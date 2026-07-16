# Data Source

## Table of Contents

- [Overview](#overview)
- [Database Scope](#database-scope)
- [Configuration Fields](#configuration-fields)
- [Endpoints](#endpoints)
- [Filtering and Pagination](#filtering-and-pagination)
- [Runtime Behavior](#runtime-behavior)
- [Custom Query Mode](#custom-query-mode)
- [Examples](#examples)

## Overview

Data Source stores a reusable query definition for a table or a custom SQL statement, then exposes that definition through a query endpoint.

Use it when you want:

- one place to define a list query
- reusable filtering and pagination
- async select options for the form builder
- exportable and importable configuration

## Database Scope

Every Data Source record has a `database_scope` field:

- `central`
- `tenant`

The backend determines the value automatically from the current request:

- `X-Tenant` present and not empty -> `tenant`
- otherwise -> `central`

The management list endpoint only returns records for the current scope, and runtime execution rejects a Data Source when the request scope does not match the stored scope.

## Configuration Fields

The `DataSource` model stores:

- `name`
- `table_name`
- `use_custom_query`
- `columns`
- `custom_query`
- `use_soft_delete`
- `response_type`
- `custom_parameters`
- `database_scope`

The related parameter table stores:

- `param_name`
- `param_type`
- `param_default_value`
- `is_required`

Example parameter definition:

```json
{
  "param_name": "status",
  "param_type": "string",
  "param_default_value": "active",
  "is_required": 0
}
```

## Endpoints

Management endpoints:

```http
GET    /api/data-source
POST   /api/data-source
GET    /api/data-source/{id}
PUT    /api/data-source/{id}
DELETE /api/data-source/{id}
```

Helper endpoints:

```http
GET /api/data-source/tables
GET /api/data-source/tables/{table}/columns
POST /api/data-source/query/validate
POST /api/data-source/query/columns
GET /api/data-source/{id}/query
GET /api/data-source/{id}/{routePath}
```

The list endpoint is scope-aware:

- `X-Tenant` present and not empty -> only `database_scope = tenant`
- no `X-Tenant` header -> only `database_scope = central`

## Filtering and Pagination

The runtime query endpoint accepts request parameters, route parameters, ordering, and pagination arguments.

Example:

```http
GET /api/users-list?page=1&per_page=10&status=active
```

Behavior:

- matching parameter names are read from the request
- route parameters can be used inside `{placeholder}` expressions
- `order_by` and `order_direction` are applied when the column exists in the configured column list
- `page` and `per_page` use Laravel pagination in the runtime query layer
- the management list endpoint paginates only when `page` is present and currently uses a default page size of 10

The package does not use `simplePaginate`, `cursorPaginate`, or `lazy` pagination in the current implementation.

## Runtime Behavior

Runtime execution handles the following before returning the response:

- runtime variable parsing
- request parameter resolution
- route parameter replacement
- JSON result decoding for configured JSON columns
- soft delete filtering when enabled and the table has `deleted_at`
- automatic ordering when `order_by` is supplied

The result payload keeps the existing response shape:

- `data` for non-paginated responses
- Laravel paginator output when pagination is active

## Custom Query Mode

When `use_custom_query` is enabled:

- `custom_query` must be a `SELECT` statement
- the package rejects non-select statements
- `columns` are derived from the query result structure
- custom parameters in the query are resolved from the request at runtime

Example:

```json
{
  "name": "Low Stock Products",
  "use_custom_query": true,
  "columns": ["id", "name", "stock"],
  "custom_query": "select id, name, stock from products where stock < 10"
}
```

## Examples

### Table-based data source

```json
{
  "name": "Product List",
  "table_name": "products",
  "use_custom_query": false,
  "columns": ["id", "sku", "name", "price"],
  "use_soft_delete": true,
  "response_type": "array"
}
```

### Custom query data source

```json
{
  "name": "Low Stock Products",
  "use_custom_query": true,
  "columns": ["id", "name", "stock"],
  "custom_query": "select id, name, stock from products where stock < 10",
  "custom_parameters": [
    {
      "name": "company_id",
      "type": "integer",
      "required": true,
      "default": "{{ auth.company_id }}"
    }
  ]
}
```

### Route-based data source

```json
{
  "name": "customers/{customer_id}",
  "table_name": "customers",
  "use_custom_query": false,
  "columns": ["id", "name", "email"]
}
```

### Runtime response

```json
{
  "data": [
    {
      "id": 1,
      "sku": "SKU-1001",
      "name": "Coffee Beans",
      "price": 15.5
    }
  ]
}
```
