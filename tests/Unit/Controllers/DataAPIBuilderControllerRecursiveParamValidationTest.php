<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use PHPUnit\Framework\TestCase;

class DataAPIBuilderControllerRecursiveParamValidationTest extends TestCase
{
    public function test_it_accepts_recursive_nested_arrays_and_primitive_arrays(): void
    {
        $controller = $this->makeController();

        $this->assertNull($controller->exposeValidateApiBuilderParamNode([
            'name' => 'orders',
            'type' => 'array object',
            'params' => [
                [
                    'name' => 'items',
                    'type' => 'array object',
                    'params' => [
                        [
                            'name' => 'tags',
                            'type' => 'array string',
                            'params' => [],
                        ],
                    ],
                ],
            ],
        ]));
    }

    public function test_it_rejects_nested_params_inside_primitive_arrays(): void
    {
        $controller = $this->makeController();

        $response = $controller->exposeValidateApiBuilderParamNode([
            'name' => 'ids',
            'type' => 'array integer',
            'params' => [
                [
                    'name' => 'child',
                    'type' => 'string',
                ],
            ],
        ]);

        $this->assertNotNull($response);
        $this->assertSame(400, $response->getStatusCode());
    }

    private function makeController(): TestableRecursiveDataAPIBuilderController
    {
        return new TestableRecursiveDataAPIBuilderController(
            $this->createMock(DynamicApiConfigResolver::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );
    }
}

class TestableRecursiveDataAPIBuilderController extends DataAPIBuilderController
{
    public function exposeValidateApiBuilderParamNode(mixed $param, array $pathSegments = []): ?\Illuminate\Http\JsonResponse
    {
        return $this->validateApiBuilderParamNode($param, $pathSegments);
    }
}
