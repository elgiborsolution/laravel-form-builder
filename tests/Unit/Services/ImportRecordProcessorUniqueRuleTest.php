<?php

namespace ESolution\DataSources\Tests\Unit\Services;

use ESolution\DataSources\Models\ImportTable;
use ESolution\DataSources\Services\Import\ImportRecordProcessor;
use Illuminate\Validation\Rules\Unique;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ImportRecordProcessorUniqueRuleTest extends TestCase
{
    public function test_it_qualifies_the_resolved_connection_and_only_ignores_a_real_existing_primary_key(): void
    {
        $processor = (new ReflectionClass(ImportRecordProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ImportRecordProcessor::class, 'buildValidationRules');
        $table = new ImportTable([
            'primary_key' => 'id',
            'data_params' => [
                'online_order_no' => ['column' => 'online_order_no', 'unique' => true],
            ],
        ]);

        $insertRules = $method->invoke($processor, 'online_orders', $table, ['online_order_no' => '10340121'], 'tenant');
        $updateRules = $method->invoke($processor, 'online_orders', $table, ['online_order_no' => '10340121'], 'tenant', 'id', 'existing-id');

        $this->assertInstanceOf(Unique::class, $insertRules['online_order_no'][1]);
        $this->assertStringContainsString('unique:tenant.online_orders,online_order_no', (string) $insertRules['online_order_no'][1]);
        $this->assertStringNotContainsString('existing-id', (string) $insertRules['online_order_no'][1]);
        $this->assertStringContainsString('existing-id', (string) $updateRules['online_order_no'][1]);
    }
}
