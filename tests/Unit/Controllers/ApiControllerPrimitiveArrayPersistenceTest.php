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
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerPrimitiveArrayPersistenceTest extends TestCase
{
    public function test_it_normalizes_primitive_arrays_for_persistence(): void
    {
        $controller = $this->makeController();

        $result = $controller->exposeResolveMappedDataParamValue(
            'detail',
            Request::create('/api/cartss', 'POST', ['detail' => ['example', '2047']]),
            [
                'detail' => [
                    'type' => 'array string',
                    'required' => true,
                ],
            ]
        );

        $this->assertSame('["example","2047"]', $result);
    }

    public function test_it_keeps_array_object_values_as_arrays(): void
    {
        $controller = $this->makeController();

        $result = $controller->exposeResolveMappedDataParamValue(
            'orders',
            Request::create('/api/cartss', 'POST', ['orders' => [['id' => 1], ['id' => 2]]]),
            [
                'orders' => [
                    'type' => 'array object',
                    'required' => true,
                ],
            ]
        );

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(['id' => 1], $result[0]);
    }

    private function makeController(): TestablePrimitiveArrayApiController
    {
        return new TestablePrimitiveArrayApiController(
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

class TestablePrimitiveArrayApiController extends \ESolution\DataSources\Controllers\ApiController
{
    public function exposeResolveMappedDataParamValue(mixed $value, Request $request, array $flattenedParams): mixed
    {
        return $this->resolveMappedDataParamValue($value, $request, $flattenedParams);
    }
}
