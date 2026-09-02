<?php

namespace ESolution\DataSources\Tests\Unit\Services;

use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;
use ESolution\DataSources\Models\ImportTable;
use ESolution\DataSources\Runtime\RuntimeVariableDefinition;
use ESolution\DataSources\Services\Import\ImportRecordProcessor;
use ESolution\DataSources\Services\Import\ImportTemplateReader;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use PHPUnit\Framework\TestCase;

class ImportRecordProcessorStagingPayloadTest extends TestCase
{
    public function test_it_resolves_a_uuid_parent_reference_before_staging_the_child_payload(): void
    {
        $container = new Container();
        $container->instance('validator', new ValidationFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);

        $parent = new ImportTable([
            'master_name' => 'orders',
            'worksheet' => 'Orders',
            'primary_key' => 'id',
            'parent_match_column' => 'order_no',
            'data_params' => [
                'id' => ['source' => '{{ uuid.random }}', 'source_type' => 'runtime_variable'],
                'order_no' => ['source' => 'order_no', 'source_type' => 'worksheet_header'],
            ],
        ]);
        $child = new ImportTable([
            'worksheet' => 'Items',
            'foreign_key' => 'online_order_id',
            'child_match_column' => 'order_no',
            'data_params' => [
                'online_order_id' => ['source' => '{{ parent.id }}', 'source_type' => 'runtime_variable'],
                'product_code' => ['source' => 'product_code', 'source_type' => 'worksheet_header'],
            ],
        ]);
        $parent->setRelation('children', collect([$child]));

        $processor = new ImportRecordProcessor(
            new ImportTemplateReader(),
            new DynamicVariableParser(new class implements RuntimeVariableRegistryInterface {
                public function all(): array { return []; }
                public function has(string $key): bool { return $key === 'uuid.random'; }
                public function get(string $key): ?RuntimeVariableDefinition { return null; }
                public function resolve(string $key): mixed { return '1f7f80f3-22f0-46b3-abd7-2e6fb1aa3176'; }
            })
        );
        $query = $this->createMock(Builder::class);
        $query->method('where')->willReturnSelf();
        $query->method('first')->willReturn(null);
        $query->expects($this->never())->method('insert');
        $query->expects($this->never())->method('insertGetId');
        $query->expects($this->never())->method('update');
        $query->expects($this->never())->method('delete');
        $connection = $this->getMockBuilder(ConnectionInterface::class)
            ->addMethods(['getTablePrefix'])
            ->getMock();
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('table')->willReturn($query);

        $method = new \ReflectionMethod(ImportRecordProcessor::class, 'prepareStagingDataset');
        $dataset = $method->invoke($processor, [
            'masters' => [[
                'name' => 'orders',
                'worksheet' => 'Orders',
                'parent' => [['row' => 2, 'data' => ['order_no' => 'ORD001']]],
                'children' => [[['row' => 2, 'data' => ['order_no' => 'ORD001', 'product_code' => 'YGP.0001']]]],
                'child_worksheets' => ['Items'],
            ]],
        ], [$parent], $connection, 'tenant');

        $parentPayload = $dataset['masters'][0]['parent'][0]['mapped_payload'];
        $childPayload = $dataset['masters'][0]['children'][0][0]['mapped_payload'];

        $this->assertSame($parentPayload['id'], $childPayload['online_order_id']);
        $this->assertSame('1f7f80f3-22f0-46b3-abd7-2e6fb1aa3176', $childPayload['online_order_id']);
        $this->assertNotSame('{{ parent.id }}', $childPayload['online_order_id']);
    }
}
