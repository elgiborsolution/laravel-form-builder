<?php

namespace ESolution\DataSources\Support\Concerns;

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Http\Request;

trait AppliesSearchFilter
{
    /**
     * Resolve the Package Builder scope from the request header.
     *
     * @param Request $request
     * @return string
     */
    protected function resolveDatabaseScope(Request $request): string
    {
        return $request->headers->has('X-Tenant') ? 'tenant' : 'central';
    }

    /**
     * Apply a case-insensitive search filter when the search term is present.
     *
     * @param mixed $query
     * @param Request $request
     * @param array<int, string> $columns
     * @param string|null $table
     * @return mixed
     */
    protected function applySearchFilter(mixed $query, Request $request, array $columns, ?string $table = null): mixed
    {
        $search = trim((string) $request->query('search', ''));

        if ($search === '') {
            return $query;
        }

        $searchableColumns = $table !== null
            ? $this->filterExistingColumns($table, $columns)
            : $columns;

        if ($searchableColumns === []) {
            return $query;
        }

        return $query->where(function ($subQuery) use ($search, $searchableColumns): void {
            foreach ($searchableColumns as $index => $column) {
                if ($index === 0) {
                    $subQuery->where($column, 'like', '%' . $search . '%');
                    continue;
                }

                $subQuery->orWhere($column, 'like', '%' . $search . '%');
            }
        });
    }

    /**
     * Apply the Package Builder database scope filter when the scope column exists.
     *
     * @param mixed $query
     * @param Request $request
     * @param string|null $table
     * @return mixed
     */
    protected function applyDatabaseScopeFilter(mixed $query, Request $request, ?string $table = null): mixed
    {
        $scopeColumns = $table !== null
            ? $this->filterExistingColumns($table, ['database_scope'])
            : ['database_scope'];

        if ($scopeColumns === []) {
            return $query;
        }

        return $query->where($scopeColumns[0], $this->resolveDatabaseScope($request));
    }

    /**
     * Filter already-loaded rows using the same search term logic.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param Request $request
     * @param array<int, string> $columns
     * @param string|null $table
     * @return array<int, array<string, mixed>>
     */
    protected function filterSearchRows(array $rows, Request $request, array $columns, ?string $table = null): array
    {
        $search = trim((string) $request->query('search', ''));

        if ($search === '') {
            return $rows;
        }

        $searchableColumns = $table !== null
            ? $this->filterExistingColumns($table, $columns)
            : $columns;

        if ($searchableColumns === []) {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return array_values(array_filter($rows, function (array $row) use ($needle, $searchableColumns): bool {
            foreach ($searchableColumns as $column) {
                $value = $row[$column] ?? null;

                if (is_array($value) || is_object($value) || $value === null) {
                    continue;
                }

                if (str_contains(mb_strtolower((string) $value), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Filter already-loaded rows by the Package Builder database scope.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param Request $request
     * @param string|null $table
     * @return array<int, array<string, mixed>>
     */
    protected function filterDatabaseScopeRows(array $rows, Request $request, ?string $table = null): array
    {
        $scopeColumns = $table !== null
            ? $this->filterExistingColumns($table, ['database_scope'])
            : ['database_scope'];

        if ($scopeColumns === []) {
            return $rows;
        }

        $scope = $this->resolveDatabaseScope($request);

        return array_values(array_filter($rows, static function (array $row) use ($scope, $scopeColumns): bool {
            $value = $row[$scopeColumns[0]] ?? null;

            return is_string($value) && $value === $scope;
        }));
    }

    /**
     * Keep only columns that exist in the current table.
     *
     * @param string $table
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    protected function filterExistingColumns(string $table, array $columns): array
    {
        $schema = DatabaseConnection::schema();

        return array_values(array_filter($columns, static fn (string $column): bool => $schema->hasColumn($table, $column)));
    }
}
