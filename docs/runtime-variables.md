# Runtime Variables

## Table of Contents

- [Overview](#overview)
- [Syntax](#syntax)
- [Resolution Rules](#resolution-rules)
- [Where They Work](#where-they-work)
- [Examples](#examples)

## Overview

Runtime variables let the package resolve values at request time instead of save time.

They are parsed through the package runtime variable registry and can be used in:

- Data Source table names
- Data Source custom queries
- Data Source parameter defaults
- API Builder payload fields
- API Builder parent and child mappings
- Form Builder schemas

## Syntax

Use double curly braces:

```text
{{ auth.company_id }}
{{ app.env }}
{{ uuid.random }}
```

Variables can also appear inside a larger string:

```text
INV-{{ auth.company_id }}-{{ auth.id }}
```

## Resolution Rules

- A value that is only a single runtime variable keeps its native type when possible.
- Mixed strings are returned as strings.
- Arrays and objects are parsed recursively.
- Unknown variables raise a validation/runtime error.

The actual variable names are defined by your runtime variable registry. The package ships with a registry resolver, but the available keys depend on the application bindings.

## Where They Work

Runtime variables are evaluated by the runtime layer before the query or CRUD operation runs.

Common usage patterns:

- Data Source `table_name`
- Data Source `custom_query`
- Data Source default parameter values
- API Builder request defaults and mapped values
- Child table mappings for generated CRUD APIs

## Examples

### Data Source table name

```json
{
  "name": "Invoices",
  "table_name": "invoices_{{ auth.company_id }}",
  "use_custom_query": false,
  "columns": ["id", "number", "total"]
}
```

### Custom query

```json
{
  "name": "Active Orders",
  "use_custom_query": true,
  "columns": ["id", "order_no", "status"],
  "custom_query": "select id, order_no, status from orders where company_id = {{ auth.company_id }}"
}
```

### Default parameter value

```json
{
  "param_name": "company_id",
  "param_type": "integer",
  "param_default_value": "{{ auth.company_id }}",
  "is_required": 1
}
```

### API Builder mapping

```json
{
  "parent_table": {
    "table_name": "invoices",
    "data_params": {
      "company_id": "{{ auth.company_id }}"
    }
  }
}
```
