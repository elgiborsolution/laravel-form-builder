# Data API Builder

## Table of Contents

- [Overview](#overview)
- [Management Endpoints](#management-endpoints)
- [Runtime API](#runtime-api)
- [Request Shape](#request-shape)
- [Headers and Middleware](#headers-and-middleware)
- [Parameters](#parameters)
- [Request and Response Examples](#request-and-response-examples)
- [Import and Export](#import-and-export)
- [Screenshot Placeholder](#screenshot-placeholder)

## Overview

Data API Builder lets you define reusable dynamic endpoints that are resolved at runtime.

It is the main entry point when you want a backend-configured API without writing a new controller for each endpoint.

## Management Endpoints

```http
GET    /api/api-config
POST   /api/api-config
GET    /api/api-config/{id}
PUT    /api/api-config/{id}
DELETE /api/api-config/{id}
```

Import/export endpoints:

```http
POST /api/data-api-builder/export
POST /api/data-api-builder/import
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

Runtime URL:

```http
GET /api/customers
```

The route resolver normalizes endpoint slashes, so you do not need to worry about:

- `//customers`
- missing leading slashes
- duplicated trailing slashes

## Request Shape

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

### Parameter format

Each param can use:

- `name`
- `type`
- `required`
- `unique`
- `validation_rules`
- `params` for nested object or array fields

Supported types:

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

## Validation Rules

API Builder runtime validation now combines three sources:

1. `required` / `nullable`
2. the parameter `type`
3. `validation_rules`

Example:

```json
{
  "name": "name",
  "type": "string",
  "required": true,
  "unique": false,
  "validation_rules": "max:5"
}
```

Final runtime rule:

```php
required|string|max:5
```

### Type mapping

The `type` field is also translated into Laravel validation rules at runtime:

- `string` -> `string`
- `integer` -> `integer`
- `numeric` -> `numeric`
- `boolean` -> `boolean`
- `array` -> `array`
- `object` -> `array`
- `email` -> `email`
- `date` -> `date`
- `uuid` -> `uuid`
- `json` -> `json`
- `url` -> `url`
- `ip` -> `ip`
- `ipv4` -> `ipv4`
- `ipv6` -> `ipv6`
- `file` -> `file`
- `image` -> `image`
- `alpha` -> `alpha`
- `alpha_num` -> `alpha_num`
- `alpha_dash` -> `alpha_dash`

### Database-aware rules

Rules such as `unique` and `exists` follow the active execution connection.

- without `X-Tenant`, validation uses `config('datasources.database_connection')`
- with `X-Tenant`, validation uses the tenant execution connection

## Headers and Middleware

The package stores middleware strings in the `middlewares` array.

Examples:

```json
[
  "auth:sanctum",
  "throttle:60,1",
  "tenant"
]
```

Recommended usage:

- `auth:sanctum` for authenticated APIs
- `x-tenant` related middleware for tenant-aware apps
- custom middleware for logging, access control, or header validation

Typical request headers:

```http
Authorization: Bearer TOKEN
Content-Type: application/json
x-tenant: tenant-id
```

## Parameters

### Simple parameter

```json
{
  "name": "status",
  "type": "string",
  "required": false,
  "unique": false,
  "validation_rules": "max:50",
  "params": []
}
```

### Nested object parameter

```json
{
  "name": "customer",
  "type": "object",
  "required": true,
  "unique": false,
  "params": [
    {
      "name": "id",
      "type": "integer",
      "required": true,
      "unique": false,
      "validation_rules": "min:1"
    },
    {
      "name": "name",
      "type": "string",
      "required": true,
      "unique": false,
      "validation_rules": "max:100"
    }
  ]
}
```

### Array parameter

```json
{
  "name": "items",
  "type": "array",
  "required": true,
  "unique": false,
  "params": [
    {
      "name": "product_id",
      "type": "integer",
      "required": true,
      "unique": false,
      "validation_rules": "exists:products,id"
    },
    {
      "name": "qty",
      "type": "integer",
      "required": true,
      "unique": false,
      "validation_rules": "min:1"
    }
  ]
}
```

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

## Request and Response Examples

### GET example

```http
GET /api/customers?status=active
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

### POST example

```http
POST /api/customers
Content-Type: application/json
Authorization: Bearer TOKEN
```

```json
{
  "customer_id": 1,
  "status": "active"
}
```

Response example:

```json
{
  "status": 200,
  "message": "Data has been successfully created",
  "data": []
}
```

### PUT example

```http
PUT /api/customers/1
Content-Type: application/json
```

```json
{
  "status": "inactive"
}
```

### DELETE example

```http
DELETE /api/customers/1
```

## Import and Export

Export example:

```http
POST /api/data-api-builder/export
Content-Type: application/json
```

```json
{
  "ids": [1, 2, 3]
}
```

Import supports JSON arrays and legacy aliases such as:

- `name`
- `base_url`
- `headers`
- `rules`

Example import payload:

```json
{
  "rows": [
    {
      "route_name": "customers.index",
      "endpoint": "customers",
      "method": "GET",
      "params": [],
      "middlewares": ["auth:sanctum"]
    }
  ]
}
```

## Screenshot Placeholder

> Screenshot placeholder: Data API Builder usage modal
