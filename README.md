# Laravel Form Builder

Laravel Form Builder is a backend package for building dynamic data sources, reusable API configurations, and form-driven CRUD flows without writing a new controller for every use case. It is designed for teams that want a configurable, JSON-friendly builder layer on top of Laravel.

## Live Demo

https://esbuilder.web.app

## GitHub Repository

https://github.com/elgiborsolution/laravel-form-builder.git

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Screenshots](#screenshots)
- [Documentation](#documentation)
- [Architecture](#architecture)
- [Support](#support)

## Features

- Dynamic form builder
- Data source builder
- API builder
- Import/export configuration
- Middleware support
- Dynamic validation
- Custom query support
- Reusable API configuration
- JSON-based configuration
- Dynamic select datasource
- Form API integration

## Installation

### 1. Require the package

```bash
composer require elgibor-solution/laravel-form-builder
```

### 2. Publish configuration

```bash
php artisan vendor:publish --provider="ESolution\DataSources\Providers\DataSourcesServiceProvider" --tag=datasources-config
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

The package will fall back to `DB_CONNECTION` and then `mysql` when this variable is not set. You can also read the resolved value from config:

```php
config('laravel-form-builder.database_connection')
```

## Quick Start

1. Create a data source for your table or custom SQL.
2. Create an API config if you need a public dynamic endpoint.
3. Use the form builder to define fields and bind select options.
4. Call the generated endpoint from your frontend or server-to-server integration.

Example:

```http
GET /api/data-source/users-list/query?page=1&per_page=10
```

Response example:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "name": "Alice",
      "email": "alice@example.com"
    }
  ],
  "total": 1
}
```

## Screenshots

> Screenshot placeholder: Data Source Builder
>
> Screenshot placeholder: Data API Builder
>
> Screenshot placeholder: Form Builder

## Documentation

- [Installation](docs/installation.md)
- [Getting Started](docs/getting-started.md)
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
- `Support` classes resolve dynamic routes and normalize endpoint lookups.
- `Providers` register routes, migrations, and configuration.

High-level flow:

1. A developer creates a builder configuration.
2. The configuration is persisted in Laravel models and database tables.
3. The runtime route resolver finds the matching API config.
4. The query service validates input, builds SQL, and returns the response.

## Support

If you are documenting the package for your team, start with:

1. [Getting Started](docs/getting-started.md)
2. [Data API Builder](docs/data-api-builder.md)
3. [Form Builder](docs/form-builder.md)
