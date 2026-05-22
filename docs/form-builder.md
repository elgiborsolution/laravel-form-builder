# Form Builder

## Table of Contents

- [Overview](#overview)
- [What It Solves](#what-it-solves)
- [Field Types](#field-types)
- [Datasource Integration](#datasource-integration)
- [Validation](#validation)
- [Conditional Fields](#conditional-fields)
- [Async Select Options](#async-select-options)
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

