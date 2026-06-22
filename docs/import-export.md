# Import / Export

## Table of Contents

- [Overview](#overview)
- [Data Source Export](#data-source-export)
- [Data API Export](#data-api-export)
- [Import Format](#import-format)
- [Best Practices](#best-practices)

## Overview

The package supports JSON-based import and export for data sources and API configs. This makes it easy to move builder definitions between environments.

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

Example exported row:

```json
{
  "name": "Users List",
  "table_name": "users",
  "use_custom_query": false,
  "columns": ["id", "name", "email"],
  "custom_query": null
}
```

## Data API Export

Export endpoint:

```http
POST /api/data-api-builder/export
```

Request example:

```json
{
  "ids": [10, 11]
}
```

Example exported row:

```json
{
  "route_name": "customers.index",
  "endpoint": "customers",
  "method": "GET",
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
  "enabled": true,
  "parent_table": {
    "table_name": "customers",
    "primary_key": "id",
    "foreign_key": null,
    "data_params": {
      "id": "id",
      "name": "name"
    }
  },
  "child_tables": [],
  "permission": null,
  "hook": null
}
```

## Import Format

Import endpoints:

```http
POST /api/data-source/import
POST /api/data-api-builder/import
```

Supported import style:

- JSON array of rows
- JSON object containing `rows`
- file upload payload for supported import handlers

Data API builder import also supports legacy aliases such as:

- `name`
- `base_url`
- `headers`
- `rules`

## Best Practices

- Keep one builder per logical business use case.
- Export related configs together so they can be restored consistently.
- Validate JSON before importing into production.
- Store API config changes in version control if you maintain seed files.
