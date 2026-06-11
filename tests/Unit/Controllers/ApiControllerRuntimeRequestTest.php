<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\ApiController;
use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerRuntimeRequestTest extends TestCase
{
    public function test_it_preserves_list_structure_while_applying_nested_defaults(): void
    {
        $controller = $this->makeController();

        $request = Request::create('/', 'POST', [
            'name' => 'dwd',
            'positions' => [
                [
                    'position_code' => 'POS-001',
                    'position_name' => 'Front Left',
                    'row_number' => 1,
                    'column_name' => 'LEFT',
                ],
                [
                    'position_code' => 'POS-002',
                    'position_name' => 'Front Right',
                    'row_number' => 1,
                ],
            ],
        ]);

        $params = [
            [
                'name' => 'name',
                'type' => 'string',
            ],
            [
                'name' => 'positions',
                'type' => 'array',
                'params' => [
                    [
                        'name' => 'position_code',
                        'type' => 'string',
                        'default' => 'POS-DEFAULT',
                    ],
                    [
                        'name' => 'position_name',
                        'type' => 'string',
                        'default' => 'Unnamed',
                    ],
                    [
                        'name' => 'row_number',
                        'type' => 'integer',
                        'default' => 1,
                    ],
                    [
                        'name' => 'column_name',
                        'type' => 'string',
                        'default' => 'RIGHT',
                    ],
                ],
            ],
        ];

        $response = $controller->exposePrepareRuntimeRequest($request, $params);

        $this->assertNull($response);
        $this->assertSame([
            'name' => 'dwd',
            'positions' => [
                [
                    'position_code' => 'POS-001',
                    'position_name' => 'Front Left',
                    'row_number' => 1,
                    'column_name' => 'LEFT',
                ],
                [
                    'position_code' => 'POS-002',
                    'position_name' => 'Front Right',
                    'row_number' => 1,
                    'column_name' => 'RIGHT',
                ],
            ],
        ], $request->all());

        $this->assertTrue(array_is_list($request->input('positions')));
        $this->assertArrayNotHasKey('position_code', $request->input('positions'));
        $this->assertArrayNotHasKey('position_name', $request->input('positions'));
        $this->assertArrayNotHasKey('row_number', $request->input('positions'));
        $this->assertArrayNotHasKey('column_name', $request->input('positions'));
    }

    public function test_it_keeps_object_defaults_backward_compatible(): void
    {
        $controller = $this->makeController();

        $request = Request::create('/', 'POST', [
            'profile' => [
                'display_name' => 'Alice',
            ],
        ]);

        $params = [
            [
                'name' => 'profile',
                'type' => 'object',
                'params' => [
                    [
                        'name' => 'display_name',
                        'type' => 'string',
                    ],
                    [
                        'name' => 'timezone',
                        'type' => 'string',
                        'default' => 'UTC',
                    ],
                ],
            ],
        ];

        $response = $controller->exposePrepareRuntimeRequest($request, $params);

        $this->assertNull($response);
        $this->assertSame([
            'profile' => [
                'display_name' => 'Alice',
                'timezone' => 'UTC',
            ],
        ], $request->all());
    }

    public function test_it_invokes_after_hit_dispatch_after_a_successful_handle_request(): void
    {
        $resolver = $this->createMock(DynamicApiConfigResolver::class);
        $apiConfig = new ApiConfig();
        $apiConfig->route_name = 'customers.index';
        $apiConfig->method = 'GET';

        $resolver->expects($this->once())
            ->method('resolve')
            ->with('customers', 'GET')
            ->willReturn([
                'config' => $apiConfig,
                'id' => null,
                'endpoint' => 'customers',
            ]);

        $controller = new class(
            $resolver,
            $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(\ESolution\DataSources\Services\AfterHitApiDispatcher::class)
        ) extends TestableApiController {
            public int $afterHitDispatchCount = 0;

            protected function withDatasourceValidationConnection(\Closure $callback)
            {
                return $callback();
            }

            protected function runDynamicMiddlewarePipeline(Request $request, ApiConfig $apiConfig, \Closure $destination): JsonResponse
            {
                return $destination($request);
            }

            protected function dispatchResolvedRequest(Request $request, ApiConfig $apiConfigs, mixed $id): JsonResponse
            {
                return new JsonResponse(['message' => 'ok'], 200);
            }

            protected function dispatchAfterHitApiEvent(
                ApiConfig $apiConfig,
                Request $request,
                JsonResponse $response,
                mixed $resolvedId = null,
                array $payload = []
            ): void {
                $this->afterHitDispatchCount++;
            }
        };

        $response = $controller->handleRequest(Request::create('/api/customers', 'GET'), 'customers');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $controller->afterHitDispatchCount);
    }

    public function test_it_passes_final_result_context_to_after_hit_dispatcher(): void
    {
        $resolver = $this->createMock(DynamicApiConfigResolver::class);
        $apiConfig = new ApiConfig();
        $apiConfig->route_name = 'customers.store';
        $apiConfig->method = 'POST';

        $resolver->expects($this->once())
            ->method('resolve')
            ->with('customers', 'POST')
            ->willReturn([
                'config' => $apiConfig,
                'id' => null,
                'endpoint' => 'customers',
            ]);

        $afterHitDispatcher = $this->createMock(AfterHitApiDispatcher::class);
        $afterHitDispatcher->expects($this->once())
            ->method('dispatchIfSuccessful')
            ->with(
                $this->identicalTo($apiConfig),
                $this->isInstanceOf(Request::class),
                $this->isInstanceOf(JsonResponse::class),
                null,
                ['name' => 'Brand A'],
                ['id' => 123, 'name' => 'Brand A'],
                'create',
                []
            );

        $controller = new class(
            $resolver,
            $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $afterHitDispatcher
        ) extends TestableApiController {
            protected function withDatasourceValidationConnection(\Closure $callback)
            {
                return $callback();
            }

            protected function runDynamicMiddlewarePipeline(Request $request, ApiConfig $apiConfig, \Closure $destination): JsonResponse
            {
                return $destination($request);
            }

            protected function dispatchResolvedRequest(Request $request, ApiConfig $apiConfigs, mixed $id): JsonResponse
            {
                $request->attributes->set('datasources.after_hit.action', 'create');
                $request->attributes->set('datasources.after_hit.result', [
                    'id' => 123,
                    'name' => 'Brand A',
                ]);
                $request->attributes->set('datasources.after_hit.before_data', []);

                return new JsonResponse(['message' => 'ok'], 200);
            }
        };

        $response = $controller->handleRequest(
            Request::create('/api/customers', 'POST', ['name' => 'Brand A']),
            'customers'
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    private function makeController(): TestableApiController
    {
        return new TestableApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(\ESolution\DataSources\Services\AfterHitApiDispatcher::class)
        );
    }
}

class TestableApiController extends ApiController
{
    public function exposePrepareRuntimeRequest(Request $request, array $params): ?JsonResponse
    {
        return $this->prepareRuntimeRequest($request, $params);
    }
}
