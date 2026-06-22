# Examples

## Table of Contents

- [Example 1: Table-Based Data Source](#example-1-table-based-data-source)
- [Example 2: Custom Query Data Source](#example-2-custom-query-data-source)
- [Example 3: Dynamic API Endpoint](#example-3-dynamic-api-endpoint)
- [Example 4: Nested Parameters](#example-4-nested-parameters)
- [Example 5: Import / Export Workflow](#example-5-import--export-workflow)

## Example 1: Table-Based Data Source

Create a data source for a users table:

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

Query it:

```http
GET /api/users-list?status=active&page=1&per_page=10
```

## Example 2: Custom Query Data Source

```json
{
  "name": "Active Orders",
  "table_name": "",
  "use_custom_query": true,
  "columns": ["id", "order_no", "total"],
  "custom_query": "select id, order_no, total from orders where status = 'active'"
}
```

## Example 3: Dynamic API Endpoint

API builder config:

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

Runtime request:

```http
GET /api/customers?status=active
Authorization: Bearer TOKEN
```

## Example 4: Nested Parameters

```json
{
  "name": "order",
  "type": "object",
  "required": true,
  "unique": false,
  "params": [
    {
      "name": "customer_id",
      "type": "integer",
      "required": true,
      "unique": false
    },
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
  ]
}
```

## Example 5: Import / Export Workflow

1. Export builder configs from one environment.
2. Commit the JSON file to your deployment workflow or artifact store.
3. Import the JSON file into another environment.
4. Verify that routes, middleware, and table mappings match.

Example export request:

```http
POST /api/data-api-builder/export
```

Example import request:

```http
POST /api/data-api-builder/import
```
