<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DatabaseDriverResolver;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerRecursiveValidationTest extends TestCase
{
    public function test_it_builds_recursive_rules_for_nested_objects_and_arrays(): void
    {
        $controller = $this->makeController();

        $rules = $controller->exposeBuildValidationRulesFromParams([
            [
                'name' => 'orders',
                'type' => 'array object',
                'required' => true,
                'params' => [
                    [
                        'name' => 'items',
                        'type' => 'array object',
                        'required' => true,
                        'params' => [
                            [
                                'name' => 'tags',
                                'type' => 'array string',
                                'required' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('required|array', $rules['orders']);
        $this->assertSame('required|array', $rules['orders.*.items']);
        $this->assertSame('nullable|array', $rules['orders.*.items.*.tags']);
        $this->assertSame('required|string', $rules['orders.*.items.*.tags.*']);
    }

    private function makeController(): TestableRecursiveApiController
    {
        return new TestableRecursiveApiController(
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

class TestableRecursiveApiController extends \ESolution\DataSources\Controllers\ApiController
{
    public function exposeBuildValidationRulesFromParams(array $params): array
    {
        return $this->buildValidationRulesFromParams($params);
    }
}
