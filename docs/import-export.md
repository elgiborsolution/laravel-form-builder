# Import / Export

## Table of Contents

- [Overview](#overview)
- [Data Source Export](#data-source-export)
- [Data API Export](#data-api-export)
- [Import Format](#import-format)
- [Database Scope Behavior](#database-scope-behavior)
- [Best Practices](#best-practices)

## Overview

The package supports JSON-based import and export for data sources and API configs.
This makes it easy to move builder definitions between environments.

## Data Source Export

Export endpoint:

```http
POST /api/data-source/export
```

Request example:

```json
{
  "ids": [1, 2, 3]
}
```

If `ids` is empty, the package exports all records.

The exported rows include the stored Data Source columns, including `database_scope`.

Example exported row:

```json
{
  "name": "Users List",
  "table_name": "users",
  "use_custom_query": false,
  "columns": ["id", "name", "email"],
  "custom_query": null,
  "database_scope": "central"
}
```

## Data API Export

Export endpoint:

```http
POST /api/data-api-builder/export
```

Related helper endpoint:

```http
POST /api/data-api-builder/bundle-crud
```

Example export request:

```json
{
  "ids": [10, 11]
}
```

The export payload contains the API configuration tree, including parent tables, child tables, permissions, hooks, and before-execute hooks.

## Import Format

Import endpoints:

```http
POST /api/data-source/import
POST /api/data-api-builder/import
```

Supported import style:

- JSON array of rows
- JSON object containing `rows`
- uploaded JSON file for supported import handlers
- legacy API config aliases such as `name`, `base_url`, `headers`, and `rules`

### Data Source import

Data Source import expects each row to describe a data source definition.

The backend ignores any incoming `database_scope` value and sets it from the current request scope before save.

### API Builder import

API Builder import accepts rows that match the saved configuration shape.

The backend ignores any incoming `database_scope` value and sets it from the current request scope before save.

## Database Scope Behavior

The request scope is determined by `X-Tenant`:

- `X-Tenant` present and not empty -> `tenant`
- otherwise -> `central`

This rule is used when:

- listing Data Source and API Builder records
- creating configurations
- updating configurations
- importing configurations
- validating runtime access before execution

## Best Practices

- Keep one builder per logical business use case.
- Export related configs together so they can be restored consistently.
- Validate JSON before importing into production.
- Keep `X-Tenant` handling consistent between management requests and runtime requests.
- Treat `database_scope` as backend-owned data, not frontend input.
