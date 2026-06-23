<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerNestedArrayMappingTest extends TestCase
{
    public function test_it_resolves_nested_child_table_fields_from_the_current_array_item(): void
    {
        $controller = $this->makeController();

        $request = Request::create('/api/customer-level', 'POST', [
            'configurations' => [
                [
                    'type_id' => 'UUID-1',
                    'item_category_id' => 'UUID-2',
                    'component_group_id' => 'UUID-3',
                    'brand_id' => 'UUID-4',
                    'discount_percent' => 1,
                    'description' => 'lorem ipsum',
                ],
                [
                    'type_id' => 'UUID-5',
                    'item_category_id' => 'UUID-6',
                    'component_group_id' => 'UUID-7',
                    'brand_id' => 'UUID-8',
                    'discount_percent' => 2,
                    'description' => 'dolor sit amet',
                ],
            ],
        ]);

        $rows = $controller->exposeBuildMappedTableRows([
            'type_id' => ['value' => 'configurations.type_id'],
            'item_category_id' => ['value' => 'configurations.item_category_id'],
            'component_group_id' => ['value' => 'configurations.component_group_id'],
            'brand_id' => ['value' => 'configurations.brand_id'],
            'discount_percent' => ['value' => 'configurations.discount_percent'],
            'description' => ['value' => 'configurations.description'],
        ], $request, []);

        $this->assertCount(2, $rows);
        $this->assertSame('UUID-1', $rows[0]['type_id']);
        $this->assertSame('UUID-2', $rows[0]['item_category_id']);
        $this->assertSame('UUID-3', $rows[0]['component_group_id']);
        $this->assertSame('UUID-4', $rows[0]['brand_id']);
        $this->assertSame(1, $rows[0]['discount_percent']);
        $this->assertSame('lorem ipsum', $rows[0]['description']);

        $this->assertSame('UUID-5', $rows[1]['type_id']);
        $this->assertSame('UUID-6', $rows[1]['item_category_id']);
        $this->assertSame('UUID-7', $rows[1]['component_group_id']);
        $this->assertSame('UUID-8', $rows[1]['brand_id']);
        $this->assertSame(2, $rows[1]['discount_percent']);
        $this->assertSame('dolor sit amet', $rows[1]['description']);
    }

    public function test_it_keeps_primitive_loop_insert_arrays_working(): void
    {
        $controller = $this->makeController();

        $request = Request::create('/api/customer-level', 'POST', [
            'tags' => ['alpha', 'beta'],
        ]);

        $rows = $controller->exposeBuildMappedTableRows([
            'tag' => [
                'value' => 'tags',
                'array_handling' => 'LOOP_INSERT',
            ],
        ], $request, []);

        $this->assertCount(2, $rows);
        $this->assertSame('alpha', $rows[0]['tag']);
        $this->assertSame('beta', $rows[1]['tag']);
    }

    private function makeController(): TestableNestedArrayApiController
    {
        return new TestableNestedArrayApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(AfterHitApiDispatcher::class)
        );
    }
}

class TestableNestedArrayApiController extends \ESolution\DataSources\Controllers\ApiController
{
    public function exposeBuildMappedTableRows(array $dataParams, Request $request, array $flattenedParams, mixed $context = null): array
    {
        return $this->buildMappedTableRows($dataParams, $request, $flattenedParams, $context);
    }

    public function exposeResolveDataParamValue(mixed $value, Request $request, mixed $context = null): mixed
    {
        return $this->resolveDataParamValue($value, $request, $context);
    }
}
