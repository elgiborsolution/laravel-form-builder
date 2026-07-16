# Troubleshooting

## Table of Contents

- [Columns Must Be Array](#columns-must-be-array)
- [Invalid JSON Import](#invalid-json-import)
- [Middleware Auth Issue](#middleware-auth-issue)
- [Endpoint Not Found](#endpoint-not-found)
- [Scope Mismatch](#scope-mismatch)
- [Custom Query Validation](#custom-query-validation)
- [Runtime Variable Error](#runtime-variable-error)

## Columns Must Be Array

**Problem:** The data source save request fails with a validation error.

**Fix:** Make sure `columns` is submitted as an array, not a string.

Correct:

```json
{
  "columns": ["id", "name", "email"]
}
```

Incorrect:

```json
{
  "columns": "id,name,email"
}
```

## Invalid JSON Import

**Problem:** Import returns `Invalid JSON structure` or `Invalid JSON file`.

**Fix:**

- verify the file is valid JSON
- ensure the top-level payload is an array or a JSON object with `rows`
- remove trailing commas

## Middleware Auth Issue

**Problem:** API returns `401`.

**Fix:**

- confirm the correct auth middleware is attached
- check the `Authorization` header
- verify the token is valid for the selected guard

Example middleware:

```json
[
  "auth:sanctum"
]
```

## Endpoint Not Found

**Problem:** Runtime request returns `API Builder tidak ditemukan` or `404`.

**Fix:**

- verify the stored `endpoint`
- check the route prefix in `config/datasources.php`
- make sure another route is not shadowing the dynamic path
- confirm the HTTP method matches the saved config

## Scope Mismatch

**Problem:** A central record is still present when the request includes `X-Tenant`, or a tenant record is hidden when the header is missing.

**Fix:**

- `X-Tenant` present and not empty means request scope is `tenant`
- missing or empty `X-Tenant` means request scope is `central`
- Data Source and API Builder list endpoints only return records for the current scope
- runtime execution rejects requests when the request scope and `database_scope` do not match

## Custom Query Validation

**Problem:** Custom query save fails.

**Fix:**

- only `SELECT` statements are allowed
- remove `INSERT`, `UPDATE`, `DELETE`, and `DROP`
- ensure the query can be parsed for column discovery when possible

Example valid query:

```sql
select id, name from users where status = 'active'
```

## Runtime Variable Error

**Problem:** Save or import fails with an invalid runtime variable expression.

**Fix:**

- verify the expression uses `{{ variable.name }}`
- make sure the runtime variable exists in your registry
- check nested JSON payloads for unresolved values

Example:

```json
{
  "custom_query": "select * from users where company_id = {{ auth.company_id }}"
}
```
