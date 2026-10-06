<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataSourceController;
use ESolution\DataSources\Database\Drivers\MySqlDatabaseDriver;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Services\CustomQueryService;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DataSourceConnectionScope;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Support\DatabaseDriverResolver;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real controller persistence, PDO validation and runtime SQL on isolated databases. */
class DataSourceScopeTest extends TestCase
{
    private Container $app;
    private ScopeFixtureTenancy $tenancy;
    private ScopeFixtureConnection $scope;
    private DataSourceController $controller;
    private ?Container $previousContainer;
    private mixed $previousFacadeApplication;
    private mixed $previousConnectionResolver;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousConnectionResolver = \Illuminate\Database\Eloquent\Model::getConnectionResolver();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'datasources' => ['database_connection' => 'central'],
            'database' => ['default' => 'central'],
        ]));
        $capsule = new Manager($this->app);
        foreach (['central', 'tenant_a', 'tenant_b'] as $connection) {
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], $connection);
        }
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->app->instance('db', $capsule->getDatabaseManager());
        $this->app->instance('log', new NullLogger());
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class {
            public function json(mixed $data, int $status = 200): \Illuminate\Http\JsonResponse
            {
                return new \Illuminate\Http\JsonResponse($data, $status);
            }
        });
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($capsule->getDatabaseManager()));
        $this->app->instance('validator', $validator);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        DB::setDefaultConnection('central');
        Request::macro('validate', function (array $rules): array {
            return app('validator')->make($this->all(), $rules)->validate();
        });

        $schema = DB::connection('central')->getSchemaBuilder();
        $schema->create('data_sources', function (Blueprint $table): void {
            $table->id();
            foreach (['name', 'table_name', 'database_scope'] as $column) $table->string($column);
            foreach (['columns', 'custom_query', 'middlewares', 'custom_parameters', 'response_type'] as $column) $table->text($column)->nullable();
            $table->boolean('use_custom_query');
            $table->boolean('use_soft_delete')->default(false);
            $table->timestamps();
        });
        $schema->create('data_source_parameters', function (Blueprint $table): void {
            $table->id();
            $table->integer('data_source_id');
            $table->string('param_name');
            $table->string('param_type');
            $table->string('param_default_value')->nullable();
            $table->string('operator')->default('=');
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });
        $schema->create('api_hooks', function (Blueprint $table): void {
            $table->id();
            $table->integer('data_source_id');
            $table->string('action_type');
            $table->string('listener_class');
            $table->timestamps();
        });
        foreach (['central', 'tenant_a', 'tenant_b'] as $connection) {
            DB::connection($connection)->getSchemaBuilder()->create('items', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
            DB::connection($connection)->table('items')->insert(['name' => $connection]);
            DB::connection($connection)->getSchemaBuilder()->create($connection . '_only', function (Blueprint $table): void {
                $table->id();
            });
        }

        $this->tenancy = new ScopeFixtureTenancy();
        $this->scope = new ScopeFixtureConnection($this->tenancy);
        $parser = $this->createMock(DynamicVariableParser::class);
        $parser->method('parse')->willReturnCallback(fn ($value) => $value);
        $driver = $this->createMock(DatabaseDriverResolver::class);
        // SQLite accepts MySQL identifier quotes, so production query execution runs unchanged.
        $driver->method('resolve')->willReturn(new MySqlDatabaseDriver());
        $metadata = new ScopeFixtureMetadata();
        $this->controller = new DataSourceController(
            new DataQueryService($parser, null, $driver, $metadata),
            new CustomQueryService($parser), new Pipeline($this->app), $metadata, $this->scope
        );
    }

    protected function tearDown(): void
    {
        Request::flushMacros();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        if ($this->previousConnectionResolver) {
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($this->previousConnectionResolver);
        } else {
            \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
        }
    }

    private function request(array $payload = [], string $tenant = '', string $method = 'GET'): Request
    {
        $request = Request::create('/api/data-source', $method, $payload, [], [], $tenant === '' ? [] : ['HTTP_X_TENANT' => $tenant]);
        $request->setUserResolver(fn () => (object) ['id' => 1]);
        return $request;
    }

    private function create(string $scope, string $loginTenant = '', string $selectedTenant = ''): DataSource
    {
        $response = $this->controller->store($this->request([
            'name' => 'items-' . $scope . '-' . DataSource::count(), 'use_custom_query' => false,
            'database_scope' => $scope, 'tenant_id' => $selectedTenant,
            'table_name' => 'items', 'columns' => ['id', 'name'],
        ], $loginTenant, 'POST'));
        $this->assertSame(201, $response->getStatusCode());
        return DataSource::findOrFail($response->getData()->id);
    }

    public function test_both_login_contexts_list_both_scopes_and_search_and_paginate(): void
    {
        $central = $this->create('central');
        $tenant = $this->create('tenant', '', 'a');
        foreach (['', 'a'] as $loginTenant) {
            $response = $this->controller->index($this->request([], $loginTenant));
            $this->assertSame(['central', 'tenant'], array_column($response->getData(true)['data'], 'database_scope'));
            $response = $this->controller->index($this->request(['search' => $tenant->name, 'page' => 1], $loginTenant));
            $this->assertSame([$tenant->id], $response->getCollection()->pluck('id')->all());
            $this->assertSame($central->database_scope, $this->controller->show($central->id)->getData()->database_scope);
        }
    }

    public function test_create_and_edit_use_explicit_scope_in_both_login_contexts(): void
    {
        foreach (['', 'a'] as $loginTenant) {
            foreach (['central', 'tenant'] as $scope) {
                $source = $this->create($scope, $loginTenant, $scope === 'tenant' ? 'a' : '');
                $payload = $source->toArray();
                unset($payload['database_scope']); // Legacy edit payload retains persisted scope.
                $payload['tenant_id'] = 'a';
                $response = $this->controller->update($this->request($payload, $loginTenant, 'PUT'), $source->id);
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame($scope, $source->fresh()->database_scope);
            }
        }
        $this->assertSame(4, DataSource::count());
        $this->assertFalse(DB::connection('tenant_a')->getSchemaBuilder()->hasTable('data_sources'));
    }

    public function test_metadata_uses_central_even_when_middleware_already_resolved_tenant(): void
    {
        $this->tenancy->initialize('a');
        $request = $this->request(['database_scope' => 'central'], 'a');
        $request->attributes->set('datasources.connection_resolved', true);
        $request->attributes->set('datasources.connection_name', 'tenant_a');
        $tables = $this->controller->listTables($request)->getData(true)['data'];
        $this->assertContains('central_only', $tables);
        $this->assertNotContains('tenant_a_only', $tables);
        $this->assertSame('tenant_a', $request->attributes->get('datasources.connection_name'));
        $this->assertSame('a', $this->tenancy->tenant->getTenantKey());
    }

    public function test_definition_models_and_listing_fall_back_to_central_metadata_connection_under_tenancy(): void
    {
        $this->app['config']->set('datasources.database_connection', '');
        $this->app['config']->set('tenancy.database.central_connection', 'central');
        $this->tenancy->initialize('a');

        try {
            $this->assertSame('tenant_a', DB::getDefaultConnection());
            $this->assertSame('central', DatabaseConnection::configuredName());

            $source = $this->create('central', 'a');
            $this->assertSame('central', $source->getConnection()->getName());
            $response = $this->controller->index($this->request([], 'a'));

            $this->assertSame([$source->id], array_column($response->getData(true)['data'], 'id'));
            $this->assertSame('tenant_a', DB::getDefaultConnection());
            $this->assertSame('a', $this->tenancy->tenant->getTenantKey());
        } finally {
            $this->tenancy->end();
        }
    }

    public function test_selected_and_current_tenants_load_their_own_tables_and_columns(): void
    {
        foreach ([['', 'a', 'tenant_a'], ['a', '', 'tenant_a'], ['', 'b', 'tenant_b']] as [$login, $selected, $connection]) {
            $request = $this->request(['database_scope' => 'tenant', 'tenant_id' => $selected], $login);
            $tables = $this->controller->listTables($request)->getData(true)['data'];
            $this->assertContains($connection . '_only', $tables);
            $this->assertNotContains('central_only', $tables);
            $columns = $this->controller->listColumns($request, 'items')->getData(true)['data'];
            $this->assertSame(['id', 'name'], array_column($columns, 'name'));
        }
    }

    public function test_missing_invalid_and_conflicting_tenants_never_fall_back(): void
    {
        foreach ([['', ''], ['', 'missing'], ['a', 'b']] as [$login, $selected]) {
            try {
                $this->controller->listTables($this->request(['database_scope' => 'tenant', 'tenant_id' => $selected], $login));
                $this->fail('Tenant resolution must fail closed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tenant_id', $exception->errors());
                $this->assertSame('central', DB::getDefaultConnection());
            }
        }
    }

    public function test_table_validation_uses_selected_database(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller->store($this->request([
            'name' => 'wrong-table', 'database_scope' => 'tenant', 'tenant_id' => 'a',
            'use_custom_query' => false, 'table_name' => 'central_only', 'columns' => ['id'],
        ], '', 'POST'));
    }

    public function test_custom_query_validation_uses_selected_database(): void
    {
        foreach (['central', 'tenant'] as $scope) {
            $request = $this->request(['database_scope' => $scope, 'tenant_id' => 'a', 'query' => 'select * from central_only']);
            $response = $this->controller->validateQuery($request);
            $this->assertSame($scope === 'central', $response->getData()->valid);
        }
    }

    public function test_custom_query_create_and_edit_preserve_scope_in_both_logins(): void
    {
        foreach (['', 'a'] as $login) {
            foreach (['central', 'tenant'] as $scope) {
                $payload = [
                    'name' => 'query-' . DataSource::count(), 'use_custom_query' => true,
                    'custom_query' => 'select id, name from items', 'database_scope' => $scope,
                    'tenant_id' => 'a', 'columns' => [],
                ];
                $response = $this->controller->store($this->request($payload, $login, 'POST'));
                $this->assertSame(201, $response->getStatusCode());
                $source = DataSource::findOrFail($response->getData()->id);
                $this->assertSame(['id', 'name'], $source->columns);
                $response = $this->controller->update($this->request($payload, $login, 'PUT'), $source->id);
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame($scope, $source->fresh()->database_scope);
            }
        }
    }

    public function test_columns_cannot_inspect_a_table_from_another_scope(): void
    {
        $response = $this->controller->listColumns($this->request([
            'database_scope' => 'tenant', 'tenant_id' => 'a',
        ]), 'central_only');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_unknown_columns_are_rejected_before_persistence(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller->store($this->request([
            'name' => 'wrong-column', 'database_scope' => 'central', 'use_custom_query' => false,
            'table_name' => 'items', 'columns' => ['missing_column'],
        ], '', 'POST'));
    }

    public function test_backend_capabilities_require_registered_tenancy_and_enabled_routes(): void
    {
        $scope = new DataSourceConnectionScope();
        $this->assertFalse($scope->tenancyEnabled());
        $this->app->instance('Stancl\Tenancy\Tenancy', $this->tenancy);
        $this->app['config']->set('datasources.routes.tenant.enabled', false);
        $this->assertFalse($scope->tenancyEnabled());
    }

    public function test_unauthenticated_selection_cannot_access_a_tenant_database(): void
    {
        $request = $this->request(['database_scope' => 'tenant', 'tenant_id' => 'a']);
        $request->setUserResolver(fn () => null);
        try {
            $this->controller->listTables($request);
            $this->fail('Authentication is required.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
            $this->assertFalse($this->tenancy->initialized);
        }
    }

    public function test_host_tenant_access_gate_blocks_metadata_validation_and_creation_before_initialization(): void
    {
        $gate = new \Illuminate\Auth\Access\Gate($this->app, fn () => null);
        $gate->define('datasources.select-tenant', fn ($user, $tenant) => $tenant->getTenantKey() === 'a');
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        $allowed = $this->request(['database_scope' => 'tenant', 'tenant_id' => 'a']);
        $this->assertSame(200, $this->controller->listTables($allowed)->getStatusCode());
        foreach (['tables', 'columns', 'validate', 'create'] as $operation) {
            $request = $this->request([
                'database_scope' => 'tenant', 'tenant_id' => 'b', 'query' => 'select * from items',
                'name' => 'denied-' . $operation, 'use_custom_query' => false, 'table_name' => 'items', 'columns' => ['id'],
            ], '', $operation === 'create' ? 'POST' : 'GET');
            try {
                match ($operation) {
                    'tables' => $this->controller->listTables($request),
                    'columns' => $this->controller->listColumns($request, 'items'),
                    'validate' => $this->controller->validateQuery($request),
                    'create' => $this->controller->store($request),
                };
                $this->fail('The denied tenant must never be accessed.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertFalse($this->tenancy->initialized);
                $this->assertSame('central', DB::getDefaultConnection());
                $this->assertSame(0, DataSource::count());
            }
        }
    }

    public function test_central_selection_can_be_reused_and_switched_without_tenant_context_leaks(): void
    {
        foreach (['a', 'a', 'b', 'b'] as $selected) {
            $this->create('tenant', '', $selected);
            $tables = $this->controller->listTables($this->request(['database_scope' => 'tenant', 'tenant_id' => $selected]))->getData(true)['data'];
            $this->assertContains('tenant_' . $selected . '_only', $tables);
            $this->assertNotContains('central_only', $tables);
            $this->assertFalse($this->tenancy->initialized);
            $this->assertSame('central', DB::getDefaultConnection());
        }
    }

    public function test_runtime_queries_read_persisted_scope_and_keep_tenant_isolation(): void
    {
        $central = $this->create('central');
        $tenant = $this->create('tenant', '', 'a');
        $centralResponse = $this->controller->executeQuery($this->request(['database_scope' => 'tenant']), $central->name);
        $this->assertSame('central', $centralResponse->getData()->data[0]->name);
        foreach (['a', 'b'] as $id) {
            $response = $this->controller->executeQuery($this->request(['database_scope' => 'central'], $id), $tenant->name);
            $this->assertSame('tenant_' . $id, $response->getData()->data[0]->name);
        }
        $this->assertSame(403, $this->controller->executeQuery($this->request([], 'a'), $central->name)->getStatusCode());
        $this->assertSame(403, $this->controller->executeQuery($this->request(), $tenant->name)->getStatusCode());
    }

    public function test_non_tenancy_supports_central_and_rejects_tenant(): void
    {
        $this->scope->enabled = false;
        $this->assertFalse($this->scope->tenancyEnabled());
        $this->create('central');
        $this->assertContains('central_only', $this->controller->listTables($this->request(['database_scope' => 'central']))->getData(true)['data']);
        $this->expectException(ValidationException::class);
        $this->create('tenant', '', 'a');
    }
}

class ScopeFixtureConnection extends DataSourceConnectionScope
{
    public bool $enabled = true;
    public function __construct(private ScopeFixtureTenancy $fixture) {}
    public function tenancyEnabled(): bool { return $this->enabled; }
    protected function tenancy(): mixed { return $this->fixture; }
}

class ScopeFixtureTenancy
{
    public bool $initialized = false;
    public ?object $tenant = null;
    public function find(string $id): ?object
    {
        if (! in_array($id, ['a', 'b'], true)) return null;
        return new class($id) {
            public function __construct(private string $id) {}
            public function getTenantKey(): string { return $this->id; }
        };
    }
    public function initialize(string $id): void
    {
        if (! in_array($id, ['a', 'b'], true)) throw new \RuntimeException('Unknown tenant');
        $this->tenant = new class($id) {
            public function __construct(private string $id) {}
            public function getTenantKey(): string { return $this->id; }
        };
        $this->initialized = true;
        DB::setDefaultConnection('tenant_' . $id);
    }
    public function end(): void
    {
        $this->initialized = false;
        $this->tenant = null;
        DB::setDefaultConnection('central');
    }
}

class ScopeFixtureMetadata extends DatabaseMetadataProvider
{
    public function listTables(?string $connectionName = null): array
    {
        return array_map(fn ($table) => $table['name'], DB::connection($connectionName)->getSchemaBuilder()->getTables());
    }
    public function listColumns(string $table, ?string $connectionName = null): array
    {
        return array_map(fn ($name) => ['name' => $name, 'type' => 'string'], DB::connection($connectionName)->getSchemaBuilder()->getColumnListing($table));
    }
    public function listIndexes(string $table, ?string $connectionName = null): array { return []; }
}
