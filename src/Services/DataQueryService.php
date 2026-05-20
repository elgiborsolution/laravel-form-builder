<?php

namespace ESolution\DataSources\Services;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Models\DataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DataQueryService
{
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

        $queryParams = [];
        $queryParamWithOperator = [];
        $paramsWithOperator = $request->params ?? [];

        foreach ($paramsWithOperator as $value) {
            if (!empty($value['param_name'])) {
                $paramsWithOperator[$value['param_name']] = $value;
            }
        }

        foreach ($definition['parameters'] as $parameter) {
            $paramName = $parameter['name'];
            $paramValue = $request->get($paramName, $parameter['default']);

            if ($parameter['required'] && $paramValue === null) {
                return response()->json([
                    'error' => "Parameter '$paramName' is required",
                    'message' => "Parameter '$paramName' is required",
                ], 422);
            }

            $paramValue = $this->findFormatValue($parameter['type'], $paramValue);
            $queryParams[$paramName] = $paramValue;

            if (count($paramsWithOperator) > 0) {
                $operatorParam = $paramsWithOperator[$paramName] ?? null;
                if (!empty($operatorParam)) {
                    $paramOpValue = $this->findFormatValue(
                        $parameter['type'],
                        $operatorParam['param_value'],
                        strtolower((string) $operatorParam['param_operation']) === 'like'
                    );

                    $queryParamWithOperator[$paramName] = [
                        'value' => $paramOpValue,
                        'operator' => $operatorParam['param_operation'],
                    ];
                }
            }
        }

        [$queryCount, $query] = $this->buildBaseQueries($definition);

        foreach ($queryParams as $key => $value) {
            if (!empty($value) && $value != '') {
                $query .= " AND $key = '" . $value . "'";
                $queryCount .= " AND $key = '" . $value . "'";
            }
        }

        foreach ($queryParamWithOperator as $key => $value) {
            $query .= " AND $key " . $value['operator'] . " '" . $value['value'] . "'";
            $queryCount .= " AND $key " . $value['operator'] . " '" . $value['value'] . "'";
        }

        $cacheKey = $definition['identifier'] . '_query_' . md5(
            $query . json_encode($queryParams) . '-' . json_encode($queryParamWithOperator) . ($request->page ?? '0')
        );

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
                    $count = count(DB::select($query));
                } else {
                    $dataCount = DB::select($queryCount);
                    $count = $dataCount[0]->aggregate;
                }

                $perPage = $request->per_page ?? 10;
                $page = $request->page == "" ? "1" : $request->page;
                $start = ($page - 1) * $perPage;
                $query .= ' LIMIT ' . $start . ', ' . $perPage;

                $data = DB::select($query);
                $dataResult = $this->paginate($data, $count, $perPage, $request->page);
            } else {
                $data = DB::select($query);
                $dataResult = ['data' => $data];
            }

            if (!empty($request->isDebug) && $request->isDebug) {
                $explainQuery = $this->ensureStringQuery('explain ' . $query);
                $dataExplain = DB::select($explainQuery);

                if (!empty($definition['use_custom_query'])) {
                    $custom = collect(['data_index' => [], 'data_explain' => $dataExplain, 'query_sql' => $query]);
                    $dataResult = $custom->merge($dataResult);
                } else {
                    $indexQuery = $this->ensureStringQuery('show index from ' . $definition['debug_index_table']);
                    $dataIndex = DB::select($indexQuery);
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
        $validateFilter = [
            'param_name' => 'required',
            'param_operation' => 'required',
            'param_value' => 'required',
        ];

        foreach ($request->params ?? [] as $key => $value) {
            $validator = Validator::make($value, $validateFilter);

            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors(),
                    'message' => 'Invalid payload params at row ' . strval(intval($key) + 1),
                ], 400);
            }
        }

        return null;
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
                    $query = $query->getValue(DB::getQueryGrammar());
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

