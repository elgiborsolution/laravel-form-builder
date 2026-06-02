<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\ApiController;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
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

    private function makeController(): TestableApiController
    {
        return new TestableApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class)
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
