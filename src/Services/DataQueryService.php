<?php

namespace ESolution\DataSources\Services;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class DataQueryService
{
    protected const ALLOWED_FILTER_OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE'];

    public function executeForDataSource(Request $request, DataSource $dataSource, string $cacheKeyPrefix): JsonResponse
    {
        $definition = [
            'identifier' => $cacheKeyPrefix,
            'parameters' => $dataSource->parameters->map(function ($param): array {
                return [
                    'name' => $param->param_name,
                    'type' => $param->param_type,
                    'required' => (bool) $param->is_required,
                    'default' => $param->param_default_value,
                    'operator' => $param->operator ?? '=',
                ];
            })->all(),
            'use_custom_query' => (bool) $dataSource->use_custom_query,
            'custom_query' => $dataSource->custom_query,
            'table_name' => $dataSource->table_name,
            'columns' => $this->normalizeColumns($dataSource->columns),
            'debug_index_table' => $dataSource->table_name,
        ];

        return $this->execute($request, $definition);
    }

    public function executeForApiConfig(Request $request, ApiConfig $apiConfig, string $cacheKeyPrefix): JsonResponse
    {
        $parentTable = $apiConfig->parentTable;

        $definition = [
            'identifier' => $cacheKeyPrefix,
            'parameters' => $this->normalizeApiConfigParameters($apiConfig->params ?? []),
            'use_custom_query' => false,
            'custom_query' => null,
            'table_name' => $parentTable?->table_name ?? '',
            'columns' => $this->columnsFromApiConfig($apiConfig),
            'debug_index_table' => $parentTable?->table_name ?? '',
        ];

        return $this->execute($request, $definition);
    }

