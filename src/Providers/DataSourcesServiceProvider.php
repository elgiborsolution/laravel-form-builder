<?php
namespace ESolution\DataSources\Providers;

use ESolution\DataSources\Console\InstallDatasourcesCommand;
use ESolution\DataSources\Controllers\ApiController;
use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Controllers\DataPickerController;
use ESolution\DataSources\Controllers\DataSourceController;
use ESolution\DataSources\Controllers\DataTableBuilderController;
use ESolution\DataSources\Controllers\FormBuilderController;
use ESolution\DataSources\Controllers\UploadBuilderController;
use ESolution\DataSources\Controllers\RuntimeVariableController;
use ESolution\DataSources\Events\AfterRunnerApiBuiderEvent;
use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;
use ESolution\DataSources\Http\Middleware\ForceDatabaseConnection;
use ESolution\DataSources\Runtime\RuntimeVariableRegistryResolver;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Support\DatabaseDriverResolver;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

class DataSourcesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/datasources.php', 'datasources');
        $this->mergeConfigFrom(__DIR__ . '/../../config/datasources.php', 'laravel-form-builder');
        $this->mergeConfigFrom(__DIR__ . '/../../config/datasources.php', 'data-sources');

        $this->app->singleton(RuntimeVariableRegistryResolver::class, RuntimeVariableRegistryResolver::class);
        $this->app->singleton(DatabaseDriverResolver::class, DatabaseDriverResolver::class);
        $this->app->singleton(DatabaseMetadataProvider::class, function ($app) {
            return new DatabaseMetadataProvider($app->make(DatabaseDriverResolver::class));
        });
        $this->app->singleton(AfterHitApiDispatcher::class, function ($app) {
            return new AfterHitApiDispatcher($app->make('events'));
        });
        $this->app->bind(RuntimeVariableRegistryInterface::class, function ($app) {
            return $app->make(RuntimeVariableRegistryResolver::class)->resolveInstance();
        });
    }

    public function boot(): void
    {
        if (! class_exists(\App\Events\AfterRunnerApiBuiderEvent::class)) {
            class_alias(AfterRunnerApiBuiderEvent::class, \App\Events\AfterRunnerApiBuiderEvent::class);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if (! $this->app->routesAreCached()) {
            $this->registerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallDatasourcesCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/../../config/datasources.php' => config_path('datasources.php'),
        ], 'datasources-config');

        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'datasources-migrations');
    }

    protected function registerRoutes(): void
    {
        $this->registerManagementRoutes();
        $this->registerTenantRoutes();
        $this->registerDynamicRoutes();
    }

    protected function registerManagementRoutes(): void
    {
        $middleware = array_values(array_filter(array_merge(
            [ForceDatabaseConnection::class],
            config('datasources.routes.management.middleware', ['api'])
        )));

        Route::middleware($middleware)
            ->prefix($this->buildPrefix())
            ->as(config('datasources.routes.name', 'datasources.'))
            ->group(function (): void {
                Route::get('data-source/tables', [DataSourceController::class, 'listTables'])
                    ->name('management.data-source.tables');
                Route::get('data-source/tables/{table}/columns', [DataSourceController::class, 'listColumns'])
                    ->name('management.data-source.columns');
                Route::post('data-source/query/validate', [DataSourceController::class, 'validateQuery'])
                    ->name('management.data-source.query.validate');
                Route::post('data-source/query/columns', [DataSourceController::class, 'extractQueryColumns'])
                    ->name('management.data-source.query.columns');
                Route::get('data-source/{id}/query', [DataSourceController::class, 'executeQuery'])
                    ->name('management.data-source.query');
                Route::get('data-source/{id}/{routePath}', [DataSourceController::class, 'executeQuery'])
                    ->where('routePath', '.+')
                    ->name('management.data-source.route');
                Route::get('data-source/{id}', [DataSourceController::class, 'executeQuery'])
                    ->name('management.data-source.api');
                Route::post('data-source/export', [DataSourceController::class, 'export'])
                    ->name('management.data-source.export');
                Route::post('data-source/import', [DataSourceController::class, 'import'])
                    ->name('management.data-source.import');
                Route::post('data-api-builder/export', [DataAPIBuilderController::class, 'export'])
                    ->name('management.data-api-builder.export');
                Route::post('data-api-builder/import', [DataAPIBuilderController::class, 'import'])
                    ->name('management.data-api-builder.import');
                Route::post('data-api-builder/bundle-crud', [DataAPIBuilderController::class, 'bundleCrud'])
                    ->name('management.data-api-builder.bundle-crud');
                Route::get('data-api-builder/defaults', [DataAPIBuilderController::class, 'defaults'])
                    ->name('management.data-api-builder.defaults');
                Route::get('upload-builder/defaults', [UploadBuilderController::class, 'defaults'])
                    ->name('management.upload-builder.defaults');
                Route::post('upload-builder/export', [UploadBuilderController::class, 'export'])
                    ->name('management.upload-builder.export');
                Route::post('upload-builder/import', [UploadBuilderController::class, 'import'])
                    ->name('management.upload-builder.import');
                Route::patch('upload-builder/{id}/status', [UploadBuilderController::class, 'updateStatus'])
                    ->whereNumber('id')
                    ->name('management.upload-builder.status');
                Route::get('runtime-variables', [RuntimeVariableController::class, 'index'])
                    ->name('management.runtime-variables.index');
                Route::prefix('form-builder')
                    ->as('management.form-builder.')
                    ->group(function (): void {
                        Route::get('/', [FormBuilderController::class, 'index'])->name('index');
                        Route::post('/', [FormBuilderController::class, 'store'])->name('store');
                        Route::post('export', [FormBuilderController::class, 'export'])
                            ->name('export');
                        Route::get('export-all', [FormBuilderController::class, 'exportAll'])
                            ->name('export-all');
                        Route::post('import', [FormBuilderController::class, 'import'])
                            ->name('import');
                        Route::get('docs', [FormBuilderController::class, 'docs'])
                            ->name('docs');
                        Route::get('postman', [FormBuilderController::class, 'postman'])
                            ->name('postman');
                        Route::get('id/{id}', [FormBuilderController::class, 'showById'])
                            ->whereNumber('id')
                            ->name('show-by-id');
                        Route::get('{code}', [FormBuilderController::class, 'showByCode'])
                            ->where('code', '[A-Za-z0-9._-]+')
                            ->name('show-by-code');
                        Route::put('{id}', [FormBuilderController::class, 'update'])
                            ->whereNumber('id')
                            ->name('update');
                        Route::patch('{id}/status', [FormBuilderController::class, 'updateStatus'])
                            ->whereNumber('id')
                            ->name('status');
                        Route::delete('{id}', [FormBuilderController::class, 'destroy'])
                            ->whereNumber('id')
                            ->name('destroy');
                    });

                Route::apiResource('data-source', DataSourceController::class)
                    ->names($this->resourceRouteNames('management.data-source'));
                Route::apiResource('data-picker', DataPickerController::class)
                    ->names($this->resourceRouteNames('management.data-picker'));
                Route::apiResource('table-builder', DataTableBuilderController::class)
                    ->names($this->resourceRouteNames('management.table-builder'));
                Route::apiResource('api-config', DataAPIBuilderController::class)
                    ->names($this->resourceRouteNames('management.api-config'));
                Route::apiResource('upload-builder', UploadBuilderController::class)
                    ->names($this->resourceRouteNames('management.upload-builder'));
            });
    }

    protected function registerTenantRoutes(): void
    {
        if (! config('datasources.routes.tenant.enabled', true)) {
            return;
        }

        $middlewareClass = config(
            'datasources.routes.tenant.initialize_middleware',
            InitializeTenancyByRequestData::class
        );

        if (! class_exists($middlewareClass)) {
            return;
        }

        $middleware = array_values(array_filter(array_merge(
            config('datasources.routes.tenant.middleware', ['api']),
            [$middlewareClass]
        )));

        Route::middleware($middleware)
            ->prefix($this->buildPrefix())
            ->as(config('datasources.routes.name', 'datasources.') . 'tenant.')
            ->group(function (): void {
                Route::get('data-source-tenant/tables', [DataSourceController::class, 'listTables'])
                    ->name('data-source-tenant.tables');
                Route::get('data-source-tenant/tables/{table}/columns', [DataSourceController::class, 'listColumns'])
                    ->name('data-source-tenant.columns');
                Route::post('data-source-tenant/query/validate', [DataSourceController::class, 'validateQuery'])
                    ->name('data-source-tenant.query.validate');
                Route::post('data-source-tenant/query/columns', [DataSourceController::class, 'extractQueryColumns'])
                    ->name('data-source-tenant.query.columns');
                Route::get('data-source-tenant/{id}/query', [DataSourceController::class, 'executeQuery'])
                    ->name('data-source-tenant.query');
                Route::get('data-source-tenant/{id}/{routePath}', [DataSourceController::class, 'executeQuery'])
                    ->where('routePath', '.+')
                    ->name('data-source-tenant.route');
                Route::get('data-source-tenant/{id}', [DataSourceController::class, 'executeQuery'])
                    ->name('data-source-tenant.api');
                Route::apiResource('data-source-tenant', DataSourceController::class)
                    ->names($this->resourceRouteNames('data-source-tenant'));
            });
    }

    protected function registerDynamicRoutes(): void
    {
        Route::prefix($this->buildPrefix(config('datasources.routes.dynamic.prefix')))
            ->as(config('datasources.routes.name', 'datasources.') . 'dynamic.')
            ->group(function (): void {
                Route::any('{dynamicPath}', [ApiController::class, 'handleRequest'])
                    ->where('dynamicPath', '.*')
                    ->name('dispatch')
                    ->fallback();
            });
    }

    protected function buildPrefix(?string $extraPrefix = null): string
    {
        $segments = [
            trim((string) config('datasources.routes.prefix', 'api'), '/'),
            trim((string) config('datasources.routes.version'), '/'),
            trim((string) $extraPrefix, '/'),
        ];

        return implode('/', array_values(array_filter($segments, static fn ($segment) => $segment !== '')));
    }

    protected function resourceRouteNames(string $prefix): array
    {
        return [
            'index' => $prefix . '.index',
            'store' => $prefix . '.store',
            'show' => $prefix . '.show',
            'update' => $prefix . '.update',
            'destroy' => $prefix . '.destroy',
        ];
    }
}
