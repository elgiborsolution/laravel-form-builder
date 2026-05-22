# Data Source

## Table of Contents

- [Overview](#overview)
- [Configuration Fields](#configuration-fields)
- [Endpoints](#endpoints)
- [Filtering and Pagination](#filtering-and-pagination)
- [Custom Query Mode](#custom-query-mode)
- [Examples](#examples)
- [Screenshot Placeholder](#screenshot-placeholder)

## Overview

Data Source is the simplest builder in the package. It stores a reusable query definition for a table or a custom SQL statement, then exposes that definition through a query endpoint.

Use it when you want:

- one place to define a list query
- reusable filtering and pagination
- async select options for the form builder
- exportable/importable configuration

## Configuration Fields

The `DataSource` model stores:

- `name`
- `table_name`
- `use_custom_query`
- `columns`
- `custom_query`

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
GET /api/data-source/{id}/query
```

## Filtering and Pagination

The query endpoint can accept request params and pagination arguments.

Example:

```http
GET /api/data-source/users-list/query?page=1&per_page=10&status=active
```

Behavior:

- `page` enables pagination
- `per_page` sets page size
- matching parameter names are read from the request
- default values are used when a parameter is not supplied

## Custom Query Mode

When `use_custom_query` is enabled:

- `custom_query` must be a `SELECT` statement
- the package rejects non-select statements
- `columns` are derived from the query result structure

Example:

```json
{
  "name": "Active Customers",
  "table_name": "",
  "use_custom_query": true,
  "columns": ["id", "name", "email"],
  "custom_query": "select id, name, email from customers where status = 'active'"
}
```

## Examples

### Table-based data source

```json
{
  "name": "Product List",
  "table_name": "products",
  "use_custom_query": false,
  "columns": ["id", "sku", "name", "price"]
}
```

### Custom query data source

```json
{
  "name": "Low Stock Products",
  "use_custom_query": true,
  "columns": ["id", "name", "stock"],
  "custom_query": "select id, name, stock from products where stock < 10"
}
```

### Response example

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

## Screenshot Placeholder

> Screenshot placeholder: create and test a data source

