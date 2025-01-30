<?php
namespace ESolution\DataSources\Providers;

use Illuminate\Support\ServiceProvider;

class DataSourcesServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register model bindings, services, etc.
    }

    public function boot()
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'migrations');
    }
}
