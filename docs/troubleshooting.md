# Troubleshooting

## Table of Contents

- [Columns Must Be Array](#columns-must-be-array)
- [Invalid JSON Import](#invalid-json-import)
- [Middleware Auth Issue](#middleware-auth-issue)
- [Endpoint Not Found](#endpoint-not-found)
- [CORS Issue](#cors-issue)
- [Custom Query Validation](#custom-query-validation)

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

## CORS Issue

**Problem:** Browser requests are blocked.

**Fix:**

- allow the frontend origin in your app CORS settings
- make sure the API prefix is included in allowed paths
- confirm preflight requests are not blocked by auth middleware

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

