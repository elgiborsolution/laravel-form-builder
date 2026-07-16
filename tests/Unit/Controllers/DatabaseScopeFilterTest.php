<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Controllers\DataSourceController;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\CustomQueryService;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class DatabaseScopeFilterTest extends TestCase
{
    public function test_it_resolves_central_scope_without_x_tenant_header(): void
    {
        $controller = $this->makeDataSourceController();
        $request = Request::create('/api/data-source', 'GET');

        $this->assertSame('central', $controller->exposeResolveDatabaseScope($request));
    }

    public function test_it_resolves_tenant_scope_with_x_tenant_header(): void
    {
        $controller = $this->makeDataSourceController();
        $request = Request::create('/api/data-source', 'GET', [], [], [], [
            'HTTP_X_TENANT' => 'jayasuksesrejeki',
        ]);

        $this->assertSame('tenant', $controller->exposeResolveDatabaseScope($request));
    }

    public function test_it_applies_the_resolved_scope_to_query_filters(): void
    {
        $controller = $this->makeDataSourceController();
        $query = new FakeScopeQuery();
        $request = Request::create('/api/data-source', 'GET', [], [], [], [
            'HTTP_X_TENANT' => 'jayasuksesrejeki',
        ]);

        $controller->exposeApplyDatabaseScopeFilter($query, $request);

        $this->assertSame([
            ['database_scope', 'tenant'],
        ], $query->wheres);
    }

    public function test_it_resolves_tenant_scope_for_the_api_builder_controller_too(): void
    {
        $controller = $this->makeDataApiBuilderController();
        $request = Request::create('/api/data-api-builder', 'GET', [], [], [], [
            'HTTP_X_TENANT' => 'jayasuksesrejeki',
        ]);

        $this->assertSame('tenant', $controller->exposeResolveDatabaseScope($request));
    }

    private function makeDataSourceController(): TestableDatabaseScopeDataSourceController
    {
        return new TestableDatabaseScopeDataSourceController(
            $this->createMock(DataQueryService::class),
            $this->createMock(CustomQueryService::class),
            $this->createMock(Pipeline::class),
            $this->createMock(DatabaseMetadataProvider::class)
        );
    }

    private function makeDataApiBuilderController(): TestableDatabaseScopeDataAPIBuilderController
    {
        return new TestableDatabaseScopeDataAPIBuilderController(
            $this->createMock(DynamicApiConfigResolver::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );
    }
}

class TestableDatabaseScopeDataSourceController extends DataSourceController
{
    public function exposeResolveDatabaseScope(Request $request): string
    {
        return $this->resolveDatabaseScope($request);
    }

    public function exposeApplyDatabaseScopeFilter(mixed $query, Request $request): mixed
    {
        return $this->applyDatabaseScopeFilter($query, $request);
    }
}

class TestableDatabaseScopeDataAPIBuilderController extends DataAPIBuilderController
{
    public function exposeResolveDatabaseScope(Request $request): string
    {
        return $this->resolveDatabaseScope($request);
    }
}

class FakeScopeQuery
{
    public array $wheres = [];

    public function where(...$args): self
    {
        $this->wheres[] = $args;

        return $this;
    }
}