    public function execute(Request $request, array $definition): JsonResponse
    {
        if (!empty($request->params) && is_string($request->params)) {
            $params = json_decode($request->params, true);
            $request->merge(['params' => $params]);
        }

        $validator = Validator::make($request->all(), ['params' => 'nullable|array']);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors(), 'message' => $validator->errors()->first()], 422);
        }

        $invalid = $this->validateDetail($request);
        if ($invalid !== null) {
            return $invalid;
        }

        if (!empty($definition['custom_query'])) {
            try {
                $definition['custom_query'] = $this->ensureStringQuery($definition['custom_query']);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
            }
        }

        if (!empty($definition['table_name'])) {
            try {
                $definition['table_name'] = $this->ensureStringQuery($definition['table_name']);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
            }
        }

        if (!empty($definition['columns']) && is_array($definition['columns'])) {
            try {
                foreach ($definition['columns'] as $key => $column) {
                    $definition['columns'][$key] = $this->ensureStringQuery($column);
                }
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
            }
        }

        if (empty($definition['table_name']) && empty($definition['custom_query'])) {
            return response()->json(['error' => 'Data source not found', 'message' => 'Data source not found'], 422);
        }

        [$queryCount, $query] = $this->buildBaseQueries($definition);
        $appliedFilters = [];

        foreach ($definition['parameters'] as $parameter) {
            $field = (string) ($parameter['name'] ?? '');
            if ($field === '') {
                continue;
            }

            $operator = $this->normalizeFilterOperator((string) ($parameter['operator'] ?? '='));
            $rawValue = $this->resolveFilterValue($request, $field, $parameter['default']);
            if ($rawValue === null || $rawValue === '') {
                if (($parameter['required'] ?? false) === true) {
                    return response()->json([
                        'error' => "Parameter '$field' is required",
                        'message' => "Parameter '$field' is required",
                    ], 422);
                }

                continue;
            }

            $formattedValue = $this->findFormatValue(
                $parameter['type'],
                $rawValue,
                false
            );

            $filterClause = $this->buildFilterClause($field, $operator, $formattedValue);
            $query .= $filterClause;
            $queryCount .= $filterClause;
            $appliedFilters[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $formattedValue,
            ];
        }

        $cacheKey = DatabaseConnection::cachePrefix($definition['identifier'] . '_query_' . md5(
            $query . '|' . $queryCount . '|' . json_encode($appliedFilters) . '|' . ($request->page ?? '0') . '|' . ($request->per_page ?? '0')
        ));

        if (!empty($request->isDebug) && $request->isDebug) {
            $result = $this->makeQuery($queryCount, $query, $request, $definition);
        } else {
            $result = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($queryCount, $query, $request, $definition) {
                return $this->makeQuery($queryCount, $query, $request, $definition);
            });
        }

        if (!empty($result['error'])) {
            return response()->json(['error' => $result['error'], 'message' => $result['error']], 400);
        }

        return response()->json($result);
    }

    protected function buildBaseQueries(array $definition): array
    {
        if (!empty($definition['use_custom_query']) && !empty($definition['custom_query'])) {
            $customQuery = $definition['custom_query'];

            return [
                "SELECT count(*) as aggregate FROM ({$customQuery}) tableCustom WHERE 1=1",
                "SELECT * FROM ({$customQuery}) tableCustom WHERE 1=1",
            ];
        }

        $columns = implode(',', $definition['columns']);

        return [
            "SELECT count(*) as aggregate FROM {$definition['table_name']} WHERE 1=1",
            "SELECT {$columns} FROM {$definition['table_name']} WHERE 1=1",
        ];
    }

    protected function makeQuery(mixed $queryCount, mixed $query, Request $request, array $definition): array|LengthAwarePaginator|Collection
    {
        try {
            $query = $this->ensureStringQuery($query);
            if ($queryCount !== null) {
                $queryCount = $this->ensureStringQuery($queryCount);
            }
            if (!empty($request->page)) {
                if (empty($queryCount)) {
                    $count = count(DatabaseConnection::connection()->select($query));
                } else {
                    $dataCount = DatabaseConnection::connection()->select($queryCount);
                    $count = $dataCount[0]->aggregate;
                }

                $perPage = $request->per_page ?? 10;
                $page = $request->page == "" ? "1" : $request->page;
                $start = ($page - 1) * $perPage;
                $query .= ' LIMIT ' . $start . ', ' . $perPage;

                $data = DatabaseConnection::connection()->select($query);
                $dataResult = $this->paginate($data, $count, $perPage, $request->page);
            } else {
                $data = DatabaseConnection::connection()->select($query);
                $dataResult = ['data' => $data];
            }

            if (!empty($request->isDebug) && $request->isDebug) {
                $explainQuery = $this->ensureStringQuery('explain ' . $query);
                $dataExplain = DatabaseConnection::connection()->select($explainQuery);

                if (!empty($definition['use_custom_query'])) {
                    $custom = collect(['data_index' => [], 'data_explain' => $dataExplain, 'query_sql' => $query]);
                    $dataResult = $custom->merge($dataResult);
                } else {
                    $indexQuery = $this->ensureStringQuery('show index from ' . $definition['debug_index_table']);
                    $dataIndex = DatabaseConnection::connection()->select($indexQuery);
                    $custom = collect(['data_index' => $dataIndex, 'data_explain' => $dataExplain, 'query_sql' => $query]);
                    $dataResult = $custom->merge($dataResult);
                }
            }

            return $dataResult;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function paginate($items, int $total, int $perPage = 5, int $page = 1, array $options = []): LengthAwarePaginator
    {
        $items = $items instanceof Collection ? $items : Collection::make($items);

        return new LengthAwarePaginator($items, $total, $perPage, $page, $options);
    }

    protected function validateDetail(Request $request): ?JsonResponse
    {
        foreach ($this->incomingFilters($request) as $key => $value) {
            $normalizedFilter = [
                'field' => $value['field'] ?? $value['param_name'] ?? $value['name'] ?? null,
                'operator' => $value['operator'] ?? $value['param_operation'] ?? null,
                'value' => $value['value'] ?? $value['param_value'] ?? null,
            ];

            $validator = Validator::make($normalizedFilter, [
                'field' => 'required',
                'operator' => 'required',
                'value' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors(),
                    'message' => 'Invalid payload params at row ' . strval(intval($key) + 1),
                ], 400);
            }

            $operator = strtoupper((string) $normalizedFilter['operator']);
            if (! in_array($operator, self::ALLOWED_FILTER_OPERATORS, true)) {
                return response()->json([
                    'error' => "Invalid filter operator: {$operator}",
                    'message' => "Invalid filter operator: {$operator}",
                ], 400);
            }
        }

        return null;
    }

    /**
     * Resolve a filter payload for a given field from supported request shapes.
     *
     * @param Request $request
     * @param string $field
     * @return array<string, mixed>|null
     */
    protected function findFilterDefinition(Request $request, string $field): ?array
    {
        foreach ($this->incomingFilters($request) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $filterField = (string) ($filter['field'] ?? $filter['param_name'] ?? $filter['name'] ?? '');

            if ($filterField !== $field) {
                continue;
            }

            return $filter;
        }

        return null;
    }

    /**
     * Resolve the runtime value for a datasource field from query string or legacy filter payloads.
     *
     * @param Request $request
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    protected function resolveFilterValue(Request $request, string $field, mixed $default = null): mixed
    {
        $value = $request->query($field);

        if ($value === null) {
            $value = $request->input($field);
        }

        if ($value !== null && $value !== '') {
            return $value;
        }

        $rawFilter = $this->findFilterDefinition($request, $field);
        if (is_array($rawFilter)) {
            if (array_key_exists('value', $rawFilter) && $rawFilter['value'] !== null && $rawFilter['value'] !== '') {
                return $rawFilter['value'];
            }

            if (array_key_exists('param_value', $rawFilter) && $rawFilter['param_value'] !== null && $rawFilter['param_value'] !== '') {
                return $rawFilter['param_value'];
            }
        }

        return $default;
    }

    /**
     * Get incoming filters from either the new `filters` payload or the legacy `params` payload.
     *
     * @param Request $request
     * @return array<int, array<string, mixed>>
     */
    protected function incomingFilters(Request $request): array
    {
        $filters = $request->input('filters', $request->input('params', []));

        if (is_string($filters) && trim($filters) !== '') {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        return is_array($filters) ? $filters : [];
    }

    /**
     * Build a safe SQL filter clause using a whitelisted operator.
     *
     * @param string $field
     * @param string $operator
     * @param mixed $value
     * @return string
     */
    protected function buildFilterClause(string $field, string $operator, mixed $value): string
    {
        $column = $this->sanitizeIdentifier($field);
        $operator = $this->normalizeFilterOperator($operator);

        return match ($operator) {
            '=', '!=', '>', '<', '>=', '<=' => ' AND ' . $column . ' ' . $operator . ' ' . $this->quoteSqlValue($value),
            'LIKE' => ' AND ' . $column . ' LIKE ' . $this->quoteSqlValue('%' . (string) $value . '%'),
            'NOT LIKE' => ' AND ' . $column . ' NOT LIKE ' . $this->quoteSqlValue('%' . (string) $value . '%'),
            default => ' AND ' . $column . ' = ' . $this->quoteSqlValue($value),
        };
    }

    /**
     * Validate and normalize a filter operator.
     *
     * @param string $operator
     * @return string
     */
    protected function normalizeFilterOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));

        if (! in_array($operator, self::ALLOWED_FILTER_OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid filter operator: {$operator}");
        }

        return $operator;
    }

    /**
     * Quote a SQL literal value using the active package connection.
     *
     * @param mixed $value
     * @return string
     */
    protected function quoteSqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $quoted = DatabaseConnection::connection()->getPdo()->quote((string) $value);

        return $quoted !== false ? $quoted : "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Sanitize a SQL identifier such as a column or dotted table.column reference.
     *
     * @param string $identifier
     * @return string
     */
    protected function sanitizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new \InvalidArgumentException('Invalid filter field: empty identifier');
        }

        $segments = explode('.', $identifier);
        $quotedSegments = [];

        foreach ($segments as $segment) {
            $segment = trim($segment, " \t\n\r\0\x0B`");

            if ($segment === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                throw new \InvalidArgumentException("Invalid filter field: {$identifier}");
            }

            $quotedSegments[] = '`' . $segment . '`';
        }

        return implode('.', $quotedSegments);
    }

    protected function findFormatValue(string $type, mixed $paramValue, bool $isLike = false): mixed
    {
        switch ($type) {
            case 'integer':
                $paramValue = (int) $paramValue;
                break;
            case 'boolean':
                $paramValue = filter_var($paramValue, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'float':
            case 'numeric':
                $paramValue = (float) $paramValue;
                break;
            case 'date':
                if (!strtotime((string) $paramValue)) {
                    return $paramValue;
                }
                break;
            case 'string':
            default:
                $paramValue = (string) $paramValue;
                break;
        }

        if ($isLike) {
            $paramValue = '%' . $paramValue . '%';
        }

        return $paramValue;
    }

    protected function normalizeColumns(array|string|null $columns): array
    {
        if (is_array($columns)) {
            return $columns;
        }

        if (is_string($columns) && $columns !== '') {
            $decoded = json_decode($columns, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['*'];
    }

    protected function columnsFromApiConfig(ApiConfig $apiConfig): array
    {
        $columns = array_keys($apiConfig->parentTable?->data_params ?? []);

        return count($columns) > 0 ? $columns : ['*'];
    }

    protected function normalizeApiConfigParameters(array $params): array
    {
        $normalized = [];

        foreach ($params as $param) {
            if (empty($param['name']) || empty($param['type'])) {
                continue;
            }

            if (in_array($param['type'], ['array', 'object'], true)) {
                continue;
            }

            $normalized[] = [
                'name' => $param['name'],
                'type' => $param['type'],
                'required' => (bool) ($param['required'] ?? false),
                'default' => $param['default'] ?? null,
            ];
        }

        return $normalized;
    }

    protected function ensureStringQuery(mixed $query): string
    {
        if ($query instanceof \Illuminate\Database\Query\Expression) {
            if (method_exists($query, 'getValue')) {
                try {
                    $query = $query->getValue(DatabaseConnection::connection()->getQueryGrammar());
                } catch (\Throwable $e) {
                    try {
                        $query = $query->getValue();
                    } catch (\Throwable $e2) {
                        $query = (string) $query;
                    }
                }
            } else {
                $query = (string) $query;
            }
        }

        if (!is_string($query)) {
            throw new \InvalidArgumentException('Database query must be of type string, ' . (is_object($query) ? get_class($query) : gettype($query)) . ' given.');
        }

        return $query;
    }
}
