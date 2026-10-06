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

Create requests send `database_scope` explicitly. Updates retain the saved scope when the field is omitted. Legacy create requests use the request context as their default:

- `X-Tenant` present and not empty -> `tenant`
- otherwise -> `central`

The management list returns all accessible definitions from the shared package connection, independent of query scope. Runtime execution still rejects requests whose scope does not match the saved definition.

For central logins, Tenant API operations send `database_scope=tenant` and the selected `tenant_id` as query parameters (metadata) or request fields (validation and creation). They keep central authentication and do not change `X-Tenant`. The frontend reuses the existing session/context tenant, or requires **Use Tenant** before any tenant metadata or creation request. A validated selection is retained in browser session storage for that server and user; **Change Tenant** replaces it explicitly, and logout clears it. Tenant logins always use their authenticated `X-Tenant` context and cannot select another tenant.

The backend requires authentication for tenant database access, resolves the selected tenant through the tenancy registry, and rejects missing, unknown, conflicting, or inaccessible tenants without falling back to central. Existing management middleware continues to authorize builder operations. Hosts can restrict registered tenants with the Laravel Gate `datasources.select-tenant` (receiving the authenticated user and tenant model), or a tenant model's `view` policy; both are checked before tenant initialization. Without a per-tenant Gate/policy, authorized central managers may select any registered tenant.

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

The list endpoint includes both scopes. Helper endpoints choose the database from `database_scope` and authenticated/selected tenant context, rather than definition-list visibility.

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
