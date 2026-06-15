<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Models\ApiHook;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use ESolution\DataSources\Models\ApiTable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class DataAPIBuilderController extends Controller
{
    use AppliesSearchFilter;

    public function __construct(
        protected DynamicApiConfigResolver $resolver,
        protected DynamicVariableParser $runtimeVariableParser
    ) {
    }

    /**
     * Display a list of API Builder configurations.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

      public function index(Request $request)
      {
        $search = trim((string) $request->query('search', ''));

        $dataApiBuilder = $search !== ''
            ? $this->loadApiConfigs($request)
            : Cache::remember($this->cacheKey('list-api-configs'), 60, function () use ($request) {
                return $this->loadApiConfigs($request);
            });

          return response()->json(['data' => $dataApiBuilder], 200);
      }

    /**
     * Export API configuration records as a pretty printed JSON file.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        $ids = $this->normalizeSelectedIds($request->input('ids', []));

        $query = ApiConfig::on(DatabaseConnection::configuredName())
            ->with(['parentTable', 'childTables', 'permission', 'hook', 'beforeExecuteHook'])
            ->orderBy('id');

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $payload = $query
            ->get()
            ->map(fn (ApiConfig $config): array => $this->serializeApiConfig($config))
            ->values()
            ->all();

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return response()->json([
                'message' => 'Failed to generate export file.',
            ], 500);
        }

        $filename = 'api-configs-' . now()->format('Y-m-d_His') . '.json';

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $filename,
            [
                'Content-Type' => 'application/json',
            ]
        );
    }

    /**
     * Import API configuration records from a JSON file.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        $rows = $this->normalizeImportRowsFromRequest($request);

        if ($rows === null) {
            return response()->json([
                'message' => 'Invalid JSON structure',
            ], 422);
        }

        \Log::info('Import payload', ['rows' => $rows]);

        $validator = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array'],
            'rows.*' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid JSON structure',
                'errors' => $validator->errors(),
            ], 422);
        }

        $summary = [
            'selected' => count($rows),
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $errors = [];
        $processedKeys = [];

        $connection = DatabaseConnection::connection();
        $connection->beginTransaction();

        try {
            foreach ($rows as $rowIndex => $row) {
                $result = $this->importApiConfigRow(
                    $row,
                    $summary,
                    $processedKeys,
                    'import.json',
                    $rowIndex + 1
                );

                if ($result !== null && isset($result['error'])) {
                    $errors[] = $result['error'];
                }
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            \Log::error('IMPORT API CONFIGS => ' . $exception->getMessage());
            \Log::error('IMPORT API CONFIGS => ' . (tenant()->id ?? 'tenant not found'));
            \Log::error('IMPORT API CONFIGS => ' . $exception->getTraceAsString());

            return response()->json([
                'message' => 'Failed to import API configurations.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        Cache::forget($this->cacheKey('list-api-configs'));

        return response()->json([
            'status' => 200,
            'message' => 'Import completed.',
            'selected' => $summary['selected'],
            'imported' => $summary['imported'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
            'summary' => $summary,
            'errors' => $errors,
        ], 200);
    }

    /**
     * Return package defaults used by the frontend create form.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function defaults(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data' => [
                'default_api_middlewares' => $this->getDefaultApiMiddlewares(),
            ],
        ]);
    }

    /**
     * Normalize selected record ids from request input.
     *
     * @param mixed $ids
     * @return array<int, int|string>
     */
    protected function normalizeSelectedIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static function (mixed $id): int|string|null {
                if (! is_int($id) && ! is_string($id)) {
                    return null;
                }

                $trimmed = trim((string) $id);

                if ($trimmed === '') {
                    return null;
                }

                return ctype_digit($trimmed) ? (int) $trimmed : $trimmed;
            }, $ids),
            static fn (mixed $id): bool => $id !== null
        ));
    }

    /**
     * Normalize imported rows from request payload or uploaded JSON file.
     *
     * @param Request $request
     * @return array<int, array<string, mixed>>|null
     */
    protected function normalizeImportRowsFromRequest(Request $request): ?array
    {
        $rows = $request->input('rows');

        if (is_string($rows) && trim($rows) !== '') {
            try {
                $rows = json_decode($rows, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                \Log::warning('IMPORT API CONFIGS => invalid rows JSON string', [
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }
        } elseif ($request->hasFile('file')) {
            $file = $request->file('file');
            $contents = @file_get_contents($file->getRealPath());

            if ($contents === false || trim($contents) === '') {
                \Log::warning('IMPORT API CONFIGS => empty upload file', [
                    'file_name' => $file->getClientOriginalName(),
                ]);

                return null;
            }

            try {
                $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                \Log::warning('IMPORT API CONFIGS => invalid uploaded JSON file', [
                    'file_name' => $file->getClientOriginalName(),
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }
        } elseif (is_array($rows)) {
            // Keep as-is.
        } else {
            return null;
        }

        if (! is_array($rows)) {
            return null;
        }

        if (array_key_exists('rows', $rows) && is_array($rows['rows'])) {
            $rows = $rows['rows'];
        }

        if ($rows === []) {
            return [];
        }

        if (! $this->isSequentialArray($rows)) {
            return null;
        }

        return $rows;
    }

    /**
     * Resolve import rows from uploaded file or JSON payload.
     *
     * @param Request $request
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveImportBatches(Request $request): ?array
    {
        $payload = $this->decodeImportPayload($request);

        if ($payload !== null) {
            $batches = $this->normalizeImportBatches($payload);

            if ($batches !== null) {
                return $batches;
            }
        }

        $uploadedFiles = $request->file('files');

        if ($uploadedFiles === null) {
            $uploadedFiles = $request->file('file');
        }

        if ($uploadedFiles === null) {
            \Log::warning('IMPORT API CONFIGS => missing rows payload and uploaded files');

            return null;
        }

        if (! is_array($uploadedFiles)) {
            $uploadedFiles = [$uploadedFiles];
        }

        $batches = [];

        foreach ($uploadedFiles as $file) {
            $contents = @file_get_contents($file->getRealPath());

            if ($contents === false || trim($contents) === '') {
                \Log::warning('IMPORT API CONFIGS => empty upload file', [
                    'file_name' => $file->getClientOriginalName(),
                ]);
                return null;
            }

            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                \Log::warning('IMPORT API CONFIGS => invalid JSON file', [
                    'file_name' => $file->getClientOriginalName(),
                    'error' => $exception->getMessage(),
                ]);
                return null;
            }

            $normalized = $this->normalizeImportBatches($decoded, $file->getClientOriginalName() ?: 'import.json');

            if ($normalized === null) {
                \Log::warning('IMPORT API CONFIGS => invalid JSON structure in file', [
                    'file_name' => $file->getClientOriginalName(),
                ]);
                return null;
            }

            $batches = array_merge($batches, $normalized);
        }

        return $batches;
    }

    /**
     * Decode the import payload from request rows/data fields.
     *
     * @param Request $request
     * @return mixed
     */
    protected function decodeImportPayload(Request $request): mixed
    {
        foreach (['payload', 'rows', 'data'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && trim($value) !== '') {
                try {
                    return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    \Log::warning('IMPORT API CONFIGS => invalid JSON payload', [
                        'field' => $key,
                        'error' => $exception->getMessage(),
                    ]);

                    return null;
                }
            }

            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Normalize an incoming payload into file batches.
     *
     * @param mixed $payload
     * @param string|null $fallbackFileName
     * @return array<int, array{file_name: string, rows: array<int, array<string, mixed>>}>|null
     */
    protected function normalizeImportBatches(mixed $payload, ?string $fallbackFileName = null): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        if ($payload === []) {
            return [];
        }

        if ($this->isAssocArray($payload)) {
            if (isset($payload['files']) && is_array($payload['files'])) {
                $batches = [];

                foreach ($payload['files'] as $index => $filePayload) {
                    $batch = $this->normalizeImportBatch($filePayload, $fallbackFileName, $index);

                    if ($batch === null) {
                        return null;
                    }

                    $batches[] = $batch;
                }

                return $batches;
            }

            if ($this->looksLikeApiConfigPayload($payload)) {
                return [
                    [
                        'file_name' => $fallbackFileName ?: 'import.json',
                        'rows' => [$payload],
                    ],
                ];
            }

            return null;
        }

        $batches = [];

        foreach ($payload as $index => $item) {
            if (! is_array($item)) {
                return null;
            }

            if (isset($item['rows']) || isset($item['data']) || isset($item['items']) || isset($item['file_name']) || isset($item['source_file'])) {
                $batch = $this->normalizeImportBatch($item, $fallbackFileName, $index);

                if ($batch === null) {
                    return null;
                }

                $batches[] = $batch;
                continue;
            }

            $batch = $this->normalizeImportBatch([
                'file_name' => $fallbackFileName ?: 'import.json',
                'rows' => $payload,
            ], $fallbackFileName, $index);

            return $batch === null ? null : [$batch];
        }

        return $batches;
    }

    /**
     * Normalize a single batch entry.
     *
     * @param mixed $batch
     * @param string|null $fallbackFileName
     * @param int|string $index
     * @return array{file_name: string, rows: array<int, array<string, mixed>>}|null
     */
    protected function normalizeImportBatch(mixed $batch, ?string $fallbackFileName = null, int|string $index = 0): ?array
    {
        if (! is_array($batch)) {
            return null;
        }

        $fileName = $fallbackFileName ?: 'import.json';

        if (isset($batch['file_name']) && is_string($batch['file_name']) && trim($batch['file_name']) !== '') {
            $fileName = trim($batch['file_name']);
        } elseif (isset($batch['source_file']) && is_string($batch['source_file']) && trim($batch['source_file']) !== '') {
            $fileName = trim($batch['source_file']);
        } elseif ($fallbackFileName !== null) {
            $fileName = $fallbackFileName;
        } elseif (is_int($index) || is_string($index)) {
            $fileName = 'import-' . $index . '.json';
        }

        $rows = $batch['rows'] ?? $batch['data'] ?? $batch['items'] ?? null;

        if ($rows === null && $this->looksLikeApiConfigPayload($batch)) {
            $rows = $this->expandLegacyApiConfigPayload($batch);
        }

        if (is_string($rows) && trim($rows) !== '') {
            try {
                $rows = json_decode($rows, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                \Log::warning('IMPORT API CONFIGS => invalid nested rows JSON', [
                    'file_name' => $fileName,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }
        }

        if (! is_array($rows)) {
            return null;
        }

        if ($rows === []) {
            return [
                'file_name' => $fileName,
                'rows' => [],
            ];
        }

        if (! $this->isSequentialArray($rows)) {
            $rows = [$rows];
        }

        return [
            'file_name' => $fileName,
            'rows' => $rows,
        ];
    }

    /**
     * Determine whether the payload looks like a single API config object.
     *
     * @param array<string, mixed> $payload
     * @return bool
     */
    protected function looksLikeApiConfigPayload(array $payload): bool
    {
        return isset($payload['route_name'], $payload['endpoint'], $payload['method'])
            || isset($payload['name'], $payload['base_url'])
            || isset($payload['endpoints']) && is_array($payload['endpoints']);
    }

    /**
     * Expand a legacy API config payload into one or more rows.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    protected function expandLegacyApiConfigPayload(array $payload): array
    {
        if (! isset($payload['endpoints']) || ! is_array($payload['endpoints']) || $payload['endpoints'] === []) {
            return [$this->normalizeLegacyApiConfigPayload($payload)];
        }

        $rows = [];

        foreach ($payload['endpoints'] as $endpointRow) {
            if (! is_array($endpointRow)) {
                continue;
            }

            $rows[] = $this->normalizeLegacyApiConfigPayload(array_merge($payload, $endpointRow));
        }

        return $rows === [] ? [$this->normalizeLegacyApiConfigPayload($payload)] : $rows;
    }

    /**
     * Normalize legacy API config aliases to the persisted shape.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function normalizeLegacyApiConfigPayload(array $payload): array
    {
        if (! isset($payload['route_name']) && isset($payload['name'])) {
            $payload['route_name'] = $payload['name'];
        }

        if (! isset($payload['endpoint']) && isset($payload['base_url'])) {
            $payload['endpoint'] = $payload['base_url'];
        }

        if (! isset($payload['method'])) {
            $payload['method'] = 'GET';
        }

        if (! isset($payload['description']) && isset($payload['name'])) {
            $payload['description'] = $payload['name'];
        }

        if (isset($payload['middlewares']) && is_string($payload['middlewares']) && trim($payload['middlewares']) !== '') {
            try {
                $payload['middlewares'] = json_decode($payload['middlewares'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                \Log::warning('IMPORT API CONFIGS => invalid middlewares JSON', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (isset($payload['before_execute_hook']) && is_string($payload['before_execute_hook']) && trim($payload['before_execute_hook']) !== '') {
            try {
                $payload['before_execute_hook'] = json_decode($payload['before_execute_hook'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                \Log::warning('IMPORT API CONFIGS => invalid before_execute_hook JSON', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (! isset($payload['middlewares']) && isset($payload['headers']) && is_array($payload['headers'])) {
            $payload['middlewares'] = $payload['headers'];
        }

        if (isset($payload['params']) && is_string($payload['params']) && trim($payload['params']) !== '') {
            try {
                $payload['params'] = json_decode($payload['params'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                \Log::warning('IMPORT API CONFIGS => invalid params JSON', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (isset($payload['params']) && is_object($payload['params'])) {
            $payload['params'] = json_decode(json_encode($payload['params']), true);
        }

        if (! isset($payload['params']) && isset($payload['rules']) && is_array($payload['rules'])) {
            $payload['params'] = $payload['rules'];
        }

        if (isset($payload['enabled']) && is_string($payload['enabled'])) {
            $normalizedEnabled = filter_var($payload['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalizedEnabled !== null) {
                $payload['enabled'] = $normalizedEnabled;
            } elseif (is_numeric($payload['enabled'])) {
                $payload['enabled'] = (int) $payload['enabled'];
            }
        }

        if (! isset($payload['enabled']) && isset($payload['status'])) {
            $payload['enabled'] = (bool) $payload['status'];
        }

        return $payload;
    }

    /**
     * Determine whether the array is sequential.
     *
     * @param array<mixed> $value
     * @return bool
     */
    protected function isSequentialArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Determine whether the array is associative.
     *
     * @param array<mixed> $value
     * @return bool
     */
    protected function isAssocArray(array $value): bool
    {
        return ! $this->isSequentialArray($value);
    }

    protected function importApiConfigRow(array $row, array &$batchSummary, array &$processedKeys, string $fileName, int $rowNumber): ?array
    {
        $payload = array_key_exists('data', $row) && is_array($row['data']) ? $row['data'] : $row;
        $payload = $this->normalizeLegacyApiConfigPayload($payload);

        if (! is_array($payload)) {
            $batchSummary['failed']++;
            $batchSummary['errors'][] = [
                'file_name' => $fileName,
                'row' => $rowNumber,
                'message' => 'Row must be a JSON object.',
            ];

            return [
                'error' => [
                    'file_name' => $fileName,
                    'row' => $rowNumber,
                    'message' => 'Row must be a JSON object.',
                ],
            ];
        }

        $validator = Validator::make($payload, [
            'route_name' => ['required', 'string'],
            'endpoint' => ['required', 'string'],
            'method' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'middlewares' => ['nullable', 'array'],
            'params' => ['nullable', 'array'],
            'enabled' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value) && ! is_int($value)) {
                    $fail('The enabled field must be a boolean or integer.');
                }
            }],
            'parent_table' => ['nullable', 'array'],
            'child_tables' => ['nullable', 'array'],
            'permission' => ['nullable'],
            'hook' => ['nullable'],
            'before_execute_hook' => ['nullable'],
        ]);

        if ($validator->fails()) {
            $batchSummary['failed']++;
            $batchSummary['errors'][] = [
                'file_name' => $fileName,
                'row' => $rowNumber,
                'message' => $validator->errors()->first(),
            ];

            return [
                'error' => [
                    'file_name' => $fileName,
                    'row' => $rowNumber,
                    'message' => $validator->errors()->first(),
                ],
            ];
        }

        if ($runtimeValidation = $this->validateRuntimeVariables($payload)) {
            $batchSummary['failed']++;
            $batchSummary['errors'][] = [
                'file_name' => $fileName,
                'row' => $rowNumber,
                'message' => $runtimeValidation->getData(true)['message'] ?? 'Invalid runtime variable expression.',
            ];

            return [
                'error' => [
                    'file_name' => $fileName,
                    'row' => $rowNumber,
                    'message' => $runtimeValidation->getData(true)['message'] ?? 'Invalid runtime variable expression.',
                ],
            ];
        }

        $routeName = trim((string) $payload['route_name']);
        $endpoint = $this->resolver->normalizeEndpoint((string) $payload['endpoint']);
        $method = strtoupper((string) $payload['method']);
        $duplicateKey = implode('|', [$routeName, $endpoint, $method]);

        if (isset($processedKeys[$duplicateKey])) {
            $batchSummary['skipped']++;

            return null;
        }

        if ($this->resolver->isReservedEndpoint($endpoint)) {
            $batchSummary['failed']++;
            $batchSummary['errors'][] = [
                'file_name' => $fileName,
                'row' => $rowNumber,
                'message' => 'The endpoint conflicts with a reserved package route.',
            ];

            return [
                'error' => [
                    'file_name' => $fileName,
                    'row' => $rowNumber,
                    'message' => 'The endpoint conflicts with a reserved package route.',
                ],
            ];
        }

        $config = ApiConfig::on(DatabaseConnection::configuredName())
            ->with(['parentTable', 'childTables', 'permission', 'hook', 'beforeExecuteHook'])
            ->where('route_name', $routeName)
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->first();

        $originalEndpoint = $config?->endpoint;
        $originalMethod = $config?->method;

        if (! $config) {
            $config = new ApiConfig();
        }

        $config->fill([
            'route_name' => $routeName,
            'endpoint' => $endpoint,
            'method' => $method,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : null,
            'middlewares' => $this->normalizeMiddlewares($payload['middlewares'] ?? null),
            'params' => $payload['params'] ?? null,
            'enabled' => (bool) $payload['enabled'],
        ]);
        $config->save();

        $this->syncApiConfigRelations($config, $payload);

        Cache::forget($this->cacheKey('list-api-configs'));
        $this->resolver->forget($endpoint, $method);

        if ($originalEndpoint && $originalMethod && ($originalEndpoint !== $endpoint || $originalMethod !== $method)) {
            $this->resolver->forget($originalEndpoint, $originalMethod);
        }

        $processedKeys[$duplicateKey] = true;

        $batchSummary['imported']++;

        return null;
    }

    protected function syncApiConfigRelations(ApiConfig $config, array $payload): void
    {
        $config->loadMissing('parentTable', 'childTables');
        $parentTable = $config->parentTable;

        if (array_key_exists('parent_table', $payload)) {
            if (is_array($payload['parent_table']) && ! empty($payload['parent_table'])) {
                $parentTableName = (string) ($payload['parent_table']['table_name'] ?? '');
                $parentSoftDelete = array_key_exists('use_soft_delete', $payload['parent_table'])
                    ? (bool) $payload['parent_table']['use_soft_delete']
                    : ($parentTable?->use_soft_delete ?? $this->tableHasDeletedAt($parentTableName));

                $parentTable = $config->parentTable()->updateOrCreate(
                    ['api_config_id' => $config->id],
                    [
                        'parent_id' => 0,
                        'table_name' => $parentTableName,
                        'primary_key' => $payload['parent_table']['primary_key'] ?? 'id',
                        'foreign_key' => $payload['parent_table']['foreign_key'] ?? null,
                        'data_params' => $payload['parent_table']['data_params'] ?? [],
                        'use_soft_delete' => $parentSoftDelete,
                    ]
                );
            } elseif ($config->parentTable) {
                $config->parentTable()->delete();
                $parentTable = null;
            }
        }

        if (array_key_exists('child_tables', $payload)) {
            $existingChildren = $config->childTables;
            $config->childTables()->delete();

            if (is_array($payload['child_tables']) && $payload['child_tables'] !== []) {
                $children = [];
                $parentId = $parentTable?->id ?? $config->parentTable?->id ?? 0;

                foreach ($payload['child_tables'] as $childTable) {
                    if (! is_array($childTable)) {
                        continue;
                    }

                    $childTableName = (string) ($childTable['table_name'] ?? '');
                    $existingChild = $existingChildren
                        ? $existingChildren->firstWhere('table_name', $childTableName)
                        : null;
                    $childSoftDelete = array_key_exists('use_soft_delete', $childTable)
                        ? (bool) $childTable['use_soft_delete']
                        : ($existingChild?->use_soft_delete ?? $this->tableHasDeletedAt($childTableName));

                    $children[] = new ApiTable([
                        'parent_id' => $parentId,
                        'table_name' => $childTableName,
                        'foreign_key' => $childTable['foreign_key'] ?? null,
                        'data_params' => $childTable['data_params'] ?? [],
                        'use_soft_delete' => $childSoftDelete,
                    ]);
                }

                if ($children !== []) {
                    $config->childTables()->saveMany($children);
                }
            }
        }

        if (array_key_exists('permission', $payload)) {
            $config->permission()->delete();

            if (is_array($payload['permission']) && ! empty($payload['permission']['permission_string'] ?? null)) {
                $config->permission()->create([
                    'permission_string' => $payload['permission']['permission_string'],
                ]);
            } elseif (is_string($payload['permission']) && trim($payload['permission']) !== '') {
                $config->permission()->create([
                    'permission_string' => trim($payload['permission']),
                ]);
            }
        }

        if (array_key_exists('hook', $payload)) {
            $config->hook()->delete();

            if (is_array($payload['hook']) && ! empty($payload['hook'])) {
                $config->hook()->create([
                    'action_type' => $payload['hook']['action_type'] ?? null,
                    'listener_class' => $payload['hook']['listener_class'] ?? $this->resolveListenerClassFromPayload($payload, null, $config),
                ]);
            } else {
                $this->syncAfterHitHook(
                    $config,
                    $this->resolveListenerClassFromPayload($payload, null, $config),
                    $this->normalizeGenerateListener($payload['generate_listener'] ?? null)
                );
            }
        } elseif (! $config->hook) {
            $this->syncAfterHitHook(
                $config,
                $this->resolveListenerClassFromPayload($payload, null, $config),
                $this->normalizeGenerateListener($payload['generate_listener'] ?? null)
            );
        }

        if (array_key_exists('before_execute_hook', $payload)) {
            if (is_array($payload['before_execute_hook']) && ! empty($payload['before_execute_hook'])) {
                $config->hooks()->updateOrCreate(
                    ['action_type' => 'before_execute'],
                    [
                        'listener_class' => $payload['before_execute_hook']['listener_class']
                            ?? $this->resolveBeforeExecuteHookClassFromPayload($payload, null, $config),
                    ]
                );
            } else {
                $config->hooks()->where('action_type', 'before_execute')->delete();
            }
        } elseif (! $config->beforeExecuteHook) {
            $generateBeforeExecuteHook = $this->normalizeGenerateBeforeExecuteHook(
                $payload['generate_before_execute_hook'] ?? null
            );

            if ($generateBeforeExecuteHook) {
                $beforeExecuteHookClass = $this->resolveBeforeExecuteHookClassFromPayload($payload, null, $config);
                $config->hooks()->updateOrCreate(
                    ['action_type' => 'before_execute'],
                    ['listener_class' => $beforeExecuteHookClass]
                );
            }
        }
    }

    protected function serializeApiConfig(ApiConfig $config): array
    {
        return [
            'route_name' => $config->route_name,
            'endpoint' => $config->endpoint,
            'method' => $config->method,
            'description' => $config->description,
            'middlewares' => $config->middlewares,
            'params' => $config->params,
            'enabled' => (bool) $config->enabled,
            'generate_listener' => $config->hook
                ? strtolower((string) $config->hook->action_type) === 'after_hit_api'
                : true,
            'listener_path' => $config->hook?->listener_class,
            'generate_before_execute_hook' => $config->beforeExecuteHook
                ? strtolower((string) $config->beforeExecuteHook->action_type) === 'before_execute'
                : false,
            'before_execute_hook_path' => $config->beforeExecuteHook?->listener_class,
            'parent_table' => $config->parentTable ? $this->serializeApiTable($config->parentTable) : null,
            'child_tables' => $config->childTables->map(fn (ApiTable $table): array => $this->serializeApiTable($table))->values()->all(),
            'permission' => $config->permission ? [
                'permission_string' => $config->permission->permission_string,
            ] : null,
            'hook' => $config->hook ? [
                'action_type' => $config->hook->action_type,
                'listener_class' => $config->hook->listener_class,
                'listener_path' => $config->hook->listener_class,
            ] : null,
            'before_execute_hook' => $config->beforeExecuteHook ? [
                'action_type' => $config->beforeExecuteHook->action_type,
                'listener_class' => $config->beforeExecuteHook->listener_class,
                'listener_path' => $config->beforeExecuteHook->listener_class,
            ] : null,
        ];
    }

    protected function serializeApiTable(ApiTable $table): array
    {
        return [
            'table_name' => $table->table_name,
            'primary_key' => $table->primary_key,
            'foreign_key' => $table->foreign_key,
            'data_params' => $table->data_params,
            'use_soft_delete' => (bool) $table->use_soft_delete,
        ];
    }

    /**
     * Check whether a table exposes a deleted_at column.
     */
    protected function tableHasDeletedAt(string $tableName): bool
    {
        $tableName = trim($tableName);

        if ($tableName === '') {
            return false;
        }

        try {
            $columns = DatabaseConnection::schema()->getColumnListing($tableName);
        } catch (\Throwable $e) {
            return false;
        }

        return in_array('deleted_at', $columns, true);
    }

    /**
     * Validate the details of an API Builder request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    public function validateDetail($request, bool $forceDataMappings = false)
    {

        $validateParam = [
            'name' => 'required|string',
            'type' => ["required" , "string", "in:string,object,array,integer,date,boolean,numeric,url"],
            'required' => 'nullable|boolean',
            'unique' => 'nullable|boolean',
            'default' => ['nullable'],
            'params' =>  ['nullable', 'required_if:type,object,array', "array"]
          ];

          foreach ($request->params as $key => $value) {

              $validator = Validator::make($value, $validateParam);

              if ($validator->fails()) {
                  return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload params at row '.strval(intval($key)+1)], 400);
              }

              if (in_array($value['type'], ['array', 'object'])){
                foreach ($value['params'] as $key2 => $value2) {
                    $validatorChild = Validator::make($value2, $validateParam);
                    if ($validatorChild->fails()) {
                        return response()->json(['error'=>$validatorChild->errors(), 'message'=>'Invalid payload params at row '.strval(intval($key)+1).'->'.strval(intval($key2)+1)], 400);
                    }

                    if (in_array($value2['type'], ['array', 'object'])){

                       return response()->json(['error'=>'You cannot use both object and array as a type in an array parameter', 'message'=>'You cannot use both object and array as a type in an array parameter, at '.strval(intval($key)+1).'->'.strval(intval($key2)+1)], 400);
                    }
                }
             
              }
          }

          
          $validateParentTable = [
            'table_name' => 'required|string',
            'primary_key' => 'nullable|string'
          ];

          $validateChildTable = [
            'table_name' => 'required|string',
            'foreign_key' => 'required|string'
          ];
          

          if ($forceDataMappings) {
            $validateParentTable['data_params'] = 'required|array';
            $validateChildTable['data_params'] = 'required|array';
          } elseif(!in_array($request->method, ['DELETE', 'GET'], true)){
            $validateParentTable['data_params'] = 'required|array';
            $validateChildTable['data_params'] = 'required|array';
          }else{
            $validateParentTable['data_params'] = $request->method == 'GET' ? 'required|array' : 'nullable|array';
            $validateChildTable['data_params'] = 'nullable|array';

          }
          
          $validator = Validator::make($request->parent_table??[], $validateParentTable);

          if ($validator->fails()) {
                  return response()->json(['error'=>$validator->errors(), 'message'=>'In param parent_table, '.$validator->errors()->first()], 400);
          }


          
          foreach ($request->child_tables??[] as $key => $value) {

              $validator = Validator::make($value, $validateChildTable);

              if ($validator->fails()) {
                  return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload child_tables at row '.strval(intval($key)+1)], 400);
              }
          }


          return null;
      }

    /**
     * Validate runtime variable expressions used anywhere in the API Builder payload.
     *
     * @param mixed $payload
     * @param string $path
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function validateRuntimeVariables(mixed $payload, string $path = ''): ?\Illuminate\Http\JsonResponse
    {
        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                $validationError = $this->validateRuntimeVariables($value, $childPath);

                if ($validationError !== null) {
                    return $validationError;
                }
            }

            return null;
        }

        if (is_object($payload)) {
            return $this->validateRuntimeVariables(get_object_vars($payload), $path);
        }

        if (! is_string($payload) || ! str_contains($payload, '{{')) {
            return null;
        }

        try {
            $this->runtimeVariableParser->parse($payload);
        } catch (InvalidRuntimeVariableException $e) {
            return response()->json([
                'status' => 422,
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
                'field' => $path !== '' ? $path : null,
            ], 422);
        }

        return null;
    }

    /**
     * Store a new API Builder configuration.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    
  public function store(Request $request)
  {
     $request->merge([
        'method' => strtoupper((string) $request->input('method')),
        'endpoint' => $this->resolver->normalizeEndpoint($request->input('endpoint')),
        'route_name' => $request->filled('route_name')
            ? $request->input('route_name')
            : $this->buildRouteName($request->input('endpoint'), $request->input('method')),
     ]);

     $eventClass = "App\\Events\\AfterRunnerApiBuiderEvent";

     if (!class_exists($eventClass)) {
         Artisan::call('make:event AfterRunnerApiBuiderEvent');
     } 

    $validated = $request->validate([
      'route_name' => ['required', 'string', Rule::unique(DatabaseConnection::validationTable('api_configs'), 'route_name')],
      'endpoint' => [
          'required',
          'string',
          function (string $attribute, mixed $value, \Closure $fail): void {
              if ($this->resolver->isReservedEndpoint((string) $value)) {
                  $fail('The endpoint conflicts with a reserved package route.');
              }
          },
          Rule::unique(DatabaseConnection::validationTable('api_configs'), 'endpoint')->where(
              fn ($query) => $query->where('method', strtoupper((string) $request->input('method')))
          ),
      ],
      'method' => ["required" , "string", "in:GET,POST,PUT,DELETE"],
      'params' => ['nullable', 'required_if:method,PUT,POST', "array"],
      'parent_table' => 'required|array',
      'parent_table.use_soft_delete' => 'nullable|boolean',
      'child_tables' => 'nullable|array',
      'child_tables.*.use_soft_delete' => 'nullable|boolean',
      'enabled' => 'nullable|boolean',
      'description' => 'nullable|string',
      'middlewares' => 'nullable|array',
      'middlewares.*' => 'nullable|string',
      'use_default_middlewares' => 'nullable|boolean',
      'validation_rules' => 'nullable|string',
      'generate_listener' => 'nullable|boolean',
      'listener_path' => 'nullable|string',
      'generate_before_execute_hook' => 'nullable|boolean',
      'before_execute_hook_path' => 'nullable|string',
    ]);


    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }

    if ($runtimeValidation = $this->validateRuntimeVariables($request->all())) {
       return $runtimeValidation;
    }


        try {

            $connection = DatabaseConnection::connection();
            $connection->beginTransaction();
            $dataApiBuilder = ApiConfig::create([
              'route_name' => $validated['route_name'],
              'endpoint' => $validated['endpoint'],
              'method' => $validated['method'],
              'params' => ($validated['params'] ?? []),
              'enabled' => array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : true,
              'description' => $validated['description'] ?? null,
              'middlewares' => $this->resolveCreateMiddlewares(
                  $validated['middlewares'] ?? null,
                  $validated['use_default_middlewares'] ?? true
              ),
            ]);

            $parentTable = new ApiTable([
                'parent_id' => 0,
                'table_name' => $validated['parent_table']['table_name'],
                'primary_key' => $validated['parent_table']['primary_key']??'id',
                'data_params' => ($validated['parent_table']['data_params']??[]),
                'use_soft_delete' => array_key_exists('use_soft_delete', $validated['parent_table'])
                    ? (bool) $validated['parent_table']['use_soft_delete']
                    : $this->tableHasDeletedAt($validated['parent_table']['table_name']),
            ]);

            $dataApiBuilder->parentTable()->save($parentTable);

            $dataChildTable = [];
            foreach ($validated['child_tables']??[] as $key => $value) {
              $dataChild = new ApiTable([
                              'parent_id' => $dataApiBuilder->parentTable->id,
                              'table_name' => $value['table_name'],
                              'foreign_key' => $value['foreign_key'],
                              'data_params' => ($value['data_params']??[]),
                              'use_soft_delete' => array_key_exists('use_soft_delete', $value)
                                  ? (bool) $value['use_soft_delete']
                                  : $this->tableHasDeletedAt($value['table_name']),
                          ]);

              $dataChildTable[] = $dataChild;
            }
          // dd($validated['child_tables']);
            if(count($dataChildTable) > 0){
              $dataApiBuilder->childTables()->saveMany($dataChildTable);

            }

            $listenerName = $this->getListenerName($validated['route_name'], 1);
            $generateListener = $this->normalizeGenerateListener($validated['generate_listener'] ?? null);
            $defaultListenerClass = "App\\Listeners\\{$listenerName}";
            $listenerClass = $this->resolveListenerClassFromPayload($validated, $defaultListenerClass);

            if ($generateListener && $listenerClass === $defaultListenerClass) {
                $this->ensureAfterHitListener($listenerName, true);
            } elseif ($generateListener && ! class_exists($listenerClass)) {
                return response()->json([
                    'status' => 422,
                    'error' => 'Listener class not found',
                    'message' => 'Listener class not found',
                ], 422);
            }

            $this->syncAfterHitHook($dataApiBuilder, $listenerClass, $generateListener);

            $beforeExecuteHookName = $this->getBeforeExecuteHookName($validated['route_name']);
            $generateBeforeExecuteHook = $this->normalizeGenerateBeforeExecuteHook(
                $validated['generate_before_execute_hook'] ?? null
            );
            $defaultBeforeExecuteHookClass = 'App\\Hooks\\Api\\' . $beforeExecuteHookName;
            $beforeExecuteHookClass = $this->resolveBeforeExecuteHookClassFromPayload(
                $validated,
                $defaultBeforeExecuteHookClass,
                $dataApiBuilder
            );

            if ($generateBeforeExecuteHook && $beforeExecuteHookClass === $defaultBeforeExecuteHookClass) {
                $this->ensureBeforeExecuteHook($beforeExecuteHookName, true);
            } elseif ($generateBeforeExecuteHook && ! class_exists($beforeExecuteHookClass)) {
                return response()->json([
                    'status' => 422,
                    'error' => 'Before execute hook class not found',
                    'message' => 'Before execute hook class not found',
                ], 422);
            }

            $this->syncBeforeExecuteHook(
                $dataApiBuilder,
                $beforeExecuteHookClass,
                $generateBeforeExecuteHook
            );

            $connection->commit();
              Cache::forget($this->cacheKey('list-api-configs'));
              $this->resolver->forget($validated['endpoint'], $validated['method']);
             return response()->json(["status" => 200, 'message' => 'Data api builder created', 'data'=>$dataApiBuilder], 201);
        } catch (\Exception $e) {

            $connection->rollBack();

            \Log::error("STORE API BUILDER=> " . $e->getMessage());
            \Log::error("STORE API BUILDER => " . (tenant()->id??'tenant not found'));
            \Log::error("STORE API BUILDER => " . $e->getTraceAsString());

            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
  }


    /**
     * Retrieve a specific API Builder configuration.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
  {

    $headers = $request->header('x-tenant');
    $dataApiBuilder = ApiConfig::on(DatabaseConnection::configuredName())
        ->with('parentTable', 'childTables', 'permission', 'hook', 'beforeExecuteHook')
        ->where('id', $id)
        ->first();
    if (empty($dataApiBuilder)) {
        $dataApiBuilder = ApiConfig::on(DatabaseConnection::configuredName())
            ->with('parentTable', 'childTables', 'permission', 'hook', 'beforeExecuteHook')
            ->where('code', $id)
            ->first();
    }
    if (empty($dataApiBuilder)) {
        return response()->json(['error' => 'Data api builder not found', 'message' => 'Data api builder not found'], 400);
    }


    return response()->json(["status" => 200, 'data'=>$dataApiBuilder], 200);
  }

    /**
     * Update an existing API Builder configuration.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    
  public function update(Request $request, $id)
  {
     $request->merge([
        'method' => strtoupper((string) $request->input('method')),
        'endpoint' => $this->resolver->normalizeEndpoint($request->input('endpoint')),
        'route_name' => $request->filled('route_name')
            ? $request->input('route_name')
            : $this->buildRouteName($request->input('endpoint'), $request->input('method')),
     ]);

     $eventClass = "App\\Events\\AfterRunnerApiBuiderEvent";

     if (!class_exists($eventClass)) {
         Artisan::call('make:event AfterRunnerApiBuiderEvent');
     } 
    $dataApiBuilder = ApiConfig::on(DatabaseConnection::configuredName())
        ->where('id', $id)
        ->first();
    if (empty($dataApiBuilder)) {
        return response()->json(['error' => 'Data api builder not found', 'message' => 'Data api builder not found'], 400);
    }
    $dataApiBuilder->loadMissing('parentTable', 'childTables');

    $originalEndpoint = $dataApiBuilder->endpoint;
    $originalMethod = $dataApiBuilder->method;

    $validated = $request->validate([
      'route_name' => ['required', 'string', Rule::unique(DatabaseConnection::validationTable('api_configs'), 'route_name')->ignore($dataApiBuilder->id)],
      'endpoint' => [
          'required',
          'string',
          function (string $attribute, mixed $value, \Closure $fail): void {
              if ($this->resolver->isReservedEndpoint((string) $value)) {
                  $fail('The endpoint conflicts with a reserved package route.');
              }
          },
          Rule::unique(DatabaseConnection::validationTable('api_configs'), 'endpoint')
              ->where(fn ($query) => $query->where('method', strtoupper((string) $request->input('method'))))
              ->ignore($dataApiBuilder->id),
      ],
      'method' => ["required" , "string", "in:GET,POST,PUT,DELETE"],
      'params' => ['nullable', 'required_if:method,PUT,POST', "array"],
      'parent_table' => 'required|array',
      'parent_table.use_soft_delete' => 'nullable|boolean',
      'child_tables' => 'nullable|array',
      'child_tables.*.use_soft_delete' => 'nullable|boolean',
      'enabled' => 'nullable|boolean',
      'description' => 'nullable|string',
      'middlewares' => 'nullable|array',
      'middlewares.*' => 'nullable|string',
      'use_default_middlewares' => 'nullable|boolean',
      'generate_listener' => 'nullable|boolean',
      'listener_path' => 'nullable|string',
      'generate_before_execute_hook' => 'nullable|boolean',
      'before_execute_hook_path' => 'nullable|string',
    ]);

    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }

    if ($runtimeValidation = $this->validateRuntimeVariables($request->all())) {
       return $runtimeValidation;
    }


        try {

            $connection = DatabaseConnection::connection();
            $connection->beginTransaction();
            $dataApiBuilder->update([
              'route_name' => $validated['route_name'],
              'endpoint' => $validated['endpoint'],
              'method' => $validated['method'],
              'params' => ($validated['params'] ?? []),
              'enabled' => array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : true,
              'description' => $validated['description'] ?? null,
              'middlewares' => $this->normalizeMiddlewares($validated['middlewares'] ?? null),
            ]);

            $parentTable = [
                'parent_id' => 0,
                'table_name' => $validated['parent_table']['table_name'],
                'primary_key' => $validated['parent_table']['primary_key']??'id',
                'data_params' => ($validated['parent_table']['data_params']??[]),
                'use_soft_delete' => array_key_exists('use_soft_delete', $validated['parent_table'])
                    ? (bool) $validated['parent_table']['use_soft_delete']
                    : ($dataApiBuilder->parentTable?->use_soft_delete ?? $this->tableHasDeletedAt($validated['parent_table']['table_name'])),
            ];

            $dataApiBuilder->parentTable()->update($parentTable);

            $dataChildTable = [];
            $existingChildren = $dataApiBuilder->childTables;
            foreach ($validated['child_tables']??[] as $key => $value) {
              $dataChild = new ApiTable([
                              'parent_id' => $dataApiBuilder->parentTable->id,
                              'table_name' => $value['table_name'],
                              'foreign_key' => $value['foreign_key'],
                              'data_params' => ($value['data_params']??[]),
                              'use_soft_delete' => array_key_exists('use_soft_delete', $value)
                                  ? (bool) $value['use_soft_delete']
                                  : (($existingChildren ? $existingChildren->firstWhere('table_name', $value['table_name'])?->use_soft_delete : null) ?? $this->tableHasDeletedAt($value['table_name'])),
                          ]);

              $dataChildTable[] = $dataChild;
            }
          
            $dataApiBuilder->childTables()->delete();
            
            if(count($dataChildTable) > 0){
              $dataApiBuilder->childTables()->saveMany($dataChildTable);

            }
            
            $listenerName = $this->getListenerName($validated['route_name'], 1);
            $generateListener = $this->normalizeGenerateListener($validated['generate_listener'] ?? null);
            $defaultListenerClass = "App\\Listeners\\{$listenerName}";
            $listenerClass = $this->resolveListenerClassFromPayload($validated, $defaultListenerClass, $dataApiBuilder);

            if ($generateListener && $listenerClass === $defaultListenerClass) {
                $this->ensureAfterHitListener($listenerName, true);
            } elseif ($generateListener && ! class_exists($listenerClass)) {
                return response()->json([
                    'status' => 422,
                    'error' => 'Listener class not found',
                    'message' => 'Listener class not found',
                ], 422);
            }

            $this->syncAfterHitHook($dataApiBuilder, $listenerClass, $generateListener);

            $beforeExecuteHookName = $this->getBeforeExecuteHookName($validated['route_name']);
            $generateBeforeExecuteHook = $this->normalizeGenerateBeforeExecuteHook(
                $validated['generate_before_execute_hook'] ?? null
            );
            $defaultBeforeExecuteHookClass = 'App\\Hooks\\Api\\' . $beforeExecuteHookName;
            $beforeExecuteHookClass = $this->resolveBeforeExecuteHookClassFromPayload(
                $validated,
                $defaultBeforeExecuteHookClass,
                $dataApiBuilder
            );

            if ($generateBeforeExecuteHook && $beforeExecuteHookClass === $defaultBeforeExecuteHookClass) {
                $this->ensureBeforeExecuteHook($beforeExecuteHookName, true);
            } elseif ($generateBeforeExecuteHook && ! class_exists($beforeExecuteHookClass)) {
                return response()->json([
                    'status' => 422,
                    'error' => 'Before execute hook class not found',
                    'message' => 'Before execute hook class not found',
                ], 422);
            }

            $this->syncBeforeExecuteHook(
                $dataApiBuilder,
                $beforeExecuteHookClass,
                $generateBeforeExecuteHook
            );


            $connection->commit();
            Cache::forget($this->cacheKey('list-api-configs'));
            $this->resolver->forget($originalEndpoint, $originalMethod);
            $this->resolver->forget($validated['endpoint'], $validated['method']);
            return response()->json(["status" => 200, 'message' => 'Data api builder updated', 'data'=>$dataApiBuilder], 200);
        } catch (\Exception $e) {

            $connection->rollBack();

            \Log::error("UPDATE API BUILDER=> " . $e->getMessage());
            \Log::error("UPDATE API BUILDER => " . (tenant()->id??'tenant not found'));
            \Log::error("UPDATE API BUILDER => " . $e->getTraceAsString());

            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
  
  }

  /**
   * Generate POST, PUT, and DELETE API configs from a single request payload.
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function bundleCrud(Request $request)
  {
     $request->merge([
        'method' => strtoupper((string) $request->input('method')),
        'endpoint' => $this->resolver->normalizeEndpoint($request->input('endpoint')),
        'route_name' => $request->filled('route_name')
            ? $request->input('route_name')
            : '',
     ]);

     $eventClass = "App\\Events\\AfterRunnerApiBuiderEvent";

     if (!class_exists($eventClass)) {
         Artisan::call('make:event AfterRunnerApiBuiderEvent');
     }

    $validated = $request->validate([
      'route_name' => ['nullable', 'string'],
      'endpoint' => [
          'required',
          'string',
          function (string $attribute, mixed $value, \Closure $fail): void {
              if ($this->resolver->isReservedEndpoint((string) $value)) {
                  $fail('The endpoint conflicts with a reserved package route.');
              }
          },
      ],
      'method' => ['nullable', 'string', 'in:POST,PUT,DELETE'],
      'params' => ['required', 'array'],
      'parent_table' => 'required|array',
      'parent_table.use_soft_delete' => 'nullable|boolean',
      'child_tables' => 'nullable|array',
      'child_tables.*.use_soft_delete' => 'nullable|boolean',
      'enabled' => 'nullable|boolean',
      'description' => 'nullable|string',
      'middlewares' => 'nullable|array',
      'middlewares.*' => 'nullable|string',
      'generate_listener' => 'nullable|boolean',
      'listener_path' => 'nullable|string',
      'generate_before_execute_hook' => 'nullable|boolean',
      'before_execute_hook_path' => 'nullable|string',
    ]);

    $invalid = $this->validateDetail($request, true);

    if (!empty($invalid)) {
       return $invalid;
    }

    if ($runtimeValidation = $this->validateRuntimeVariables($request->all())) {
       return $runtimeValidation;
    }

        $connection = DatabaseConnection::connection();
        $createdConfigs = [];

        try {
            $connection->beginTransaction();

            foreach (['POST', 'PUT', 'DELETE'] as $method) {
                $bundlePayload = $validated;
                $bundlePayload['method'] = $method;
                $bundlePayload['route_name'] = $this->buildCrudBundleRouteName(
                    $validated['route_name'] ?? null,
                    $bundlePayload['endpoint'],
                    $method
                );
                $bundlePayload['enabled'] = array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : true;
                $bundlePayload['description'] = $validated['description'] ?? null;
                $bundlePayload['middlewares'] = $this->resolveCreateMiddlewares(
                    $validated['middlewares'] ?? null,
                    $validated['use_default_middlewares'] ?? true
                );
                $bundlePayload['generate_listener'] = $this->normalizeGenerateListener($validated['generate_listener'] ?? null);
                $bundlePayload['listener_path'] = $this->normalizeListenerPath($validated['listener_path'] ?? null);
                $bundlePayload['generate_before_execute_hook'] = $this->normalizeGenerateBeforeExecuteHook(
                    $validated['generate_before_execute_hook'] ?? null
                );
                $bundlePayload['before_execute_hook_path'] = $this->normalizeBeforeExecuteHookPath(
                    $validated['before_execute_hook_path'] ?? null
                );

                $dataApiBuilder = ApiConfig::create([
                    'route_name' => $bundlePayload['route_name'],
                    'endpoint' => $bundlePayload['endpoint'],
                    'method' => $bundlePayload['method'],
                    'params' => ($bundlePayload['params'] ?? []),
                    'enabled' => (bool) $bundlePayload['enabled'],
                    'description' => $bundlePayload['description'] ?? null,
                    'middlewares' => $bundlePayload['middlewares'],
                ]);

                $this->syncApiConfigRelations($dataApiBuilder, $bundlePayload);

                $listenerName = $this->getListenerName($bundlePayload['route_name']);
                $defaultListenerClass = "App\\Listeners\\{$listenerName}";
                $listenerClass = $this->resolveListenerClassFromPayload($bundlePayload, $defaultListenerClass);

                if ($bundlePayload['generate_listener'] && $listenerClass === $defaultListenerClass) {
                    $this->ensureAfterHitListener($listenerName, true);
                } elseif ($bundlePayload['generate_listener'] && ! class_exists($listenerClass)) {
                    throw new \RuntimeException('Listener class not found');
                }

                $beforeExecuteHookName = $this->getBeforeExecuteHookName($bundlePayload['route_name']);
                $defaultBeforeExecuteHookClass = 'App\\Hooks\\Api\\' . $beforeExecuteHookName;
                $beforeExecuteHookClass = $this->resolveBeforeExecuteHookClassFromPayload(
                    $bundlePayload,
                    $defaultBeforeExecuteHookClass,
                    $dataApiBuilder
                );

                if ($bundlePayload['generate_before_execute_hook'] && $beforeExecuteHookClass === $defaultBeforeExecuteHookClass) {
                    $this->ensureBeforeExecuteHook($beforeExecuteHookName, true);
                } elseif ($bundlePayload['generate_before_execute_hook'] && ! class_exists($beforeExecuteHookClass)) {
                    throw new \RuntimeException('Before execute hook class not found');
                }

                $this->syncBeforeExecuteHook(
                    $dataApiBuilder,
                    $beforeExecuteHookClass,
                    $bundlePayload['generate_before_execute_hook']
                );

                $createdConfigs[] = $dataApiBuilder->load(['parentTable', 'childTables', 'permission', 'hook', 'beforeExecuteHook']);
                $this->resolver->forget($bundlePayload['endpoint'], $method);
            }

            $connection->commit();
            Cache::forget($this->cacheKey('list-api-configs'));

            return response()->json([
                "status" => 200,
                'message' => 'CRUD API bundle created',
                'data' => $createdConfigs,
            ], 201);
        } catch (\Exception $e) {
            $connection->rollBack();

            \Log::error("BUNDLE CRUD API BUILDER=> " . $e->getMessage());
            \Log::error("BUNDLE CRUD API BUILDER => " . (tenant()->id??'tenant not found'));
            \Log::error("BUNDLE CRUD API BUILDER => " . $e->getTraceAsString());

            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
  }

    /**
     * Delete an API Builder configuration.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */

      public function destroy(Request $request, $id)
      {
        $dataApiBuilder = ApiConfig::on(DatabaseConnection::configuredName())
            ->where('id', $id)
            ->first();
        if (empty($dataApiBuilder)) {
          return response()->json(['error' => 'Data api builder not found'], 400);
        }
      


        $headers = $request->header('x-tenant');
        $this->resolver->forget($dataApiBuilder->endpoint, $dataApiBuilder->method);
        $dataApiBuilder->delete();

        Cache::forget($this->cacheKey('list-api-configs'));
        return response()->json(['message' => 'Data api builder deleted']);
      }

      protected function cacheKey(string $key): string
      {
            return DatabaseConnection::cachePrefix($key);
      }

      /**
       * Load API builder configs with optional search filtering.
       *
       * @param Request $request
       * @return array<int, array<string, mixed>>
       */
      protected function loadApiConfigs(Request $request): array
      {
            return $this->applySearchFilter(
                ApiConfig::on(DatabaseConnection::configuredName())
                    ->with('parentTable', 'childTables', 'permission', 'hook', 'beforeExecuteHook')
                    ->orderBy('id'),
                $request,
                ['route_name', 'endpoint', 'description', 'method'],
                'api_configs'
            )->get()->toArray();
      }

      protected function buildRouteName(?string $endpoint, ?string $method): string
      {
            $endpoint = $this->resolver->normalizeEndpoint($endpoint);

            return 'generated-' . strtolower((string) $method) . '-' . Str::of($endpoint)
                ->replace('/', '-')
                ->replace('-', '-')
                ->snake()
                ->trim('-')
                ->value();
      }

      protected function buildCrudBundleRouteName(?string $routeName, ?string $endpoint, string $method): string
      {
            $normalizedRouteName = trim((string) $routeName);
            $methodSuffix = strtolower($method);

            if ($normalizedRouteName !== '') {
                $normalizedRouteName = preg_replace('/\.(post|put|delete)$/i', '', $normalizedRouteName) ?? $normalizedRouteName;

                return $normalizedRouteName . '.' . $methodSuffix;
            }

            return $this->buildRouteName($endpoint, $method);
      }

      protected function normalizeMiddlewares(?array $middlewares): ?array
      {
            if (empty($middlewares)) {
                return null;
            }

            $normalized = array_values(array_filter(
                array_map(static fn ($middleware) => is_string($middleware) ? trim($middleware) : '', $middlewares),
                static fn (string $middleware) => $middleware !== ''
            ));

            return $normalized === [] ? null : $normalized;
      }

      protected function resolveCreateMiddlewares(mixed $middlewares, mixed $useDefaultMiddlewares = true): ?array
      {
            $normalized = $this->normalizeMiddlewares(is_array($middlewares) ? $middlewares : null);

            if ($normalized !== null) {
                return $normalized;
            }

            if (! $this->normalizeUseDefaultMiddlewares($useDefaultMiddlewares)) {
                return null;
            }

            $defaultMiddlewares = $this->getDefaultApiMiddlewares();

            return $defaultMiddlewares === [] ? null : $defaultMiddlewares;
      }

      protected function getDefaultApiMiddlewares(): array
      {
            $defaultMiddlewares = config('datasources.default_api_middlewares', []);

            if (! is_array($defaultMiddlewares)) {
                return [];
            }

            return array_values(array_filter(
                array_map(static fn ($middleware) => is_string($middleware) ? trim($middleware) : '', $defaultMiddlewares),
                static fn (string $middleware) => $middleware !== ''
            ));
      }

      protected function normalizeUseDefaultMiddlewares(mixed $value): bool
      {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value === 1;
            }

            if (is_string($value)) {
                return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
            }

            return false;
      }

      protected function syncAfterHitHook(
            ApiConfig $config,
            ?string $listenerClass = null,
            bool $generateListener = true
      ): void
      {
            $listenerClass = $listenerClass ?? 'App\\Listeners\\' . $this->getListenerName($config->route_name);
            $actionType = $generateListener ? 'after_hit_api' : 'after_hit_api_disabled';

            $config->hook()->delete();
            $config->hook()->create([
                'action_type' => $actionType,
                'listener_class' => $listenerClass,
            ]);
      }

      protected function syncBeforeExecuteHook(
            ApiConfig $config,
            ?string $hookClass = null,
            bool $generateHook = true
      ): void
      {
            $existingHook = $config->beforeExecuteHook;

            if (! $generateHook) {
                if ($existingHook !== null) {
                    $existingHook->delete();
                }

                return;
            }

            $hookClass = $hookClass ?? 'App\\Hooks\\Api\\' . $this->getBeforeExecuteHookName($config->route_name);
            $config->hooks()->updateOrCreate(
                ['action_type' => 'before_execute'],
                ['listener_class' => $hookClass]
            );
      }

      protected function ensureBeforeExecuteHook(string $hookName, bool $generateHook): bool
      {
            if (! $generateHook) {
                return false;
            }

            $classPath = app_path('Hooks/Api/' . $hookName . '.php');

            if (File::exists($classPath)) {
                return true;
            }

            File::ensureDirectoryExists(dirname($classPath));

            $content = <<<PHP
<?php

namespace App\Hooks\Api;

use ESolution\DataSources\Exceptions\ApiHookException;
use ESolution\DataSources\Contracts\BeforeExecuteHookInterface;
use ESolution\DataSources\Models\ApiConfig;
use Illuminate\Http\Request;

class {$hookName} implements BeforeExecuteHookInterface
{
    public function handle(
        array &\$payload,
        ApiConfig \$apiConfig,
        Request \$request
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Example: Stop execution with custom error
        |--------------------------------------------------------------------------
        |
        | throw new ApiHookException(
        |     400,
        |     'Branch tidak valid'
        | );
        |
        */

        /*
        |--------------------------------------------------------------------------
        | Example: Return custom status and additional data
        |--------------------------------------------------------------------------
        |
        | throw new ApiHookException(
        |     409,
        |     'Stock tidak mencukupi',
        |     [
        |         'available_stock' => 10,
        |         'requested_stock' => 20,
        |     ]
        | );
        |
        */

        /*
        |--------------------------------------------------------------------------
        | Example: Modify payload before execution
        |--------------------------------------------------------------------------
        |
        | \$payload['created_by'] = auth()->id();
        |
        */
    }
}
PHP;

            File::put($classPath, $content);

            return true;
      }

      protected function normalizeGenerateBeforeExecuteHook(mixed $value): bool
      {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value === 1;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));

                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }

                if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                    return false;
                }
            }

            return true;
      }

      protected function normalizeBeforeExecuteHookPath(mixed $value): ?string
      {
            if (! is_string($value)) {
                return null;
            }

            $normalized = trim($value);

            return $normalized === '' ? null : $normalized;
      }

      protected function getBeforeExecuteHookName(string $routeName): string
      {
            $cleanString = preg_replace('/[^A-Za-z0-9]/', ' ', $routeName);
            $cleanString = ucwords((string) $cleanString);

            return 'BeforeExecute' . str_replace(' ', '', $cleanString) . 'Hook';
      }

      protected function resolveBeforeExecuteHookClassFromPayload(array $payload, ?string $defaultHookClass = null, ?ApiConfig $existingConfig = null): string
      {
            $hookPath = $this->normalizeBeforeExecuteHookPath($payload['before_execute_hook_path'] ?? null);

            if ($hookPath !== null) {
                return $hookPath;
            }

            if (isset($payload['before_execute_hook']) && is_array($payload['before_execute_hook'])) {
                $hookListener = $this->normalizeBeforeExecuteHookPath($payload['before_execute_hook']['listener_class'] ?? null);

                if ($hookListener !== null) {
                    return $hookListener;
                }
            }

            $existingListener = $existingConfig?->beforeExecuteHook?->listener_class;

            if (is_string($existingListener) && trim($existingListener) !== '') {
                return trim($existingListener);
            }

            if ($defaultHookClass !== null && trim($defaultHookClass) !== '') {
                return trim($defaultHookClass);
            }

            $routeName = trim((string) ($payload['route_name'] ?? $existingConfig?->route_name ?? ''));

            return 'App\\Hooks\\Api\\' . $this->getBeforeExecuteHookName($routeName);
      }

      protected function ensureAfterHitListener(string $listenerName, bool $generateListener): bool
      {
            if (! $generateListener) {
                return false;
            }

            $listenerClass = "App\\Listeners\\{$listenerName}";

            if (! class_exists($listenerClass)) {
                Artisan::call('make:listener '.$listenerName.' --event=AfterRunnerApiBuiderEvent');
            }

            return true;
      }

      protected function normalizeListenerPath(mixed $value): ?string
      {
            if (! is_string($value)) {
                return null;
            }

            $normalized = trim($value);

            return $normalized === '' ? null : $normalized;
      }

      protected function resolveListenerClassFromPayload(array $payload, ?string $defaultListenerClass = null, ?ApiConfig $existingConfig = null): string
      {
            $listenerPath = $this->normalizeListenerPath($payload['listener_path'] ?? null);

            if ($listenerPath !== null) {
                return $listenerPath;
            }

            if (isset($payload['hook']) && is_array($payload['hook'])) {
                $hookListener = $this->normalizeListenerPath($payload['hook']['listener_class'] ?? null);

                if ($hookListener !== null) {
                    return $hookListener;
                }
            }

            $existingListener = $existingConfig?->hook?->listener_class;

            if (is_string($existingListener) && trim($existingListener) !== '') {
                return trim($existingListener);
            }

            if ($defaultListenerClass !== null && trim($defaultListenerClass) !== '') {
                return trim($defaultListenerClass);
            }

            $routeName = trim((string) ($payload['route_name'] ?? $existingConfig?->route_name ?? ''));

            return 'App\\Listeners\\' . $this->getListenerName($routeName);
      }

      protected function normalizeGenerateListener(mixed $value): bool
      {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value === 1;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));

                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }

                if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                    return false;
                }
            }

            return true;
      }

      public function getListenerName($routeName)
      {

            $cleanString = preg_replace('/[^A-Za-z0-9]/', ' ', $routeName);
            $cleanString = ucwords($cleanString);
            $cleanString = 'AfterRun'. str_replace(" ","",$cleanString).'Listener';
            return $cleanString;
      }
}
