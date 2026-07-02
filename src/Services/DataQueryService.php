<?php

namespace ESolution\DataSources\Services;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Support\DatabaseDriverResolver;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use ESolution\DataSources\Support\FilterOperatorResolver;
use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DataQueryService
{
    protected const ALLOWED_FILTER_OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'];
    protected const JSON_NATIVE_COLUMN_TYPES = ['json', 'jsonb'];
    protected const JSON_AUTO_DECODE_TEXT_COLUMN_TYPES = ['mediumtext', 'longtext'];
    protected const JSON_CONFIGURABLE_TEXT_COLUMN_TYPES = ['text'];
    protected bool $cacheEnabled = true;
    protected bool $forceDisableCacheForDataSource = true;
    protected ?string $executionConnectionName = null;

    public function __construct(
        protected DynamicVariableParser $runtimeVariableParser,
        protected ?ExecutionConnectionResolver $executionConnectionResolver = null,
        protected ?DatabaseDriverResolver $databaseDriverResolver = null,
        protected ?DatabaseMetadataProvider $databaseMetadataProvider = null
    ) {
        $this->executionConnectionResolver ??= new ExecutionConnectionResolver();
        $this->databaseDriverResolver ??= new DatabaseDriverResolver();
        $this->databaseMetadataProvider ??= new DatabaseMetadataProvider($this->databaseDriverResolver);
    }

    public function executeForDataSource(Request $request, DataSource $dataSource, string $cacheKeyPrefix): JsonResponse
    {
        try {
            $this->cacheEnabled = ! $this->forceDisableCacheForDataSource;

            $definition = [
                'identifier' => $cacheKeyPrefix,
                'connection_name' => $this->resolveExecutionConnectionName($request),
                'parameters' => $dataSource->parameters->map(function ($param): array {
                    return [
                        'name' => $param->param_name,
                        'type' => $param->param_type,
                        'required' => (bool) $param->is_required,
                        'default' => $this->parseRuntimeValue($param->param_default_value),
                        'operator' => $param->operator ?? FilterOperatorResolver::resolve((string) $param->param_type),
                    ];
                })->all(),
                'use_custom_query' => (bool) $dataSource->use_custom_query,
                'use_soft_delete' => (bool) $dataSource->use_soft_delete,
                'custom_query' => $this->parseRuntimeValue($dataSource->custom_query),
                'table_name' => $this->parseRuntimeValue($dataSource->table_name),
                'columns' => $this->normalizeColumns($dataSource->columns),
                'debug_index_table' => $this->parseRuntimeValue($dataSource->table_name),
                'response_type' => $this->normalizeResponseType($dataSource->response_type ?? 'array'),
                'custom_parameters' => $this->syncCustomParameters(
                    $this->normalizeCustomParameters($dataSource->custom_parameters ?? []),
                    (string) $this->parseRuntimeValue($dataSource->custom_query)
                ),
            ];

            return $this->execute($request, $definition);
        } catch (InvalidRuntimeVariableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 422);
        } finally {
            $this->cacheEnabled = true;
        }
    }

    public function executeForApiConfig(Request $request, ApiConfig $apiConfig, string $cacheKeyPrefix): JsonResponse
    {
        try {
            $parentTable = $apiConfig->parentTable;

            $definition = [
                'identifier' => $cacheKeyPrefix,
                'connection_name' => $this->resolveExecutionConnectionName($request),
                'parameters' => $this->normalizeApiConfigParameters($apiConfig->params ?? []),
                'use_custom_query' => false,
                'custom_query' => null,
                'table_name' => $parentTable?->table_name ?? '',
                'columns' => $this->columnsFromApiConfig($apiConfig),
                'debug_index_table' => $parentTable?->table_name ?? '',
                'use_soft_delete' => (bool) ($parentTable?->use_soft_delete ?? false),
            ];

            return $this->execute($request, $definition);
        } catch (InvalidRuntimeVariableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function execute(Request $request, array $definition): JsonResponse
    {
        $previousExecutionConnectionName = $this->executionConnectionName;
        $this->executionConnectionName = $this->normalizeConnectionName($definition['connection_name'] ?? $this->resolveExecutionConnectionName($request));

        try {
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
                    $definition['custom_query'] = $this->ensureStringQuery(
                        $this->applyCustomParameterPlaceholders(
                            $this->applyConditionalBlocks(
                                $this->applyRouteParameterPlaceholders(
                                    (string) $this->parseRuntimeValue($definition['custom_query']),
                                    $request
                                ),
                                $request
                            ),
                            $request,
                            is_array($definition['custom_parameters'] ?? null) ? $definition['custom_parameters'] : []
                        )
                    );
                } catch (\InvalidArgumentException $e) {
                    return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
                }
            }

            if (!empty($definition['table_name'])) {
                try {
                    $definition['table_name'] = $this->ensureStringQuery($this->parseRuntimeValue($definition['table_name']));
                } catch (\InvalidArgumentException $e) {
                    return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
                }
            }

            if (!empty($definition['columns']) && is_array($definition['columns'])) {
                try {
                    foreach ($definition['columns'] as $key => $column) {
                        $definition['columns'][$key] = $this->ensureStringQuery($this->parseRuntimeValue($column));
                    }
                } catch (\InvalidArgumentException $e) {
                    return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
                }
            }

            if (empty($definition['table_name']) && empty($definition['custom_query'])) {
                return response()->json(['error' => 'Data source not found', 'message' => 'Data source not found'], 422);
            }

            $softDeleteClause = $this->resolveSoftDeleteClause($definition);

            [$queryCount, $query] = $this->buildBaseQueries($definition);

            if ($softDeleteClause !== '') {
                $queryCount .= $softDeleteClause;
                $query .= $softDeleteClause;
            }

            Log::debug('DataSource query build', [
                'identifier' => $definition['identifier'] ?? null,
                'dataSourceCode' => $request->attributes->get('datasources.data_source_code'),
                'routePattern' => $request->attributes->get('datasources.route_pattern'),
                'detectedParameters' => $request->attributes->get('datasources.detected_parameters', []),
                'original_sql_count' => $queryCount,
                'original_sql' => $query,
                'route_params' => $request->route() ? $request->route()->parameters() : [],
                'request_params' => $request->all(),
                'request_all' => $request->all(),
                'detected_placeholders' => $this->extractCustomParameterNames($query),
                'bindings' => [],
            ]);

            $appliedFilters = [];

            foreach ($definition['parameters'] as $parameter) {
            $field = (string) ($parameter['name'] ?? '');
            if ($field === '') {
                continue;
            }

                $operator = $this->normalizeFilterOperator((string) ($parameter['operator'] ?? '='));
                $rawValue = $this->resolveFilterValue($request, $field, $parameter['default']);
                $rawValue = $this->parseRuntimeValue($rawValue);
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

            $runtimeOrdering = $this->resolveRuntimeOrdering($request, $definition);

            if ($runtimeOrdering !== null && ! $this->containsOrderByClause($query)) {
                $query .= ' ORDER BY ' . $this->quoteOrderByIdentifier($runtimeOrdering['column']) . ' ' . $runtimeOrdering['direction'];
            }

            $cacheKey = $this->cachePrefixForExecution($definition['identifier'] . '_query_' . md5(
            $query . '|' . $queryCount . '|' . json_encode($appliedFilters) . '|' . ($request->page ?? '0') . '|' . ($request->per_page ?? '0')
            ));

            if (!empty($request->isDebug) && $request->isDebug) {
                $result = $this->runQueryDirectly($queryCount, $query, $request, $definition);
            } elseif (! $this->cacheEnabled) {
                $result = $this->runQueryDirectly($queryCount, $query, $request, $definition);
            } else {
                $result = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($queryCount, $query, $request, $definition) {
                    return $this->runQueryDirectly($queryCount, $query, $request, $definition);
                });
            }

            if (!empty($result['error'])) {
                return response()->json(['error' => $result['error'], 'message' => $result['error']], 400);
            }

            $result = $this->normalizeResultJsonColumns($result, $definition);

            Log::debug('DataSource query final', [
                'identifier' => $definition['identifier'] ?? null,
                'dataSourceCode' => $request->attributes->get('datasources.data_source_code'),
                'routePattern' => $request->attributes->get('datasources.route_pattern'),
                'detectedParameters' => $request->attributes->get('datasources.detected_parameters', []),
                'final_sql_count' => $queryCount,
                'final_sql' => $query,
                'bindings' => [],
            ]);

            return response()->json($this->applyResponseType($result, (string) ($definition['response_type'] ?? 'array')));
        } catch (InvalidRuntimeVariableException $e) {
            return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        } finally {
            $this->executionConnectionName = $previousExecutionConnectionName;
        }
    }

    protected function buildBaseQueries(array $definition): array
    {
        if (!empty($definition['use_custom_query']) && !empty($definition['custom_query'])) {
            $customQuery = $this->normalizeCustomQuery((string) $definition['custom_query']);

            return [
                "SELECT count(*) as aggregate FROM ({$customQuery}) AS tableCustom WHERE 1=1",
                "SELECT * FROM ({$customQuery}) AS tableCustom WHERE 1=1",
            ];
        }

        $columns = $this->formatSelectColumns($definition['columns'] ?? []);

        return [
            "SELECT count(*) as aggregate FROM {$definition['table_name']} WHERE 1=1",
            "SELECT {$columns} FROM {$definition['table_name']} WHERE 1=1",
        ];
    }

    /**
     * Build the soft delete clause when the selected table supports deleted_at filtering.
     */
    protected function resolveSoftDeleteClause(array $definition): string
    {
        if (empty($definition['use_soft_delete']) || ! empty($definition['custom_query'])) {
            return '';
        }

        $tableName = trim((string) ($definition['table_name'] ?? ''));

        if ($tableName === '' || ! $this->tableHasColumn($tableName, 'deleted_at')) {
            return '';
        }

        return ' AND deleted_at IS NULL';
    }

    /**
     * Determine whether a table includes a specific column.
     */
    protected function tableHasColumn(string $tableName, string $columnName): bool
    {
        try {
            $columns = $this->databaseMetadataProvider->listColumns($tableName, $this->executionConnectionName);
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }

            if (strtolower((string) ($column['name'] ?? '')) === strtolower($columnName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a custom query before wrapping it in a derived table.
     *
     * @param string $query
     * @return string
     */
    protected function normalizeCustomQuery(string $query): string
    {
        $query = trim($query);

        return trim((string) preg_replace('/;+\s*$/', '', $query));
    }

    /**
     * Resolve request-time ordering for the current datasource execution.
     *
     * @param Request $request
     * @param array<string, mixed> $definition
     * @return array{column:string,direction:string}|null
     */
    protected function resolveRuntimeOrdering(Request $request, array $definition): ?array
    {
        $orderBy = trim((string) $request->input('order_by', ''));

        if ($orderBy === '') {
            return null;
        }

        $direction = strtoupper(trim((string) $request->input('order_direction', 'ASC')));

        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $allowedColumns = $this->normalizeSelectableColumns($definition['columns'] ?? []);
        $allowedLookup = [];

        foreach ($allowedColumns as $column) {
            $allowedLookup[strtolower($column)] = $column;
        }

        if ($allowedColumns === []) {
            return null;
        }

        $matchedColumn = $allowedLookup[strtolower($orderBy)] ?? null;

        if (! is_string($matchedColumn) || $matchedColumn === '') {
            return null;
        }

        return [
            'column' => $matchedColumn,
            'direction' => $direction,
        ];
    }

    /**
     * Determine whether the SQL already contains an ORDER BY clause.
     */
    protected function containsOrderByClause(string $query): bool
    {
        return preg_match('/\border\s+by\b/i', $query) === 1;
    }

    /**
     * Normalize selected/displayed columns into a safe list of sort options.
     *
     * @param array<int, mixed> $columns
     * @return array<int, string>
     */
    protected function normalizeSelectableColumns(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $candidate = $column['name'] ?? $column['column'] ?? $column['value'] ?? $column['label'] ?? null;
            } else {
                $candidate = $column;
            }

            $candidate = trim((string) $candidate);

            if ($candidate === '' || $candidate === '*') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Quote a validated ORDER BY identifier without re-applying strict identifier
     * validation so custom query aliases remain usable.
     */
    protected function quoteOrderByIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return '';
        }

        $driver = $this->databaseDriverResolver->resolve($this->executionConnectionName);

        if (str_contains($identifier, '.')) {
            $segments = array_map(
                static fn (string $segment): string => trim($segment, " \t\n\r\0\x0B`\""),
                explode('.', $identifier)
            );

            $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));

            if ($segments === []) {
                return '';
            }

            return implode('.', array_map(
                static fn (string $segment) => $driver->quoteIdentifier($segment),
                $segments
            ));
        }

        return $driver->quoteIdentifier(trim($identifier, " \t\n\r\0\x0B`\""));
    }

    /**
     * Resolve conditional SQL blocks using request-provided custom parameters.
     *
     * A block is kept only when every custom parameter referenced inside it
     * has a non-empty value on the request.
     *
     * @param string $query
     * @param Request $request
     * @return string
     */
    protected function applyConditionalBlocks(string $query, Request $request): string
    {
        return preg_replace_callback(
            '/\[\[\s*(.*?)\s*\]\]/s',
            function (array $matches) use ($request): string {
                $inner = (string) ($matches[1] ?? '');
                $parameterNames = $this->extractCustomParameterNames($inner);

                foreach ($parameterNames as $name) {
                    if (! $this->requestHasCustomParameter($request, $name)) {
                        return '';
                    }
                }

                return $inner;
            },
            $query
        ) ?? $query;
    }

    /**
     * Replace route-style placeholders such as {customer_id} with request values.
     *
     * @param string $query
     * @param Request $request
     * @return string
     */
    protected function applyRouteParameterPlaceholders(string $query, Request $request): string
    {
        $pattern = '/\{([a-zA-Z0-9_]+)\}/';

        if (preg_match($pattern, $query) !== 1) {
            return $query;
        }

        return preg_replace_callback(
            $pattern,
            function (array $matches) use ($request): string {
                $key = $matches[1];
                $value = $request->input($key);

                if ($value === null || $value === '') {
                    $value = $request->route($key);
                }

                if ($value === null || $value === '') {
                    throw new \InvalidArgumentException("Missing route parameter: {$key}");
                }

                Log::debug('DataSource route placeholder resolved', [
                    'field' => $key,
                    'value' => $value,
                    'source' => $request->input($key) !== null && $request->input($key) !== ''
                        ? 'request_input'
                        : 'route_parameter',
                ]);

                return $this->quoteSqlValue($value);
            },
            $query
        ) ?? $query;
    }

    /**
     * Replace custom parameters such as :keyword using query/body values.
     *
     * @param string $query
     * @param Request $request
     * @param array<int, mixed> $customParameters
     * @return string
     */
    protected function applyCustomParameterPlaceholders(string $query, Request $request, array $customParameters): string
    {
        $definitions = $this->normalizeCustomParameters($customParameters);
        $usedParameters = $this->extractCustomParameterNames($query);

        Log::debug('DataSource custom placeholder scan', [
            'query' => $query,
            'detected_placeholders' => $usedParameters,
            'custom_parameters' => array_keys($definitions),
        ]);

        foreach ($usedParameters as $name) {
            if (! array_key_exists($name, $definitions)) {
                throw new \InvalidArgumentException("Custom parameter \"{$name}\" is not defined.");
            }
        }

        return preg_replace_callback(
            '/(?<!:):([A-Za-z_][A-Za-z0-9_]*)\b/',
            function (array $matches) use ($request, $definitions): string {
                $name = $matches[1];
                $definition = $definitions[$name] ?? null;

                if (! is_array($definition)) {
                    throw new \InvalidArgumentException("Custom parameter \"{$name}\" is not defined.");
                }

                $value = $this->resolveRequestValue($request, $name);

                if (($value === null || $value === '') && array_key_exists('default', $definition)) {
                    $value = $definition['default'];
                }

                if (($value === null || $value === '') && ! empty($definition['required'])) {
                    throw new \InvalidArgumentException("Custom parameter \"{$name}\" is required.");
                }

                $value = $this->parseRuntimeValue($value);
                $value = $this->formatCustomParameterValue($definition['type'] ?? 'string', $value);

                Log::debug('DataSource custom placeholder resolved', [
                    'field' => $name,
                    'value' => $value,
                ]);

                return $this->quoteSqlValue($value);
            },
            $query
        ) ?? $query;
    }

    /**
     * Determine whether the current request contains a usable value for a custom parameter.
     *
     * @param Request $request
     * @param string $name
     * @return bool
     */
    protected function requestHasCustomParameter(Request $request, string $name): bool
    {
        $value = $this->resolveRequestValue($request, $name);

        return $value !== null && $value !== '';
    }

    /**
     * Resolve a request value with route parameters taking precedence.
     *
     * @param Request $request
     * @param string $name
     * @return mixed
     */
    protected function resolveRequestValue(Request $request, string $name): mixed
    {
        $value = $request->input($name);

        if ($value !== null && $value !== '') {
            return $value;
        }

        $allowedRouteParameters = $request->attributes->get('datasources.route_parameter_names', []);

        if (! is_array($allowedRouteParameters) || ! in_array($name, $allowedRouteParameters, true)) {
            return $request->query($name);
        }

        $value = $request->route($name);

        if ($value !== null && $value !== '') {
            return $value;
        }

        return $request->query($name);
    }

    /**
     * Extract custom parameter names from a SQL string.
     *
     * @param string $query
     * @return array<int, string>
     */
    protected function extractCustomParameterNames(string $query): array
    {
        if ($query === '') {
            return [];
        }

        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)\b/', $query, $matches);

        $names = $matches[1] ?? [];

        return array_values(array_unique(array_filter($names, static fn ($name) => is_string($name) && trim($name) !== '')));
    }

    /**
     * Extract route-style placeholders from a SQL string.
     *
     * @param string $query
     * @return array<int, string>
     */
    protected function extractRoutePlaceholders(string $query): array
    {
        if ($query === '') {
            return [];
        }

        preg_match_all('/(?<!\{)\{([A-Za-z_][A-Za-z0-9_]*)\}(?!\})/', $query, $matches);

        $names = $matches[1] ?? [];

        return array_values(array_unique(array_filter($names, static fn ($name) => is_string($name) && trim($name) !== '')));
    }

    /**
     * Normalize custom parameter definitions into a keyed array.
     *
     * @param array<int, mixed> $customParameters
     * @return array<string, array<string, mixed>>
     */
    protected function normalizeCustomParameters(array $customParameters): array
    {
        $normalized = [];

        foreach ($customParameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = trim((string) ($parameter['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized[$name] = [
                'name' => $name,
                'type' => $this->normalizeCustomParameterType($parameter['type'] ?? 'string'),
                'required' => (bool) ($parameter['required'] ?? false),
                'default' => $parameter['default'] ?? $parameter['default_value'] ?? null,
                'description' => is_string($parameter['description'] ?? null) ? trim((string) $parameter['description']) : '',
                'unused' => (bool) ($parameter['unused'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * Sync custom parameter metadata with placeholders found in the query.
     *
     * @param array<int, array<string, mixed>> $customParameters
     * @param string $query
     * @return array<int, array<string, mixed>>
     */
    protected function syncCustomParameters(array $customParameters, string $query): array
    {
        $definitions = $this->normalizeCustomParameters($customParameters);
        $usedNames = $this->extractCustomParameterNames($query);
        $synced = [];

        foreach ($definitions as $name => $definition) {
            $definition['unused'] = ! in_array($name, $usedNames, true);
            $synced[$name] = $definition;
        }

        foreach ($usedNames as $name) {
            if (array_key_exists($name, $synced)) {
                $synced[$name]['unused'] = false;
                continue;
            }

            $synced[$name] = [
                'name' => $name,
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => '',
                'unused' => false,
            ];
        }

        return array_values($synced);
    }

    /**
     * Normalize the declared custom parameter type.
     *
     * @param mixed $value
     * @return string
     */
    protected function normalizeCustomParameterType(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['string', 'integer', 'boolean', 'date', 'float'], true)
            ? $normalized
            : 'string';
    }

    /**
     * Convert a custom parameter value according to its declared type.
     *
     * @param string $type
     * @param mixed $value
     * @return mixed
     */
    protected function formatCustomParameterValue(string $type, mixed $value): mixed
    {
        return $this->findFormatValue($this->normalizeCustomParameterType($type), $value, false);
    }

    /**
     * Format a SELECT column list while keeping raw SQL expressions intact.
     *
     * @param array<int, mixed> $columns
     * @return string
     */
    protected function formatSelectColumns(array $columns): string
    {
        $normalized = [];

        foreach ($columns as $column) {
            $formatted = $this->normalizeSelectColumn($column);

            if ($formatted === '') {
                continue;
            }

            $normalized[] = $formatted;
        }

        return $normalized === [] ? '*' : implode(' , ', $normalized);
    }

    /**
     * Normalize a single SELECT column or raw expression.
     *
     * @param mixed $column
     * @return string
     */
    protected function normalizeSelectColumn(mixed $column): string
    {
        if (! is_string($column) && ! is_numeric($column)) {
            return '';
        }

        $column = trim((string) $column);

        if ($column === '') {
            return '';
        }

        if (
            $column === '*'
            || str_contains($column, '(')
            || str_contains($column, ')')
            || preg_match('/\s+as\s+/i', $column) === 1
        ) {
            return $column;
        }

        $segments = explode('.', $column);
        $driver = $this->databaseDriverResolver->resolve($this->executionConnectionName);

        foreach ($segments as $index => $segment) {
            $segment = trim($segment, " \t\n\r\0\x0B`\"");

            if ($segment === '') {
                continue;
            }

            if ($segment === '*') {
                $segments[$index] = '*';
                continue;
            }

            $segments[$index] = $driver->quoteIdentifier($segment);
        }

        return implode('.', $segments);
    }

    protected function runQueryDirectly(mixed $queryCount, mixed $query, Request $request, array $definition): array|LengthAwarePaginator|Collection
    {
        try {
            $connection = DatabaseConnection::connection($this->executionConnectionName);
            $query = $this->ensureStringQuery($query);
            if ($queryCount !== null) {
                $queryCount = $this->ensureStringQuery($queryCount);
            }
            if (!empty($request->page)) {
                if (empty($queryCount)) {
                    $count = count($connection->select($query));
                } else {
                    $dataCount = $connection->select($queryCount);
                    $count = $dataCount[0]->aggregate;
                }

                $perPage = $request->per_page ?? 10;
                $page = $request->page == "" ? "1" : $request->page;
                $start = ($page - 1) * $perPage;
                $query = $this->databaseDriverResolver
                    ->resolve($this->executionConnectionName)
                    ->compilePaginatedQuery($query, (int) $start, (int) $perPage);

                $data = $connection->select($query);
                $dataResult = $this->paginate($data, $count, $perPage, $request->page);
            } else {
                $data = $connection->select($query);
                $dataResult = ['data' => $data];
            }

            if (!empty($request->isDebug) && $request->isDebug) {
                $driver = $this->databaseDriverResolver->resolve($this->executionConnectionName);
                $explainQuery = $this->ensureStringQuery($driver->compileExplainQuery($query));
                $dataExplain = $connection->select($explainQuery);

                if (!empty($definition['use_custom_query'])) {
                    $custom = collect(['data_index' => [], 'data_explain' => $dataExplain, 'query_sql' => $query]);
                    $dataResult = $custom->merge($dataResult);
                } else {
                    $dataIndex = $this->databaseMetadataProvider->listIndexes(
                        (string) $definition['debug_index_table'],
                        $this->executionConnectionName
                    );
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

    /**
     * Backward-compatible query executor used by the cache bypass path.
     */
    protected function makeQuery(mixed $queryCount, mixed $query, Request $request, array $definition): array|LengthAwarePaginator|Collection
    {
        return $this->runQueryDirectly($queryCount, $query, $request, $definition);
    }

    protected function paginate($items, int $total, int $perPage = 5, int $page = 1, array $options = []): LengthAwarePaginator
    {
        $items = $items instanceof Collection ? $items : Collection::make($items);

        return new LengthAwarePaginator($items, $total, $perPage, $page, $options);
    }

    protected function validateDetail(Request $request): ?JsonResponse
    {
        foreach ($this->incomingFilters($request) as $key => $value) {
            $resolvedOperator = $this->resolveIncomingFilterOperator($value);
            $normalizedFilter = [
                'field' => $value['field'] ?? $value['param_name'] ?? $value['name'] ?? null,
                'operator' => $resolvedOperator,
                'value' => $value['value'] ?? $value['param_value'] ?? null,
            ];

            $validator = Validator::make($normalizedFilter, [
                'field' => 'required',
                'value' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors(),
                    'message' => 'Invalid payload params at row ' . strval(intval($key) + 1),
                ], 400);
            }

            if (! in_array($normalizedFilter['operator'], self::ALLOWED_FILTER_OPERATORS, true)) {
                return response()->json([
                    'error' => "Invalid filter operator: {$normalizedFilter['operator']}",
                    'message' => "Invalid filter operator: {$normalizedFilter['operator']}",
                ], 400);
            }
        }

        return null;
    }

    /**
     * Resolve an operator from the incoming filter payload.
     *
     * Legacy payloads that still send `param_operation` are supported, but
     * type-based resolution is preferred for new configurations.
     *
     * @param array<string, mixed> $filter
     * @return string
     */
    protected function resolveIncomingFilterOperator(array $filter): string
    {
        $type = trim((string) ($filter['type'] ?? $filter['param_type'] ?? ''));

        return $type !== '' ? FilterOperatorResolver::resolve($type) : '=';
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
        $value = $this->resolveRequestValue($request, $field);

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
        $driver = $this->databaseDriverResolver->resolve($this->executionConnectionName);
        $operator = $driver->normalizeLikeOperator($this->normalizeFilterOperator($operator));

        return match ($operator) {
            '=', '!=', '>', '<', '>=', '<=' => ' AND ' . $column . ' ' . $operator . ' ' . $this->quoteSqlValue($value),
            'LIKE' => ' AND ' . $column . ' LIKE ' . $this->quoteSqlValue('%' . (string) $value . '%'),
            'NOT LIKE' => ' AND ' . $column . ' NOT LIKE ' . $this->quoteSqlValue('%' . (string) $value . '%'),
            'ILIKE' => ' AND ' . $column . ' ILIKE ' . $this->quoteSqlValue('%' . (string) $value . '%'),
            'NOT ILIKE' => ' AND ' . $column . ' NOT ILIKE ' . $this->quoteSqlValue('%' . (string) $value . '%'),
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

        $quoted = DatabaseConnection::connection($this->executionConnectionName)->getPdo()->quote((string) $value);

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

        $driver = $this->databaseDriverResolver->resolve($this->executionConnectionName);
        $segments = explode('.', $identifier);
        $quotedSegments = [];

        foreach ($segments as $segment) {
            $segment = trim($segment, " \t\n\r\0\x0B`\"");

            if ($segment === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                throw new \InvalidArgumentException("Invalid filter field: {$identifier}");
            }

            $quotedSegments[] = $driver->quoteIdentifier($segment);
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

    protected function normalizeResponseType(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['array', 'object'], true) ? $normalized : 'array';
    }

    protected function applyResponseType(mixed $result, string $responseType): mixed
    {
        if ($this->normalizeResponseType($responseType) !== 'object') {
            return $result;
        }

        $data = null;

        if ($result instanceof LengthAwarePaginator) {
            $data = $result->items();
        } elseif ($result instanceof Collection) {
            $data = $result->values()->all();
        } elseif (is_array($result)) {
            $data = $result['data'] ?? $result;
        }

        if (! is_array($data)) {
            return [
                'data' => (object) [],
                'message' => 'Data not found',
            ];
        }

        if ($data === [] || count($data) === 0) {
            return [
                'data' => (object) [],
                'message' => 'Data not found',
            ];
        }

        $first = $data[0] ?? null;

        if (is_array($first)) {
            return ['data' => $first];
        }

        if (is_object($first)) {
            return ['data' => $first];
        }

        return [
            'data' => (object) [],
            'message' => 'Data not found',
        ];
    }

    protected function normalizeResultJsonColumns(mixed $result, array $definition): mixed
    {
        $jsonColumns = $this->resolveJsonColumnMap($definition);
        $safeDetectAllColumns = empty($jsonColumns) && ! empty($definition['use_custom_query']);

        if ($jsonColumns === [] && ! $safeDetectAllColumns) {
            return $result;
        }

        if ($result instanceof LengthAwarePaginator) {
            $result->setCollection(
                $result->getCollection()->map(
                    fn (mixed $row): mixed => $this->normalizeJsonColumnsForRow($row, $jsonColumns, $safeDetectAllColumns)
                )
            );

            return $result;
        }

        if ($result instanceof Collection) {
            return $result->map(
                fn (mixed $row): mixed => $this->normalizeJsonColumnsForRow($row, $jsonColumns, $safeDetectAllColumns)
            );
        }

        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            $result['data'] = array_map(
                fn (mixed $row): mixed => $this->normalizeJsonColumnsForRow($row, $jsonColumns, $safeDetectAllColumns),
                $result['data']
            );

            return $result;
        }

        if (is_array($result) && array_is_list($result)) {
            return array_map(
                fn (mixed $row): mixed => $this->normalizeJsonColumnsForRow($row, $jsonColumns, $safeDetectAllColumns),
                $result
            );
        }

        return $result;
    }

    /**
     * @return array<string, true>
     */
    protected function resolveJsonColumnMap(array $definition): array
    {
        $tableName = trim((string) ($definition['table_name'] ?? ''));

        if ($tableName === '' || ! empty($definition['use_custom_query'])) {
            return [];
        }

        try {
            $columns = $this->databaseMetadataProvider->listColumns($tableName, $this->executionConnectionName);
        } catch (\Throwable $e) {
            return [];
        }

        $configuredJsonColumns = $this->configuredJsonColumnsForTable($tableName);
        $jsonColumns = [];

        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }

            $name = trim((string) ($column['name'] ?? ''));
            $type = $this->normalizeDatabaseColumnType($column['type'] ?? null);

            if ($name === '' || $type === '') {
                continue;
            }

            if (in_array($type, self::JSON_NATIVE_COLUMN_TYPES, true)) {
                $jsonColumns[$name] = true;
                continue;
            }

            if (in_array($type, self::JSON_AUTO_DECODE_TEXT_COLUMN_TYPES, true)) {
                $jsonColumns[$name] = true;
                continue;
            }

            if (
                in_array($type, self::JSON_CONFIGURABLE_TEXT_COLUMN_TYPES, true)
                && isset($configuredJsonColumns[strtolower($name)])
            ) {
                $jsonColumns[$name] = true;
            }
        }

        return $jsonColumns;
    }

    /**
     * @return array<string, true>
     */
    protected function configuredJsonColumnsForTable(string $tableName): array
    {
        $configured = config('datasources.json_columns', []);

        if (! is_array($configured)) {
            return [];
        }

        $candidates = array_values(array_unique(array_filter([
            $tableName,
            strtolower($tableName),
            $this->normalizeTableLookupKey($tableName),
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));

        foreach ($candidates as $candidate) {
            $columns = $configured[$candidate] ?? null;

            if (! is_array($columns)) {
                continue;
            }

            $normalized = [];

            foreach ($columns as $column) {
                if (! is_string($column) || trim($column) === '') {
                    continue;
                }

                $normalized[strtolower(trim($column))] = true;
            }

            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    protected function normalizeJsonColumnsForRow(mixed $row, array $jsonColumns, bool $safeDetectAllColumns = false): mixed
    {
        if (is_object($row)) {
            foreach (get_object_vars($row) as $column => $value) {
                if ($safeDetectAllColumns) {
                    $row->{$column} = $this->decodePotentialJsonValue($value);
                    continue;
                }

                if (! isset($jsonColumns[$column])) {
                    continue;
                }

                $row->{$column} = $this->decodeJsonColumnValue($value);
            }

            return $row;
        }

        if (is_array($row)) {
            foreach ($row as $column => $value) {
                if ($safeDetectAllColumns) {
                    $row[$column] = $this->decodePotentialJsonValue($value);
                    continue;
                }

                if (! isset($jsonColumns[$column])) {
                    continue;
                }

                $row[$column] = $this->decodeJsonColumnValue($value);
            }
        }

        return $row;
    }

    protected function decodeJsonColumnValue(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $value;
        }
    }

    protected function decodePotentialJsonValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $firstChar = $trimmed[0] ?? '';

        if (! in_array($firstChar, ['{', '['], true)) {
            return $value;
        }

        try {
            return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $value;
        }
    }

    protected function normalizeDatabaseColumnType(mixed $type): string
    {
        $normalized = strtolower(trim((string) $type));

        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/\(.+\)$/', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function normalizeTableLookupKey(string $tableName): string
    {
        $segments = array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment, " \t\n\r\0\x0B`\""),
            explode('.', $tableName)),
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return '';
        }

        if (count($segments) >= 2) {
            return strtolower($segments[count($segments) - 2] . '.' . $segments[count($segments) - 1]);
        }

        return strtolower($segments[0]);
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
                'default' => $this->parseRuntimeValue($param['default'] ?? null),
            ];
        }

        return $normalized;
    }

    protected function parseRuntimeValue(mixed $value): mixed
    {
        return $this->runtimeVariableParser->parse($value);
    }

    protected function ensureStringQuery(mixed $query): string
    {
        if ($query instanceof \Illuminate\Database\Query\Expression) {
            if (method_exists($query, 'getValue')) {
                try {
                    $query = $query->getValue(DatabaseConnection::connection($this->executionConnectionName)->getQueryGrammar());
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

    protected function resolveExecutionConnectionName(Request $request): string
    {
        return $this->executionConnectionResolver->resolve($request);
    }

    protected function normalizeConnectionName(mixed $connectionName): string
    {
        if (! is_string($connectionName)) {
            return DatabaseConnection::configuredName();
        }

        $connectionName = trim($connectionName);

        return $connectionName !== '' ? $connectionName : DatabaseConnection::configuredName();
    }

    protected function cachePrefixForExecution(string $key): string
    {
        $connection = $this->executionConnectionName ?? DatabaseConnection::configuredName();

        try {
            $databaseName = DatabaseConnection::connection($connection)->getDatabaseName();

            if (is_string($databaseName) && trim($databaseName) !== '') {
                return $connection . '@' . trim($databaseName) . ':' . $key;
            }
        } catch (\Throwable $e) {
            // Fall through to connection-scoped key.
        }

        return $connection . ':' . $key;
    }
}
