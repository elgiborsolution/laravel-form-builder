# Authentication

## Table of Contents

- [Overview](#overview)
- [Token-Based Requests](#token-based-requests)
- [Tenant Header](#tenant-header)
- [Middleware Examples](#middleware-examples)
- [Common Notes](#common-notes)

## Overview

The package does not replace Laravel authentication.
It works with your existing auth layer through standard middleware and request headers.

## Token-Based Requests

Use standard Laravel auth middleware such as:

- `auth`
- `auth:sanctum`
- `auth:api`

Example request:

```http
GET /api/customers
Authorization: Bearer TOKEN
```

## Tenant Header

If your project uses tenancy, the package can initialize tenant context from request data.

Example:

```http
X-Tenant: tenant-id
```

`X-Tenant` is also used by Package Builder to determine request scope:

- present and not empty -> `tenant`
- missing or empty -> `central`

## Middleware Examples

Use API builder `middlewares` to attach auth and access-control behavior.

```json
[
  "auth:sanctum",
  "throttle:60,1"
]
```

Custom middleware can be used for:

- tenant resolution
- role checks
- request logging
- header validation

## Common Notes

- If a request returns `401`, verify your auth middleware and token.
- If a tenant-aware route cannot find data, confirm the `X-Tenant` header and tenant initialization middleware.
- If dynamic routes are blocked, check route prefix collisions and reserved paths in `config/datasources.php`.
