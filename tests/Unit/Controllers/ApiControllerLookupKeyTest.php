<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\ApiController;
use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Models\ApiTable;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerLookupKeyTest extends TestCase
{
    public function test_it_uses_custom_lookup_key_when_available_on_parent_table(): void
    {
        $controller = $this->makeController();
        $apiConfig = new ApiConfig();
        $apiConfig->setRelation('parentTable', new ApiTable([
            'primary_key' => 'id',
            'key_update_delete' => 'uuid',
        ]));

        $this->assertSame('uuid', $controller->exposeResolveParentLookupKey($apiConfig));
    }

    public function test_it_falls_back_to_primary_key_when_lookup_key_is_missing(): void
    {
        $controller = $this->makeController();
        $apiConfig = new ApiConfig();
        $apiConfig->setRelation('parentTable', new ApiTable([
            'primary_key' => 'id',
            'key_update_delete' => null,
        ]));

        $this->assertSame('id', $controller->exposeResolveParentLookupKey($apiConfig));
    }

    private function makeController(): TestableLookupKeyApiController
    {
        return new TestableLookupKeyApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            new Pipeline(),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(AfterHitApiDispatcher::class)
        );
    }
}

class TestableLookupKeyApiController extends ApiController
{
    public function exposeResolveParentLookupKey(ApiConfig $apiConfig): string
    {
        return $this->resolveParentLookupKey($apiConfig);
    }
}
