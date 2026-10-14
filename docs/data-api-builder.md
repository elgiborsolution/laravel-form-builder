# Data API Builder

## Table of Contents

- [Overview](#overview)
- [Database Scope](#database-scope)
- [Management Endpoints](#management-endpoints)
- [Runtime API](#runtime-api)
- [Configuration Fields](#configuration-fields)
- [Parent and Child Tables](#parent-and-child-tables)
- [Validation Rules](#validation-rules)
- [Runtime Lifecycle](#runtime-lifecycle)
- [Import and Export](#import-and-export)
- [Examples](#examples)

## Overview

Data API Builder lets you define reusable dynamic endpoints that are resolved at runtime.

It is the main entry point when you want a backend-configured API without writing a new controller for each endpoint.

In the current package routes, the API Builder management resource is exposed as `/api/api-config`.
The helper endpoints are exposed under `/api/data-api-builder` for import/export/defaults/bundle CRUD operations.

## Database Scope

Every API Builder record has a `database_scope` field:

- `central`
- `tenant`

The backend determines the value automatically from the request context:

- `X-Tenant` present and not empty -> `tenant`
- otherwise -> `central`

The list endpoint only returns configs that match the current scope, create/update/import force the correct scope, and runtime execution rejects requests when the request scope does not match the stored scope.

## Management Endpoints

```http
GET    /api/api-config
POST   /api/api-config
GET    /api/api-config/{id}
PUT    /api/api-config/{id}
DELETE /api/api-config/{id}
```

Helper endpoints:

```http
POST /api/data-api-builder/export
POST /api/data-api-builder/import
POST /api/data-api-builder/bundle-crud
GET  /api/data-api-builder/defaults
```

## Runtime API

After an API config is saved, the package resolves requests dynamically using the configured endpoint.

Example saved config:

```json
{
  "route_name": "customers.index",
  "endpoint": "customers",
  "method": "GET"
}
```

For update and delete configs, the parent table can also define a custom lookup key:

```json
{
  "parent_table": {
    "table_name": "customers",
    "primary_key": "id",
    "key_update_delete": "code"
  }
}
```

That produces runtime usage like:

```http
PUT /api/customers/{code}
DELETE /api/customers/{code}
```

Runtime URL:

```http
GET /api/customers
```

The route resolver normalizes endpoint slashes, so you do not need to worry about:

- duplicated leading slashes
- missing leading slashes
- duplicated trailing slashes

Runtime execution validates the stored `database_scope` before any validation, runtime variable parsing, hooks, listeners, or CRUD work begins.

## Configuration Fields

The builder stores these fields:

- `route_name`
- `endpoint`
- `method`
- `params`
- `enabled`
- `description`
- `middlewares`
- `parent_table`
- `child_tables`
- `permission`
- `hook`
- `before_execute_hook`
- `database_scope`

Inside `parent_table`, `key_update_delete` controls the route parameter used for PUT, PATCH, and DELETE.
When it is missing, the package falls back to `primary_key`.

POST APIs default to the `CREATE` parent operation. They may opt into `CREATE_OR_UPDATE` with one or more lookup keys. Every lookup key pairs a parent-table column with a declared scalar request parameter; all values are required, and all configured keys must match the same parent. A match updates that parent, while no match creates one. Ambiguous matches are rejected. Child rows are synchronized using their configured update key within the resulting parent, in the same transaction.

## Parent and Child Tables

The parent table defines the main CRUD target.

Important fields:

- `table_name`
- `primary_key`
- `key_update_delete`
- `operation` (`CREATE` or `CREATE_OR_UPDATE`, POST only)
- `lookup_keys` (`column` and `parameter` pairs, for `CREATE_OR_UPDATE`)
- `foreign_key`
- `data_params`
- `use_soft_delete`

Each child table can also define:

- `table_name`
- `primary_key`
- `child_update_key`
- `foreign_key`
- `data_params`
- `missing_child_strategy`
- `use_soft_delete`

`child_update_key` is the lookup column used when child rows are updated.
If it is empty, the runtime falls back to the child primary key, then the table primary key, then `id`.

During POST `CREATE_OR_UPDATE`, a child is matched by its `foreign_key` and `child_update_key` together. Matching children retain their primary keys, even when request defaults generate new UUIDs; unmatched children are inserted using their mapped IDs. For example, `foreign_key: agama_id` and `child_update_key: code` match by `agama_id + code`, never by an incoming `id`. `DELETE_MISSING` removes children whose update keys were not received, only within the current parent, after incoming children are processed. `KEEP_EXISTING` leaves omitted children in place.

## Validation Rules

API Builder runtime validation combines:

1. `required` / `nullable`
2. the parameter `type`
3. `validation_rules`
4. `unique` and `exists` rules when enabled

Supported parameter types include:

- `string`
- `object`
- `array`
- `integer`
- `date`
- `boolean`
- `numeric`
- `url`
- `email`
- `uuid`
- `json`
- `ip`
- `ipv4`
- `ipv6`
- `file`
- `image`
- `alpha`
- `alpha_num`
- `alpha_dash`

### Multi-dimensional parameters

The `params` field supports nested structures recursively.

Example:

```json
{
  "name": "customer",
  "type": "object",
  "required": true,
  "params": [
    {
      "name": "id",
      "type": "integer",
      "required": true
    },
    {
      "name": "items",
      "type": "array",
      "required": true,
      "params": [
        {
          "name": "product_id",
          "type": "integer",
          "required": true
        },
        {
          "name": "qty",
          "type": "integer",
          "required": true
        }
      ]
    }
  ]
}
```

### Parameter defaults

Parameter settings include an optional **Default Value**, with **Manual** entry or
a **Runtime Variable** selected from the shared registry. Manual values may also
contain runtime expressions, including mixed strings such as `INV-{{ auth.id }}`.

```json
{
  "name": "owner_id",
  "type": "integer",
  "required": false,
  "default": "{{ auth.id }}",
  "default_mode": "runtime_variable"
}
```

Defaults apply only when a nullable parameter is missing or explicitly null.
Supplied values, including `0`, `false`, empty strings, and empty arrays, are kept.
Required parameters still require an input. Missing/null defaults mean no fallback.
Runtime variables are validated against the registry when saving and resolved only
when needed during execution, using the current request context.

Defaults work recursively inside supplied objects/array rows and configured parent
defaults. Child defaults alone do not create missing parents or new array rows.
Values and the optional `default_mode` (`manual` or `runtime_variable`) are stored
within `params` and preserved by import/export. Legacy defaults without a mode
continue to work.

### Unique validation

`unique` rules are built against the active execution connection.

- without `X-Tenant`, the validation query uses the central execution connection
- with `X-Tenant`, the validation query uses the tenant execution connection

The runtime also supports route lookup columns for update and delete requests.

### File upload support

`file` and `image` parameter types are validated as Laravel file uploads.
They can be passed through the normal request lifecycle and consumed by the handler like any other uploaded file.

## Runtime Lifecycle

The generated API follows this order:

1. Resolve the route and load the API configuration.
2. Validate `database_scope` against the current request scope.
3. Run the dynamic middleware pipeline.
4. Parse runtime variables and apply runtime defaults.
5. Validate the incoming payload.
6. Run `before_execute` hooks.
7. Execute the generated CRUD or query flow.
8. Dispatch the after-hit listener for successful responses.

### Before execute hooks

Before-execute hooks run immediately before the API action and must implement `BeforeExecuteHookInterface`.

### After execute listeners

After-hit listeners are dispatched only for successful responses.

### LOOP_INSERT and array handling

Array mappings can use `array_handling`.

- `RAW_VALUE` keeps the array as-is
- `LOOP_INSERT` expands the array into one row per item

This is used for parent and child table mappings when the request contains array-like values that should generate multiple inserts.

### Route parameters

Generated endpoints can use route parameters in the URL lookup key, for example:

```json
{
  "route_name": "customers.show",
  "endpoint": "customers/{code}",
  "method": "GET"
}
```

For update and delete requests, `key_update_delete` decides which parent-table column is used to look up the record. If it is empty, the package falls back to `primary_key`.

### JSON field auto decoding

The runtime decodes JSON request payloads and JSON-like fields during import and execution so nested objects and arrays stay usable without manual decoding.

## Import and Export

Import/export endpoints:

```http
POST /api/data-api-builder/export
POST /api/data-api-builder/import
POST /api/data-api-builder/bundle-crud
```

Import accepts JSON payloads or uploaded JSON files, including legacy aliases such as `name`, `base_url`, `headers`, and `rules`.

The import path ignores any incoming `database_scope` value and derives the final scope from the request context.

Export returns the saved configuration tree, including parent and child mappings, permissions, hooks, and listeners.

## Examples

### GET example

```http
GET /api/customers?status=active
```

```json
{
  "status": 200,
  "data": [
    {
      "id": 1,
      "name": "Alice"
    }
  ]
}
```

### Parent and child insert example

```json
{
  "route_name": "orders.store",
  "endpoint": "orders",
  "method": "POST",
  "enabled": true,
  "parent_table": {
    "table_name": "orders",
    "primary_key": "id",
    "data_params": {
      "customer_id": "{{ auth.id }}",
      "status": "pending"
    }
  },
  "child_tables": [
    {
      "table_name": "order_items",
      "foreign_key": "order_id",
      "child_update_key": "id",
      "data_params": {
        "product_id": 1,
        "qty": 2,
        "tags": {
          "array_handling": "LOOP_INSERT",
          "value": ["new", "gift"]
        }
      }
    }
  ]
}
```

### POST create-or-update example

```json
{
  "route_name": "customers.upsert",
  "endpoint": "customers",
  "method": "POST",
  "enabled": true,
  "params": [
    { "name": "email", "type": "string", "required": true, "validation_rules": "email" },
    { "name": "tenant_code", "type": "string", "required": true },
    { "name": "name", "type": "string", "required": true }
  ],
  "parent_table": {
    "table_name": "customers",
    "primary_key": "id",
    "operation": "CREATE_OR_UPDATE",
    "lookup_keys": [
      { "column": "email", "parameter": "email" },
      { "column": "tenant_code", "parameter": "tenant_code" }
    ],
    "data_params": {
      "name": "name"
    }
  },
  "child_tables": []
}
```

Operation and lookup settings are stored in the existing parent `data_params` JSON column and are returned separately from the column mappings. Existing configurations without this metadata keep the default `CREATE` behavior.

### Validation example

```json
{
  "name": "email",
  "type": "string",
  "required": true,
  "unique": true,
  "validation_rules": "email|max:255"
}
```

Final runtime validation:

```php
required|string|unique:tenant_or_package_table,email|email|max:255
```
