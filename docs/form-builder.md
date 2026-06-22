# Form Builder

## Table of Contents

- [Overview](#overview)
- [What It Solves](#what-it-solves)
- [Field Types](#field-types)
- [Datasource Integration](#datasource-integration)
- [Validation](#validation)
- [Conditional Fields](#conditional-fields)
- [Async Select Options](#async-select-options)
- [API Import & Export](#api-import--export)
- [API Documentation](#api-documentation)
- [Example Flow](#example-flow)
- [Screenshot Placeholder](#screenshot-placeholder)

## Overview

The form builder is the configuration layer for dynamic forms. It allows developers to define fields, validation, and data bindings in a reusable format instead of hard-coding every form.

## What It Solves

- reduce duplicate form code
- standardize validation
- bind select inputs to live data sources
- support nested fields and dynamic mappings
- keep form behavior consistent across modules

## Field Types

The package supports common builder-driven field patterns such as:

- text
- number
- date
- checkbox
- dropdown
- radio
- async select
- nested object
- repeated array items

For API-style form payloads, the parameter types used by the builder include:

- `string`
- `integer`
- `date`
- `boolean`
- `numeric`
- `object`
- `array`
- `url`

## Datasource Integration

Form fields can pull options from a data source or picker configuration.

Typical integration pattern:

1. Create a data source for the lookup table.
2. Use the generated query endpoint to load options.
3. Bind the result to the select field.
4. Store the selected value as the field value.

Example lookup scenario:

- a product select field reads from `products`
- the select option label is `name`
- the select option value is `id`

## Validation

Validation rules are stored with the builder configuration and applied at runtime or by the frontend renderer.

Examples:

- required field
- unique value
- type check
- nested required child field

Example rule set:

```json
{
  "name": "email",
  "type": "string",
  "required": true,
  "unique": true
}
```

## Conditional Fields

The builder can express conditional logic by showing or validating fields based on another field value.

Examples:

- show `shipping_address` only when `delivery_type = courier`
- show child array fields only when `order_type = bulk`
- require a field only when a checkbox is checked

## Async Select Options

Async select fields are the main integration point with data sources and data pickers.

Recommended pattern:

1. Define a data source for the option list.
2. Add query filters if needed.
3. Request the list dynamically from the backend.
4. Map the response into label/value options.

Example response mapped to select options:

```json
[
  {
    "id": 1,
    "name": "Admin"
  },
  {
    "id": 2,
    "name": "Staff"
  }
]
```

Mapped options:

- label: `name`
- value: `id`

## API Import & Export

Form Builder now includes environment-friendly import and export endpoints so configurations can be moved between projects without losing the decoded schema payload.

### Export Selected

- `POST /api/form-builder/export`
- Body examples:

```json
{ "ids": [1, 2, 3] }
```

```json
{ "codes": ["FORM_CUSTOMER", "FORM_PRODUCT"] }
```

The response is a JSON download with this structure:

```json
{
  "version": 1,
  "exported_at": "2026-06-22 10:00:00",
  "items": [
    {
      "id": 1,
      "code": "FORM_CUSTOMER",
      "name": "Customer Form",
      "description": "...",
      "enabled": true,
      "schema": [
        {
          "name": "customer_name",
          "type": "string"
        }
      ]
    }
  ]
}
```

### Export All

- `GET /api/form-builder/export-all`
- Exports every form builder record in the same JSON structure.

### Import

- `POST /api/form-builder/import`
- `Content-Type: multipart/form-data`
- Fields:
  - `file` required JSON export file
  - `mode` optional, `skip` or `update`

Behavior:

- `skip` leaves existing codes unchanged.
- `update` overwrites existing records using the imported payload.
- schema is validated and restored as JSON data.

### Validation Rules

- file is required
- file must be JSON
- export structure must include `version`, `exported_at`, and `items`
- each item must include `code`, `name`, and `schema`
- schema must be valid JSON data

## API Documentation

The package also exposes:

- `GET /api/form-builder/docs`
- `GET /api/form-builder/postman`

`/docs` returns developer-friendly JSON documentation with request and response examples. `/postman` returns a Postman Collection v2.1 JSON that can be imported directly into Postman without edits.

## Example Flow

```text
Create builder config
  -> Attach data source
  -> Define fields and validation
  -> Render form in frontend
  -> Submit payload to API
```

## Screenshot Placeholder

> Screenshot placeholder: dynamic form builder screen
