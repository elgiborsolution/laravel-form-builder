# FAQ

## Table of Contents

- [Is the frontend included in the package?](#is-the-frontend-included-in-the-package)
- [Can I use custom SQL?](#can-i-use-custom-sql)
- [Does the package support middleware?](#does-the-package-support-middleware)
- [How are endpoints generated?](#how-are-endpoints-generated)
- [Can I import and export configs?](#can-i-import-and-export-configs)
- [Does it support tenancy?](#does-it-support-tenancy)
- [What is database_scope?](#what-is-database_scope)

## Is the frontend included in the package?

The public frontend demo is available separately at:

https://esbuilder.web.app

This documentation focuses on the backend package and builder behavior.

## Can I use custom SQL?

Yes. Data sources can use a custom `SELECT` query when `use_custom_query` is enabled.

## Does the package support middleware?

Yes. API configs can store middleware strings such as `auth:sanctum` or `throttle:60,1`.

## How are endpoints generated?

The `endpoint` field is normalized and routed dynamically under the package API prefix, for example:

```http
GET /api/customers
```

For API Builder, the current management resource is `/api/api-config`.

## Can I import and export configs?

Yes. Data sources, API builder configs, and form builder configs all support JSON import and export.

## Does it support tenancy?

Yes. The service provider can initialize tenant routes and the controllers read `X-Tenant` where applicable.

`X-Tenant` is also used to determine Package Builder scope:

- present and not empty -> `tenant`
- missing or empty -> `central`

## What is database_scope?

`database_scope` is the Package Builder visibility flag for management records.

Supported values:

- `central`
- `tenant`

The backend sets the value automatically when you create, update, or import Data Source and API Builder records.
Runtime execution also checks that the request scope matches the stored configuration scope before it runs an API.
