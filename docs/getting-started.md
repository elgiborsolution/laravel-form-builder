# Getting Started

## Table of Contents

- [First Data Source](#first-data-source)
- [First API Config](#first-api-config)
- [Form Builder Flow](#form-builder-flow)
- [How the Runtime Works](#how-the-runtime-works)
- [Database Scope](#database-scope)

## First Data Source

Start by creating a simple data source for an existing table.

```http
POST /api/data-source
Content-Type: application/json
```

```json
{
  "name": "Users List",
  "table_name": "users",
  "use_custom_query": false,
  "columns": ["id", "name", "email"],
  "parameters": [
    {
      "param_name": "status",
      "param_type": "string",
      "param_default_value": "active",
      "is_required": 0
    }
  ]
}
```

If the request includes `X-Tenant`, the record is saved as `database_scope = tenant`. Without `X-Tenant`, the record is saved as `database_scope = central`.

## First API Config

Create a reusable API endpoint that will be resolved dynamically at runtime.

```http
POST /api/api-config
Content-Type: application/json
```

```json
{
  "route_name": "customers.index",
  "endpoint": "customers",
  "method": "GET",
  "enabled": true,
  "description": "Return customer list",
  "middlewares": ["auth:sanctum"],
  "params": [
    {
      "name": "status",
      "type": "string",
      "required": false,
      "unique": false,
      "params": []
    }
  ],
  "parent_table": {
    "table_name": "customers",
    "primary_key": "id",
    "data_params": {
      "id": "id",
      "name": "name"
    }
  },
  "child_tables": []
}
```

After it is saved, the runtime API is available at:

```http
GET /api/customers?status=active
```

## Form Builder Flow

The frontend builder lets you define a dynamic form without hard-coding each field. The package side stores the data model and routes that power that experience.

Typical workflow:

1. Define field metadata in the builder UI.
2. Save the configuration as JSON-backed records.
3. Bind a data source or data picker for async select options.
4. Let the frontend render the form from the stored configuration.

Common builder capabilities:

- input validation
- conditional fields
- dynamic select options
- nested object and array params
- reusable field mappings

## How the Runtime Works

High-level request flow:

1. Developer creates a builder config.
2. The config is stored in the database.
3. The package resolves the matching route or data source.
4. `DataQueryService` validates incoming params or CRUD payloads.
5. Laravel executes the query and returns JSON.

## Database Scope

Package Builder management records are scope-aware.

- `X-Tenant` present and not empty -> `tenant`
- no `X-Tenant` header -> `central`

The same rule is used when:

- listing Data Source and API Builder records
- creating or updating configurations
- importing configurations
- validating runtime access before execution

## Screenshot Placeholder

> Screenshot placeholder: onboarding overview in the builder UI
