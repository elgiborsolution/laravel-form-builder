# Laravel Form Builder

Laravel Form Builder is a backend package for building dynamic data sources, reusable API configurations, and form-driven CRUD flows on top of Laravel.

It is designed for teams that want a configurable, JSON-friendly builder layer without writing a new controller for every use case.

## Live Demo

https://esbuilder.web.app

## GitHub Repository

https://github.com/elgiborsolution/laravel-form-builder.git

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Documentation](#documentation)
- [Architecture](#architecture)
- [Support](#support)

## Features

- Data Source builder
- API Builder
- Form Builder
- Runtime variables
- Before execute hooks
- After execute listeners
- Parent and child table mapping
- Child update key support
- LOOP_INSERT array handling
- Route parameter expressions
- JSON field auto decoding
- Table-based and custom query data sources
- Filtering, pagination, and automatic ordering
- Import and export
- Validation rules and unique validation
- Soft delete support
- File upload field support
- Package Builder `database_scope` filtering and access validation

## Installation

### 1. Require the package

```bash
composer require elgibor-solution/laravel-form-builder
```

### 2. Install the runtime registry scaffold

```bash
php artisan datasources:install
```

This command creates `app/Runtime/AppRuntimeVariableRegistry.php` only when it does not already exist.
No `AppServiceProvider` changes or manual registry binding are needed.

If you also want the package config published during install, use:

```bash
php artisan datasources:install --publish-config
```

### 3. Publish migrations

```bash
php artisan vendor:publish --provider="ESolution\DataSources\Providers\DataSourcesServiceProvider" --tag=datasources-migrations
```

### 4. Run migrations

```bash
php artisan migrate
```

### 5. Verify route prefix

By default, package routes are registered under the `api` prefix. You can adjust this in `config/datasources.php` or with environment variables such as:

```env
DATASOURCES_ROUTE_PREFIX=api
DATASOURCES_ROUTE_VERSION=
```

### 6. Configure the package database connection

Set the connection the package should use for its models, queries, and migrations:

```env
LARAVEL_FORM_BUILDER_DB_CONNECTION=tenant
```

The package falls back to `DB_CONNECTION` when this variable is not set. You can also read the resolved value from config:

```php
config('datasources.database_connection')
```

## Quick Start

1. Create a data source for your table or custom SQL.
2. Create an API config if you need a public dynamic endpoint.
3. Use the form builder to define fields and bind select options.
4. Call the generated endpoint from your frontend or server-to-server integration.
5. Use `X-Tenant` to switch Package Builder scope automatically between `central` and `tenant` records.

Example runtime request:

```http
GET /api/data-source/users-list/query?page=1&per_page=10
```

## Documentation

- [Installation](docs/installation.md)
- [Getting Started](docs/getting-started.md)
- [Runtime Variables](docs/runtime-variables.md)
- [Data Source](docs/data-source.md)
- [Data API Builder](docs/data-api-builder.md)
- [Form Builder](docs/form-builder.md)
- [Import / Export](docs/import-export.md)
- [Authentication](docs/authentication.md)
- [Examples](docs/examples.md)
- [Troubleshooting](docs/troubleshooting.md)
- [FAQ](docs/faq.md)

## Architecture

The package is organized around a small set of backend layers:

- `Controllers` accept management requests for builders and runtime API requests.
- `Models` store the configuration records such as data sources, API configs, listeners, permissions, and mappings.
- `Services` turn configuration into executable queries.
- `Support` classes resolve dynamic routes, database scope, and endpoint lookups.
- `Providers` register routes, migrations, and configuration.

High-level flow:

1. A developer creates a builder configuration.
2. The configuration is persisted in Laravel models and database tables.
3. The runtime route resolver finds the matching API config or data source.
4. The query service validates input, builds SQL or CRUD payloads, and returns the response.

## Support

If you are documenting the package for your team, start with:

1. [Getting Started](docs/getting-started.md)
2. [Data API Builder](docs/data-api-builder.md)
3. [Data Source](docs/data-source.md)
4. [Runtime Variables](docs/runtime-variables.md)
