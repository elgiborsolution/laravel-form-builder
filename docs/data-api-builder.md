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
      "unique": false
    },
    {
      "name": "name",
      "type": "string",
      "required": true,
      "unique": false
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
      "unique": false
    },
    {
      "name": "qty",
      "type": "integer",
      "required": true,
      "unique": false
    }
  ]
}
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

