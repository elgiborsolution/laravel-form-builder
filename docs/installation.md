# Installation

## Table of Contents

- [Requirements](#requirements)
- [Install Package](#install-package)
- [Publish Assets](#publish-assets)
- [Run Migrations](#run-migrations)
- [Configure Routes](#configure-routes)
- [Verify Installation](#verify-installation)

## Requirements

- PHP 8.2+
- Laravel 10 or 11
- Database connection configured

## Install Package

```bash
composer require elgibor-solution/laravel-form-builder
```

The package is Laravel auto-discoverable. In most projects you do not need to register the service provider manually.

## Publish Assets

Publish the package configuration:

```bash
php artisan vendor:publish --provider="ESolution\DataSources\Providers\DataSourcesServiceProvider" --tag=datasources-config
```

Publish the package migrations:

```bash
php artisan vendor:publish --provider="ESolution\DataSources\Providers\DataSourcesServiceProvider" --tag=datasources-migrations
```

## Run Migrations

```bash
php artisan migrate
```

The migrations create the tables used by the builders, including:

- `data_sources`
- `data_source_parameters`
- `data_pickers`
- `data_table_builders`
- `api_configs`
- `api_tables`
- `api_permissions`
- `api_hooks`
- `api_listeners`

## Configure Routes

Route behavior is controlled by `config/datasources.php`.

Default route settings:

```php
'routes' => [
    'prefix' => env('DATASOURCES_ROUTE_PREFIX', 'api'),
    'version' => env('DATASOURCES_ROUTE_VERSION'),
]
```

Optional environment values:

```env
DATASOURCES_ROUTE_PREFIX=api
DATASOURCES_ROUTE_VERSION=
```

If you use tenancy, the tenant middleware is also configured here:

```php
'tenant' => [
    'enabled' => true,
    'initialize_middleware' => 'Stancl\\Tenancy\\Middleware\\InitializeTenancyByRequestData',
]
```

## Verify Installation

Check that routes are registered:

```bash
php artisan route:list | findstr data-source
php artisan route:list | findstr api-config
```

You should see management routes for data sources, data pickers, table builders, and API configs.

