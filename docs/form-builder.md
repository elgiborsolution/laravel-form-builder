# Form Builder

## Table of Contents

- [Overview](#overview)
- [What It Solves](#what-it-solves)
- [Field Types](#field-types)
- [Datasource Integration](#datasource-integration)
- [Validation](#validation)
- [Conditional Fields](#conditional-fields)
- [Runtime Variables](#runtime-variables)
- [Import and Export](#import-and-export)
- [API Documentation](#api-documentation)
- [Example Flow](#example-flow)

## Overview

The form builder is the configuration layer for dynamic forms.
It allows developers to define fields, validation, and data bindings in a reusable format instead of hard-coding every form.

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
- `email`
- `uuid`
- `json`
- `file`
- `image`

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

## Management Endpoints

```http
GET    /api/form-builder
POST   /api/form-builder
GET    /api/form-builder/id/{id}
GET    /api/form-builder/{code}
PUT    /api/form-builder/{id}
PATCH  /api/form-builder/{id}/status
DELETE /api/form-builder/{id}
```

Import/export and documentation helpers:

```http
POST /api/form-builder/export
GET  /api/form-builder/export-all
POST /api/form-builder/import
GET  /api/form-builder/docs
GET  /api/form-builder/postman
```

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

## Runtime Variables

Form Builder can use runtime variables in schema payloads and default values.

Examples:

- `{{ auth.company_id }}`
- `{{ app.env }}`
- `{{ uuid.random }}`

For details, see [Runtime Variables](runtime-variables.md).

## Import and Export

Form Builder includes JSON import and export endpoints so configurations can be moved between projects without losing the decoded schema payload.

### Export Selected

- `POST /api/form-builder/export`
- body: `ids` or `codes`

### Export All

- `GET /api/form-builder/export-all`

### Import

- `POST /api/form-builder/import`
- `Content-Type: multipart/form-data`
- fields:
  - `file` required JSON export file
  - `mode` optional, `skip` or `update`

Validation rules:

- file is required
- file must be JSON
- export structure must include `version`, `exported_at`, and `items`
- each item must include `code`, `name`, and `schema`
- schema must be valid JSON data

## API Documentation

The package also exposes:

- `GET /api/form-builder/docs`
- `GET /api/form-builder/postman`

`/docs` returns developer-friendly JSON documentation with request and response examples.
`/postman` returns a Postman Collection v2.1 JSON that can be imported directly into Postman without edits.

## Example Flow

```text
Create builder config
  -> Attach data source
  -> Define fields and validation
  -> Render form in frontend
  -> Submit payload to API
```
