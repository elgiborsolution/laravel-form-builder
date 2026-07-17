<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Contracts\AfterExecuteHookInterface;
use ESolution\DataSources\Contracts\DataSourceBeforeExecuteHookInterface;
use ESolution\DataSources\Exceptions\ApiHookException;
use ESolution\DataSources\Models\ApiHook;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Models\DataSourceParameter;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\CustomQueryService;
use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Pipeline\Pipeline;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DataSourceController extends Controller
{
  use AppliesSearchFilter;

  private const ROUTE_PARAMETER_SEGMENT_PATTERN = '/^\{([a-zA-Z0-9_]+)\}$/';

  public function __construct(
    protected DataQueryService $dataQueryService,
    protected CustomQueryService $customQueryService,
    protected Pipeline $pipeline,
    protected ?DatabaseMetadataProvider $databaseMetadataProvider = null
  ) {
    $this->databaseMetadataProvider ??= new DatabaseMetadataProvider();
  }

  /**
  * Display List data source configuration
  *
  * @param Request $request
  *
  * @return \Illuminate\Http\JsonResponse
  */

  public function index(Request $request)
  {
    $connection = DatabaseConnection::configuredName();
    $data = $this->applyDatabaseScopeFilter(
      $this->applySearchFilter(
        DataSource::on($connection)->orderBy('id'),
        $request,
        ['code', 'name', 'description', 'table_name', 'custom_query'],
        'data_sources'
      ),
      $request,
      'data_sources'
    );

    if(!empty($request->page)){
      $paginator = $data->paginate(10);
      $this->attachDataSourceParameters($paginator->getCollection(), $connection);

      return $paginator;
    }else{
      $data = $data->get();
      $this->attachDataSourceParameters($data, $connection);
    }
    return response()->json(['data' => $data], 200);
  }

  /**
   * Export data source configurations as a pretty printed JSON file.
   *
   * @param Request $request
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   */
  public function export(Request $request)
  {
    $ids = $this->normalizeSelectedIds($request->input('ids', []));
    $columns = $this->exportableColumns();

    $query = $this->applySearchFilter(
        DataSource::with(['hooks'])->orderBy('id'),
        $request,
        ['code', 'name', 'description', 'table_name', 'custom_query'],
        'data_sources'
    );

    if (! empty($ids)) {
      $query->whereIn('id', $ids);
    }

    $payload = $query->get($columns)
      ->map(static function (DataSource $dataSource) use ($columns): array {
        $row = [];

        foreach ($columns as $column) {
          $row[$column] = $dataSource->getAttribute($column);
        }

        $row['generate_before_execute_hook'] = $dataSource->beforeExecuteHook !== null;
        $row['before_execute_hook_path'] = $dataSource->beforeExecuteHook?->listener_class;
        $row['generate_after_execute_hook'] = $dataSource->afterExecuteHook !== null;
        $row['after_execute_hook_path'] = $dataSource->afterExecuteHook?->listener_class;

        return $row;
      })
      ->values()
      ->all();

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
      return response()->json([
        'message' => 'Failed to generate export file.',
      ], 500);
    }

    $filename = 'data-sources-' . now()->format('Y-m-d_His') . '.json';

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
   * Import data source configurations from JSON payload or uploaded file.
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function import(Request $request)
  {
    Log::info('IMPORT START', ['payload' => $request->all()]);

    $rows = $request->input('rows');

    if (is_string($rows)) {
      try {
        $rows = json_decode($rows, true, 512, JSON_THROW_ON_ERROR);
      } catch (\JsonException $exception) {
        return response()->json([
          'message' => 'Invalid JSON format in rows payload',
        ], 422);
      }
    }

    if (! is_array($rows)) {
      return response()->json([
        'message' => 'Invalid import format: rows must be array',
      ], 422);
    }

    Log::info('ROWS COUNT', ['count' => count($rows ?? [])]);
    Log::info('ROWS DATA', ['rows' => $rows]);

    $validation = Validator::make(['rows' => $rows], [
      'rows' => ['required', 'array'],
      'rows.*' => ['required', 'array'],
    ]);

    if ($validation->fails()) {
      return response()->json([
        'message' => $validation->errors()->first('rows') ?: 'Invalid import format: rows must be array',
        'errors' => $validation->errors()->toArray(),
      ], 422);
    }

    $summary = [
      'selected' => count($rows),
      'imported' => 0,
      'skipped' => 0,
      'failed' => 0,
    ];

    $errors = [];
    $duplicateColumns = $this->duplicateColumns();
    $insertedCount = 0;
    $databaseScope = $this->resolveRequestDatabaseScope($request);

    try {
      if (empty($rows)) {
        return response()->json([
          'message' => 'No data to import',
        ], 422);
      }

      $connection = DatabaseConnection::connection();
      $connection->beginTransaction();

    foreach ($rows as $index => $row) {
        $rowNumber = $index + 1;

        Log::info('INSERT ROW', [
          'row_number' => $rowNumber,
          'row' => $row,
        ]);

        if (! is_array($row)) {
          $summary['failed']++;
          $errors[] = [
            'row' => $rowNumber,
            'message' => 'Row must be a JSON object.',
          ];
          continue;
        }

        $useCustomQuery = filter_var($row['use_custom_query'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $row['use_custom_query'] = $useCustomQuery;
        $row['columns'] = $this->normalizeColumnsInput($row['columns'] ?? []);
        $row['middlewares'] = $this->normalizeMiddlewaresInput($row['middlewares'] ?? null);
        $row['custom_parameters'] = $this->normalizeCustomParametersInput($row['custom_parameters'] ?? []);
        $row['generate_before_execute_hook'] = $this->normalizeGenerateBeforeExecuteHook($row['generate_before_execute_hook'] ?? false);
        $row['before_execute_hook_path'] = $this->normalizeBeforeExecuteHookPath($row['before_execute_hook_path'] ?? null);
        $row['generate_after_execute_hook'] = $this->normalizeGenerateAfterExecuteHook($row['generate_after_execute_hook'] ?? false);
        $row['after_execute_hook_path'] = $this->normalizeAfterExecuteHookPath($row['after_execute_hook_path'] ?? null);
        $importedDatabaseScope = (string) ($row['database_scope'] ?? 'central');
        $finalDatabaseScope = $databaseScope;

        Log::debug('DataSource import database_scope save', [
          'X-Tenant' => trim((string) $request->header('X-Tenant', '')),
          'Detected scope' => $databaseScope,
          'Imported record name' => (string) ($row['name'] ?? ''),
          'Imported database_scope' => $importedDatabaseScope,
          'Final database_scope before save' => $finalDatabaseScope,
        ]);

        $validated = Validator::make($row, [
          'name' => ['required', 'string'],
          'use_custom_query' => ['required', 'boolean'],
          'table_name' => [
            'nullable',
            Rule::requiredIf(fn () => ! $useCustomQuery),
            'string',
          ],
          'columns' => ['required', 'array'],
          'columns.*' => ['string'],
          'custom_query' => ['nullable', 'string'],
          'middlewares' => ['nullable', 'array'],
          'middlewares.*' => ['nullable', 'string'],
          'custom_parameters' => ['nullable', 'array'],
          'custom_parameters.*.name' => ['required', 'string'],
          'custom_parameters.*.type' => ['required', 'string', Rule::in(['string', 'integer', 'boolean', 'date', 'float'])],
          'custom_parameters.*.required' => ['nullable', 'boolean', 'integer'],
          'custom_parameters.*.default' => ['nullable'],
          'custom_parameters.*.description' => ['nullable', 'string'],
          'generate_before_execute_hook' => ['nullable', 'boolean'],
          'before_execute_hook_path' => ['nullable', 'string'],
          'generate_after_execute_hook' => ['nullable', 'boolean'],
          'after_execute_hook_path' => ['nullable', 'string'],
        ]);

        if ($validated->fails()) {
          $summary['failed']++;
          $errors[] = [
            'row' => $rowNumber,
            'message' => $validated->errors()->first(),
          ];
          continue;
        }

        if (! empty($row['custom_query']) && ! DataSource::validateQuery((string) $row['custom_query'])) {
          $summary['failed']++;
          $errors[] = [
            'row' => $rowNumber,
            'message' => 'Only SELECT queries are allowed.',
          ];
          continue;
        }

        if ($this->hasDuplicateDataSource($row, $duplicateColumns)) {
          $summary['skipped']++;
          continue;
        }

        $data = [
          'name' => $row['name'] ?? null,
          'table_name' => $row['table_name'] ?? '',
          'use_custom_query' => $useCustomQuery,
          'columns' => $row['columns'] ?? [],
          'custom_query' => $row['custom_query'] ?? null,
          'middlewares' => $row['middlewares'] ?? null,
          'custom_parameters' => $row['custom_parameters'] ?? [],
          'database_scope' => $finalDatabaseScope,
        ];

        try {
          $dataSource = DataSource::create($data);

          $beforeExecuteHookName = $this->getBeforeExecuteHookName((string) ($row['name'] ?? ''));
          $afterExecuteHookName = $this->getAfterExecuteHookName((string) ($row['name'] ?? ''));
          $defaultBeforeExecuteHookClass = 'App\\Hooks\\Api\\' . $beforeExecuteHookName;
          $defaultAfterExecuteHookClass = 'App\\Hooks\\Api\\' . $afterExecuteHookName;
          $beforeExecuteHookClass = $this->resolveBeforeExecuteHookClassFromPayload($row, $defaultBeforeExecuteHookClass, $dataSource);
          $afterExecuteHookClass = $this->resolveAfterExecuteHookClassFromPayload($row, $defaultAfterExecuteHookClass, $dataSource);

          if ($row['generate_before_execute_hook']) {
            if ($beforeExecuteHookClass === $defaultBeforeExecuteHookClass) {
              $this->ensureBeforeExecuteHook($beforeExecuteHookName, true);
            } elseif (! class_exists($beforeExecuteHookClass)) {
              throw new \RuntimeException('Before execute hook class not found');
            }
          }

          if ($row['generate_after_execute_hook']) {
            if ($afterExecuteHookClass === $defaultAfterExecuteHookClass) {
              $this->ensureAfterExecuteHook($afterExecuteHookName, true);
            } elseif (! class_exists($afterExecuteHookClass)) {
              throw new \RuntimeException('After execute hook class not found');
            }
          }

          $this->syncBeforeExecuteHook($dataSource, $beforeExecuteHookClass, $row['generate_before_execute_hook']);
          $this->syncAfterExecuteHook($dataSource, $afterExecuteHookClass, $row['generate_after_execute_hook']);

          if ($dataSource && $dataSource->exists) {
            $insertedCount++;
            $summary['imported']++;

            Log::debug('DataSource import database_scope save', [
              'X-Tenant' => trim((string) $request->header('X-Tenant', '')),
              'Detected scope' => $databaseScope,
              'Imported record name' => (string) ($row['name'] ?? ''),
              'Imported database_scope' => $importedDatabaseScope,
              'Final database_scope before save' => (string) ($dataSource->database_scope ?? ''),
            ]);
          } else {
            $summary['failed']++;
            $errors[] = [
              'row' => $rowNumber,
              'message' => 'Insert did not persist the record.',
            ];
          }
        } catch (\Throwable $exception) {
          $summary['failed']++;
          $errors[] = [
            'row' => $rowNumber,
            'message' => $exception->getMessage(),
          ];
          throw $exception;
        }
      }

      $connection->commit();
    } catch (\Throwable $exception) {
      $connection->rollBack();

      Log::error('IMPORT FAILED', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
      ]);

      return response()->json([
        'message' => 'Import failed',
        'error' => $exception->getMessage(),
      ], 500);
    }

    Log::info('IMPORT COMPLETE', [
      'selected' => $summary['selected'],
      'inserted' => $insertedCount,
      'imported' => $summary['imported'],
      'skipped' => $summary['skipped'],
      'failed' => $summary['failed'],
    ]);

    return response()->json([
      'message' => 'Import success',
      'selected' => $summary['selected'],
      'inserted' => $insertedCount,
      'imported' => $summary['imported'],
      'skipped' => $summary['skipped'],
      'failed' => $summary['failed'],
      'errors' => $errors,
    ], 200);
  }


  /**
  * Create new data source configuration
  *
  * @param Request $request
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function store(Request $request)
  {
    $request->merge([
      'use_custom_query' => filter_var($request->input('use_custom_query'), FILTER_VALIDATE_BOOLEAN),
      'use_soft_delete' => filter_var($request->input('use_soft_delete'), FILTER_VALIDATE_BOOLEAN),
      'columns' => $this->normalizeColumnsInput($request->input('columns')),
      'middlewares' => $this->normalizeMiddlewaresInput($request->input('middlewares')),
      'response_type' => $this->normalizeResponseType($request->input('response_type', 'array')),
      'custom_parameters' => $this->normalizeCustomParametersInput($request->input('custom_parameters')),
      'generate_before_execute_hook' => $this->normalizeGenerateBeforeExecuteHook($request->input('generate_before_execute_hook')),
      'before_execute_hook_path' => $this->normalizeBeforeExecuteHookPath($request->input('before_execute_hook_path')),
      'generate_after_execute_hook' => $this->normalizeGenerateAfterExecuteHook($request->input('generate_after_execute_hook')),
      'after_execute_hook_path' => $this->normalizeAfterExecuteHookPath($request->input('after_execute_hook_path')),
    ]);

    $databaseScope = $this->resolveRequestDatabaseScope($request);
    $databaseScopeBeforeSave = (string) $request->input('database_scope', 'central');
    Log::debug('DataSource database_scope save', [
      'X-Tenant' => trim((string) $request->header('X-Tenant', '')),
      'Detected scope' => $databaseScope,
      'database_scope before save' => $databaseScopeBeforeSave,
    ]);

    $validated = $request->validate([
      'use_custom_query' => 'required|boolean',
      'use_soft_delete' => ['nullable', 'boolean'],
      'name' => [
        'required',
        'string',
        'unique:' . DatabaseConnection::validationTable('data_sources') . ',name',
        function (string $attribute, mixed $value, \Closure $fail): void {
          $message = $this->validateRouteTemplate((string) $value);
          if ($message !== null) {
            $fail($message);
          }
        },
      ],
      'table_name' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return ! $request->boolean('use_custom_query');
        }),
        'string',
      ],
      'columns' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return ! $request->boolean('use_custom_query');
        }),
        'array',
      ],
      'columns.*' => ['string'],
      'parameters' => ['nullable', "array"],
      'filter_parameters' => ['nullable', 'array'],
      'middlewares' => ['nullable', 'array'],
      'middlewares.*' => ['nullable', 'string'],
      'response_type' => ['nullable', 'string', Rule::in(['array', 'object'])],
      'generate_before_execute_hook' => ['nullable', 'boolean'],
      'before_execute_hook_path' => ['nullable', 'string'],
      'generate_after_execute_hook' => ['nullable', 'boolean'],
      'after_execute_hook_path' => ['nullable', 'string'],
      'custom_parameters' => ['nullable', 'array'],
      'custom_parameters.*.name' => ['required', 'string'],
      'custom_parameters.*.type' => ['required', 'string', Rule::in(['string', 'integer', 'boolean', 'date', 'float'])],
      'custom_parameters.*.required' => ['nullable', 'boolean', 'integer'],
      'custom_parameters.*.default' => ['nullable'],
      'custom_parameters.*.description' => ['nullable', 'string'],
      'custom_parameters.*.unused' => ['nullable', 'boolean'],
      'database_scope' => ['nullable', 'string', Rule::in(['central', 'tenant'])],
      'custom_query' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return $request->boolean('use_custom_query');
        }),
        "string",
      ],
    ]);

    $dataParam = $this->validateDataSourceParameters(
      $this->resolveParameterPayload($request),
      (bool) $validated['use_custom_query']
    );

    if (isset($dataParam['error'])) {
      return response()->json([
        'error' => $dataParam['error'],
        'message' => $dataParam['error'],
      ], 422);
    }

    $dataParam = $dataParam['data'];
    $validated['columns'] = $validated['columns'] ?? [];
    $beforeExecuteHookName = $this->getBeforeExecuteHookName((string) $validated['name']);
    $afterExecuteHookName = $this->getAfterExecuteHookName((string) $validated['name']);
    $defaultBeforeExecuteHookClass = 'App\\Hooks\\Api\\' . $beforeExecuteHookName;
    $defaultAfterExecuteHookClass = 'App\\Hooks\\Api\\' . $afterExecuteHookName;
    $beforeExecuteHookClass = $this->resolveBeforeExecuteHookClassFromPayload($validated, $defaultBeforeExecuteHookClass);
    $afterExecuteHookClass = $this->resolveAfterExecuteHookClassFromPayload($validated, $defaultAfterExecuteHookClass);

    if ($validated['use_custom_query']) {
      $validated['custom_parameters'] = $this->syncCustomParametersInput(
        $validated['custom_parameters'] ?? [],
        (string) ($validated['custom_query'] ?? '')
      );

      $customQueryInspection = $this->inspectCustomQuery(
        $request,
        (string) ($validated['custom_query'] ?? ''),
        $validated['custom_parameters'] ?? []
      );

      if (! $customQueryInspection['valid']) {
        return response()->json([
          'error' => $customQueryInspection['message'],
          'message' => $customQueryInspection['message'],
          'valid' => false,
        ], 422);
      }

      $validated['columns'] = $customQueryInspection['columns'];

      $parameterColumns = $customQueryInspection['columns'];
      foreach ($dataParam as $parameter) {
        $columnName = (string) ($parameter['param_name'] ?? '');

        if ($columnName !== '' && ! in_array($columnName, $parameterColumns, true)) {
          return response()->json([
            'error' => "Unknown column '{$columnName}'",
            'message' => "Unknown column '{$columnName}'",
            'valid' => false,
          ], 422);
        }
      }
    }

    $validated['use_soft_delete'] = (bool) ($validated['use_soft_delete'] ?? false);
    if ((bool) $validated['use_custom_query']) {
      $validated['use_soft_delete'] = false;
    }
    $validated['database_scope'] = $databaseScope;

    $dataSource = DataSource::create([
      'name' => $validated['name'],
      'table_name' => $validated['table_name']??'',
      'use_custom_query' => $validated['use_custom_query'],
      'use_soft_delete' => $validated['use_soft_delete'],
      'columns' => $validated['columns'],
      'custom_query' => $validated['custom_query'] ?? null,
      'middlewares' => $validated['middlewares'] ?? null,
      'response_type' => $validated['response_type'] ?? 'array',
      'custom_parameters' => $validated['custom_parameters'] ?? [],
      'database_scope' => $validated['database_scope'],
    ]);

    if ($validated['generate_before_execute_hook'] ?? false) {
      if ($beforeExecuteHookClass === $defaultBeforeExecuteHookClass) {
        $this->ensureBeforeExecuteHook($beforeExecuteHookName, true);
      } elseif (! class_exists($beforeExecuteHookClass)) {
        return response()->json([
          'error' => 'Before execute hook class not found',
          'message' => 'Before execute hook class not found',
        ], 422);
      }
    }

    if ($validated['generate_after_execute_hook'] ?? false) {
      if ($afterExecuteHookClass === $defaultAfterExecuteHookClass) {
        $this->ensureAfterExecuteHook($afterExecuteHookName, true);
      } elseif (! class_exists($afterExecuteHookClass)) {
        return response()->json([
          'error' => 'After execute hook class not found',
          'message' => 'After execute hook class not found',
        ], 422);
      }
    }

    $this->syncBeforeExecuteHook(
      $dataSource,
      $beforeExecuteHookClass,
      (bool) ($validated['generate_before_execute_hook'] ?? false)
    );
    $this->syncAfterExecuteHook(
      $dataSource,
      $afterExecuteHookClass,
      (bool) ($validated['generate_after_execute_hook'] ?? false)
    );

    Log::debug('DataSource database_scope save', [
      'X-Tenant' => trim((string) $request->header('X-Tenant', '')),
      'Detected scope' => $databaseScope,
      'database_scope before save' => $databaseScopeBeforeSave,
      'database_scope after save' => (string) ($dataSource->database_scope ?? ''),
    ]);

    if(count($dataParam) > 0){
        $dataSource->parameters()->createMany($dataParam);
    }

    return response()->json($dataSource, 201);
  }

  /**
  * Show some data source configuration
  *
  * @param DataSource $id
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function show($id)
  {
    $dataSource = DataSource::with(['parameters', 'hooks'])->findOrFail($id);
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }

    $payload = $dataSource->toArray();
    $payload['filter_parameters'] = $payload['parameters'] ?? [];
    $payload['response_type'] = $this->normalizeResponseType($payload['response_type'] ?? 'array');
    $payload['custom_parameters'] = $this->normalizeCustomParametersInput($payload['custom_parameters'] ?? []);
    $payload['use_soft_delete'] = (bool) ($payload['use_soft_delete'] ?? false);
    $payload['generate_before_execute_hook'] = $dataSource->beforeExecuteHook !== null;
    $payload['before_execute_hook_path'] = $dataSource->beforeExecuteHook?->listener_class;
    $payload['generate_after_execute_hook'] = $dataSource->afterExecuteHook !== null;
    $payload['after_execute_hook_path'] = $dataSource->afterExecuteHook?->listener_class;
    $payload['before_execute_hook'] = $dataSource->beforeExecuteHook ? [
      'action_type' => $dataSource->beforeExecuteHook->action_type,
      'listener_class' => $dataSource->beforeExecuteHook->listener_class,
      'listener_path' => $dataSource->beforeExecuteHook->listener_class,
    ] : null;
    $payload['after_execute_hook'] = $dataSource->afterExecuteHook ? [
      'action_type' => $dataSource->afterExecuteHook->action_type,
      'listener_class' => $dataSource->afterExecuteHook->listener_class,
      'listener_path' => $dataSource->afterExecuteHook->listener_class,
    ] : null;

    return response()->json($payload);
  }

  /**
  * Update data source configuration
  *
  * @param Request $request, DataSource $id
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function update(Request $request, $id)
  {
    $dataSource = DataSource::findOrFail($id);
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }
    $request->merge([
      'use_custom_query' => filter_var($request->input('use_custom_query'), FILTER_VALIDATE_BOOLEAN),
      'use_soft_delete' => $request->has('use_soft_delete')
        ? filter_var($request->input('use_soft_delete'), FILTER_VALIDATE_BOOLEAN)
        : ($dataSource->use_soft_delete ?? false),
      'columns' => $this->normalizeColumnsInput($request->input('columns')),
      'middlewares' => $this->normalizeMiddlewaresInput($request->input('middlewares')),
      'response_type' => $this->normalizeResponseType($request->input('response_type', $dataSource->response_type ?? 'array')),
      'custom_parameters' => $this->normalizeCustomParametersInput($request->input('custom_parameters', $dataSource->custom_parameters ?? [])),
      'generate_before_execute_hook' => $this->normalizeGenerateBeforeExecuteHook(
        $request->input('generate_before_execute_hook', $dataSource->generate_before_execute_hook ?? false)
      ),
      'before_execute_hook_path' => $this->normalizeBeforeExecuteHookPath(
        $request->input('before_execute_hook_path', $dataSource->before_execute_hook_path ?? null)
      ),
      'generate_after_execute_hook' => $this->normalizeGenerateAfterExecuteHook(
        $request->input('generate_after_execute_hook', $dataSource->generate_after_execute_hook ?? false)
      ),
      'after_execute_hook_path' => $this->normalizeAfterExecuteHookPath(
        $request->input('after_execute_hook_path', $dataSource->after_execute_hook_path ?? null)
      ),
    ]);

    $databaseScope = $this->resolveRequestDatabaseScope($request);
    $databaseScopeBeforeSave = (string) ($dataSource->database_scope ?? 'central');
    Log::debug('DataSource database_scope save', [
      'X-Tenant' => trim((string) $request->header('X-Tenant', '')),
      'Detected scope' => $databaseScope,
      'database_scope before save' => $databaseScopeBeforeSave,
    ]);

    $validated = $request->validate([
      'name' => 'required|string|unique:' . DatabaseConnection::validationTable('data_sources') . ',name,'. $dataSource->id ,
      'use_custom_query' => 'boolean',
      'use_soft_delete' => ['nullable', 'boolean'],
      'response_type' => ['nullable', 'string', Rule::in(['array', 'object'])],
      'table_name' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return ! $request->boolean('use_custom_query');
        }),
        'string',
      ],
      'columns' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return ! $request->boolean('use_custom_query');
        }),
        'array',
      ],
      'columns.*' => ['string'],
      'parameters' => ['nullable', "array"],
      'filter_parameters' => ['nullable', 'array'],
      'custom_parameters' => ['nullable', 'array'],
      'custom_parameters.*.name' => ['required', 'string'],
      'custom_parameters.*.type' => ['required', 'string', Rule::in(['string', 'integer', 'boolean', 'date', 'float'])],
      'custom_parameters.*.required' => ['nullable', 'boolean', 'integer'],
      'custom_parameters.*.default' => ['nullable'],
      'custom_parameters.*.description' => ['nullable', 'string'],
      'custom_parameters.*.unused' => ['nullable', 'boolean'],
      'middlewares' => ['nullable', 'array'],
      'middlewares.*' => ['nullable', 'string'],
      'database_scope' => ['nullable', 'string', Rule::in(['central', 'tenant'])],
      'generate_before_execute_hook' => ['nullable', 'boolean'],
      'before_execute_hook_path' => ['nullable', 'string'],
      'generate_after_execute_hook' => ['nullable', 'boolean'],
      'after_execute_hook_path' => ['nullable', 'string'],
      'custom_query' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return $request->boolean('use_custom_query');
        }),
        "string",
      ],
    ]);

    $dataParam = $this->validateDataSourceParameters(
      $this->resolveParameterPayload($request),
      (bool) $validated['use_custom_query']
    );

    if (isset($dataParam['error'])) {
      return response()->json([
        'error' => $dataParam['error'],
        'message' => $dataParam['error'],
      ], 422);
    }

    $dataParam = $dataParam['data'];
    $validated['columns'] = $validated['columns'] ?? [];

    if ($validated['use_custom_query']) {
      $validated['custom_parameters'] = $this->syncCustomParametersInput(
        $validated['custom_parameters'] ?? [],
        (string) ($validated['custom_query'] ?? '')
      );

      $customQueryInspection = $this->inspectCustomQuery(
        $request,
        (string) ($validated['custom_query'] ?? ''),
        $validated['custom_parameters'] ?? []
      );

      if (! $customQueryInspection['valid']) {
        return response()->json([
          'error' => $customQueryInspection['message'],
          'message' => $customQueryInspection['message'],
          'valid' => false,
        ], 422);
      }

      $validated['columns'] = $customQueryInspection['columns'];

      $parameterColumns = $customQueryInspection['columns'];
      foreach ($dataParam as $parameter) {
        $columnName = (string) ($parameter['param_name'] ?? '');

        if ($columnName !== '' && ! in_array($columnName, $parameterColumns, true)) {
          return response()->json([
            'error' => "Unknown column '{$columnName}'",
            'message' => "Unknown column '{$columnName}'",
            'valid' => false,
          ], 422);
        }
      }
    }

    $validated['use_soft_delete'] = (bool) ($validated['use_soft_delete'] ?? $dataSource->use_soft_delete ?? false);
    if ((bool) $validated['use_custom_query']) {
      $validated['use_soft_delete'] = false;
    }
    $validated['database_scope'] = $databaseScope;
    $beforeExecuteHookName = $this->getBeforeExecuteHookName((string) $validated['name']);
    $afterExecuteHookName = $this->getAfterExecuteHookName((string) $validated['name']);
    $defaultBeforeExecuteHookClass = 'App\\Hooks\\Api\\' . $beforeExecuteHookName;
    $defaultAfterExecuteHookClass = 'App\\Hooks\\Api\\' . $afterExecuteHookName;
    $beforeExecuteHookClass = $this->resolveBeforeExecuteHookClassFromPayload($validated, $defaultBeforeExecuteHookClass, $dataSource);
    $afterExecuteHookClass = $this->resolveAfterExecuteHookClassFromPayload($validated, $defaultAfterExecuteHookClass, $dataSource);

    if ($validated['generate_before_execute_hook'] ?? $dataSource->generate_before_execute_hook ?? false) {
      if ($beforeExecuteHookClass === $defaultBeforeExecuteHookClass) {
        $this->ensureBeforeExecuteHook($beforeExecuteHookName, true);
      } elseif (! class_exists($beforeExecuteHookClass)) {
        return response()->json([
          'error' => 'Before execute hook class not found',
          'message' => 'Before execute hook class not found',
        ], 422);
      }
    }

    if ($validated['generate_after_execute_hook'] ?? $dataSource->generate_after_execute_hook ?? false) {
      if ($afterExecuteHookClass === $defaultAfterExecuteHookClass) {
        $this->ensureAfterExecuteHook($afterExecuteHookName, true);
      } elseif (! class_exists($afterExecuteHookClass)) {
        return response()->json([
          'error' => 'After execute hook class not found',
          'message' => 'After execute hook class not found',
        ], 422);
      }
    }


    $dataSource->update([
      'name' => $validated['name'],
      'table_name' => $validated['table_name']??'',
      'use_custom_query' => $validated['use_custom_query'],
      'use_soft_delete' => $validated['use_soft_delete'],
      'columns' => $validated['columns'],
      'custom_query' => $validated['custom_query'] ?? null,
      'middlewares' => $validated['middlewares'] ?? null,
      'response_type' => $validated['response_type'] ?? 'array',
      'custom_parameters' => $validated['custom_parameters'] ?? [],
      'database_scope' => $validated['database_scope'],
    ]);

    $this->syncBeforeExecuteHook(
      $dataSource,
      $beforeExecuteHookClass,
      (bool) ($validated['generate_before_execute_hook'] ?? $dataSource->generate_before_execute_hook ?? false)
    );
    $this->syncAfterExecuteHook(
      $dataSource,
      $afterExecuteHookClass,
      (bool) ($validated['generate_after_execute_hook'] ?? $dataSource->generate_after_execute_hook ?? false)
    );

    Log::debug('DataSource database_scope save', [
      'X-Tenant' => trim((string) $request->header('X-Tenant', '')),
      'Detected scope' => $databaseScope,
      'database_scope before save' => $databaseScopeBeforeSave,
      'database_scope after save' => (string) ($dataSource->database_scope ?? ''),
    ]);

    $dataSource->parameters()->delete();

    if(count($dataParam) > 0){
        $dataSource->parameters()->createMany($dataParam);
    }

    

    return response()->json($dataSource->load(['parameters']));
  }

  /**
   * Validate a custom query before save or manual verification.
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function validateQuery(Request $request)
  {
    return $this->runWithDatasourceTenantContext($request, function () use ($request) {
      $connectionName = $this->resolveExecutionConnectionNameFromRequest($request);
      $result = $this->customQueryService->validate(
        (string) $request->input('query', ''),
        $connectionName,
        $this->syncCustomParametersInput(
          $this->normalizeCustomParametersInput($request->input('custom_parameters', [])),
          (string) $request->input('query', '')
        )
      );

      return response()->json($result, $result['valid'] ? 200 : 422);
    });
  }

  /**
   * Extract column names from a custom query.
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function extractQueryColumns(Request $request)
  {
    return $this->runWithDatasourceTenantContext($request, function () use ($request) {
      $connectionName = $this->resolveExecutionConnectionNameFromRequest($request);
      $result = $this->customQueryService->extractColumns(
        (string) $request->input('query', ''),
        $connectionName,
        $this->syncCustomParametersInput(
          $this->normalizeCustomParametersInput($request->input('custom_parameters', [])),
          (string) $request->input('query', '')
        )
      );

      return response()->json($result, $result['valid'] ? 200 : 422);
    });
  }

  /**
  * Delete data source configuration
  *
  * @param DataSource $id
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function destroy($id)
  {
    $dataSource = DataSource::findOrFail($id);
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }
    $dataSource->parameters()->delete();

    DataSource::destroy($id);
    return response()->json(['message' => 'Data source deleted']);
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
   * Get exportable columns from the data sources table.
   *
   * @return array<int, string>
   */
  protected function exportableColumns(): array
  {
    $columns = DatabaseConnection::schema()->getColumnListing((new DataSource())->getTable());

    return array_values(array_filter(
      $columns,
      static fn (string $column): bool => ! in_array($column, ['id', 'created_at', 'updated_at'], true)
    ));
  }

  /**
   * Determine the duplicate detection columns for data source imports.
   *
   * @return array<int, string>
   */
  protected function duplicateColumns(): array
  {
    $columns = DatabaseConnection::schema()->getColumnListing((new DataSource())->getTable());

    if (in_array('slug', $columns, true)) {
      return ['slug'];
    }

    if (in_array('name', $columns, true)) {
      return ['name'];
    }

    return [];
  }

  /**
   * Check if the incoming row already exists.
   *
   * @param array<string, mixed> $row
   * @param array<int, string> $duplicateColumns
   * @return bool
   */
  protected function hasDuplicateDataSource(array $row, array $duplicateColumns): bool
  {
    if ($duplicateColumns === []) {
      return false;
    }

    $query = DataSource::query();

    foreach ($duplicateColumns as $column) {
      if (! array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
        return false;
      }

      $query->where($column, $row[$column]);
    }

    return $query->exists();
  }

  /**
   * Normalize columns input so the rest of the controller can work with arrays only.
   *
   * @param mixed $columns
   * @return array<int, mixed>
   */
  protected function normalizeColumnsInput(mixed $columns): array
  {
    if (is_string($columns)) {
      $decoded = json_decode($columns, true);
      $columns = is_array($decoded) ? $decoded : [];
    }

    if (! is_array($columns)) {
      return [];
    }

    return $columns;
  }

  /**
   * Normalize middleware input so the rest of the controller can work with arrays only.
   *
   * @param mixed $middlewares
   * @return array<int, string>|null
   */
  protected function normalizeMiddlewaresInput(mixed $middlewares): ?array
  {
    if (is_string($middlewares)) {
      $decoded = json_decode($middlewares, true);
      $middlewares = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $middlewares);
    }

    if (! is_array($middlewares)) {
      return [];
    }

    $normalized = array_values(array_filter(
      array_map(static fn ($middleware) => is_string($middleware) ? trim($middleware) : '', $middlewares),
      static fn (string $middleware) => $middleware !== ''
    ));

    return $normalized;
  }

  /**
   * Normalize custom parameter payloads into a predictable array.
   *
   * @param mixed $customParameters
   * @return array<int, array<string, mixed>>
   */
  protected function normalizeCustomParametersInput(mixed $customParameters): array
  {
    if (is_string($customParameters)) {
      $decoded = json_decode($customParameters, true);
      $customParameters = is_array($decoded) ? $decoded : [];
    }

    if (! is_array($customParameters)) {
      return [];
    }

    $normalized = [];

    foreach ($customParameters as $parameter) {
      if (! is_array($parameter)) {
        continue;
      }

      $name = trim((string) ($parameter['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      $type = strtolower(trim((string) ($parameter['type'] ?? 'string')));
      if (! in_array($type, ['string', 'integer', 'boolean', 'date', 'float'], true)) {
        $type = 'string';
      }

      $normalized[] = [
        'name' => $name,
        'type' => $type,
        'required' => $this->normalizeRequiredFlag($parameter['required'] ?? $parameter['is_required'] ?? 0),
        'default' => $parameter['default'] ?? $parameter['default_value'] ?? null,
        'description' => isset($parameter['description']) && is_string($parameter['description'])
          ? trim($parameter['description'])
          : '',
        'unused' => $this->normalizeRequiredFlag($parameter['unused'] ?? 0) === 1,
      ];
    }

    return array_values($normalized);
  }

  /**
   * Sync custom parameter metadata with placeholders found in query.
   *
   * @param array<int, array<string, mixed>> $customParameters
   * @param string $query
   * @return array<int, array<string, mixed>>
   */
  protected function syncCustomParametersInput(array $customParameters, string $query): array
  {
    return $this->customQueryService->syncCustomParameters($customParameters, $query);
  }

  /**
   * Validate parameter payloads and normalize them into a predictable array.
   *
   * @param mixed $parameters
   * @param bool $customQueryMode
   * @return array{data:array<int, array<string, mixed>>}|array{error:string}
   */
  protected function validateDataSourceParameters(mixed $parameters, bool $customQueryMode): array
  {
    if (! is_array($parameters)) {
      $parameters = [];
    }

    $validateParam = [
      'param_name' => 'required|string',
      'param_default_value' => 'nullable|string',
      'param_type' => ['required', 'string', 'in:string,integer,boolean,date,float'],
      'operator' => 'nullable|string|in:=,!=,>,<,>=,<=,LIKE,NOT LIKE,like,not like',
      'is_required' => 'nullable|integer',
    ];

    $dataParam = [];

    foreach ($parameters as $value) {
      if (! is_array($value)) {
        return ['error' => 'Invalid parameter payload.'];
      }

      $validator = Validator::make($value, $validateParam);

      if ($validator->fails()) {
        return ['error' => $validator->errors()->first()];
      }

      $dataParam[] = $value;
    }

    return ['data' => $dataParam];
  }

  /**
   * Resolve parameter rows from supported payload aliases.
   *
   * @param Request $request
   * @return array<int, array<string, mixed>>
   */
  protected function resolveParameterPayload(Request $request): array
  {
    $payload = $request->input('parameters');

    if (! is_array($payload)) {
      $payload = $request->input('filter_parameters');
    }

    if (! is_array($payload)) {
      $payload = $request->input('filters', []);
    }

    if (! is_array($payload)) {
      return [];
    }

    return array_values(array_filter(array_map(function (mixed $row): ?array {
      if (! is_array($row)) {
        return null;
      }

      $paramName = $row['param_name'] ?? $row['name'] ?? $row['field'] ?? $row['column'] ?? null;
      $paramDefaultValue = $row['param_default_value'] ?? $row['value'] ?? $row['param_value'] ?? null;
      $paramType = $row['param_type'] ?? $row['type'] ?? null;

      return [
        'param_name' => is_string($paramName) ? trim($paramName) : '',
        'param_default_value' => $paramDefaultValue,
        'param_type' => is_string($paramType) && trim($paramType) !== '' ? trim($paramType) : 'string',
        'operator' => $row['operator'] ?? $row['param_operation'] ?? '=',
        'is_required' => $this->normalizeRequiredFlag($row['is_required'] ?? 0),
      ];
    }, $payload), static fn (?array $row): bool => is_array($row) && (($row['param_name'] ?? '') !== '')));
  }

  /**
   * Normalize boolean-ish flags into database-safe integers.
   *
   * @param mixed $value
   * @return int
   */
  protected function normalizeRequiredFlag(mixed $value): int
  {
    if (is_bool($value)) {
      return $value ? 1 : 0;
    }

    if (is_numeric($value)) {
      return (int) ((int) $value !== 0);
    }

    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
  }

  /**
   * Inspect a custom query using the active tenant context when available.
   *
   * @param Request $request
   * @param string $query
   * @return array{valid:bool,columns:array<int, string>,message?:string}
   */
  protected function inspectCustomQuery(Request $request, string $query, array $customParameters = []): array
  {
    return $this->runWithDatasourceTenantContext($request, function () use ($request, $query, $customParameters) {
      $connectionName = $this->resolveExecutionConnectionNameFromRequest($request);
      return $this->customQueryService->validateAndExtract(
        $query,
        $connectionName,
        $this->syncCustomParametersInput(
          $this->normalizeCustomParametersInput($customParameters ?: $request->input('custom_parameters', [])),
          $query
        )
      );
    });
  }

  /**
   * Attach parameter records using the explicit datasource connection.
   *
   * @param Collection<int, DataSource> $dataSources
   * @param string $connection
   * @return void
   */
  protected function attachDataSourceParameters(Collection $dataSources, string $connection): void
  {
    if ($dataSources->isEmpty()) {
      return;
    }

    $ids = $dataSources->pluck('id')->filter()->values()->all();

    if ($ids === []) {
      return;
    }

    $parametersBySource = DataSourceParameter::on($connection)
      ->whereIn('data_source_id', $ids)
      ->get()
      ->groupBy('data_source_id');

    $dataSources->each(function (DataSource $dataSource) use ($parametersBySource): void {
      $dataSource->setRelation(
        'parameters',
        $parametersBySource->get($dataSource->id, collect())->values()
      );
    });
  }

  /**
   * Execute a callback inside the tenant database context when the request
   * provides an `x-tenant` header.
   *
   * This keeps the existing non-tenant behavior intact while ensuring the
   * Data Source lookup and execution flow run on the active tenant database.
   *
   * @template TReturn
   * @param Request $request
   * @param \Closure():TReturn $callback
   * @return mixed
   */
  protected function runWithDatasourceTenantContext(Request $request, \Closure $callback)
  {
    if ($request->attributes->get('datasources.connection_resolved') === true) {
      return $callback();
    }

    $tenantId = trim((string) $request->header('x-tenant'));

    if ($tenantId === '') {
      return $callback();
    }

    $tenantInitialized = false;

    try {
      if (function_exists('tenancy')) {
        try {
          tenancy()->initialize($tenantId);
          $tenantInitialized = true;
          $connection = DB::getDefaultConnection();

          if (! is_string($connection) || trim($connection) === '') {
            $connection = DatabaseConnection::configuredName();
          }

          $request->attributes->set('datasources.connection_name', trim($connection));
        } catch (\Throwable $e) {
          // If tenancy cannot be initialized, keep the package connection flow.
        }
      }

      return $callback();
    } finally {
      if ($tenantInitialized && function_exists('tenancy')) {
        try {
          tenancy()->end();
        } catch (\Throwable $e) {
          // Ignore teardown failures so the request can still complete.
        }
      }
    }
  }

  /**
   * Resolve the datasource execution connection for the current request.
   *
   * @param Request $request
   * @return string
   */
  protected function resolveExecutionConnectionNameFromRequest(Request $request): string
  {
    $connectionName = $request->attributes->get('datasources.connection_name');

    if (is_string($connectionName) && trim($connectionName) !== '') {
      return trim($connectionName);
    }

    return DatabaseConnection::configuredName();
  }

  protected function resolveRequestDatabaseScope(Request $request): string
  {
    $tenantId = trim((string) $request->header('X-Tenant', ''));

    return $tenantId !== '' ? 'tenant' : 'central';
  }

  /**
   * Resolve middleware definitions using Laravel router middleware aliases and groups.
   *
   * @param array<int, string> $middlewares
   * @return array<int, mixed>
   */
  protected function resolveMiddlewareDefinitions(array $middlewares): array
  {
    if (! app()->bound('router')) {
      return $middlewares;
    }

    $router = app('router');

    if (method_exists($router, 'resolveMiddleware')) {
      return $router->resolveMiddleware($middlewares);
    }

    return collect($middlewares)
      ->flatMap(fn ($middleware) => (array) $router->resolveMiddleware([$middleware]))
      ->all();
  }

  /**
  * Display a list of tables in the database
  *
  * @param Request $request, DataSource $id
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function listTables(Request $request)
  {
    return $this->runWithDatasourceTenantContext($request, function () use ($request) {
      $connectionName = $this->resolveExecutionConnectionNameFromRequest($request);
      $tableList = $this->databaseMetadataProvider->listTables($connectionName);

      return response()->json(['data' => $tableList]);
    });
  }

  /**
  * Display a list of columns in a table
  *
  * @param Request $request, String $table (table name)
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function listColumns(Request $request, $table)
  {
    return $this->runWithDatasourceTenantContext($request, function () use ($request, $table) {
      try {
        $connectionName = $this->resolveExecutionConnectionNameFromRequest($request);
        $columnList = $this->databaseMetadataProvider->listColumns((string) $table, $connectionName);
        $hasDeletedAt = $this->tableHasColumn((string) $table, 'deleted_at', $connectionName, $columnList);

        return response()->json([
          'data' => $columnList,
          'meta' => [
            'has_deleted_at' => $hasDeletedAt,
          ],
        ]);
      } catch (\Exception $e) {
        return response()->json(['error' => 'Table not found'], 404);
      }
    });
  }



  /**
  * Run a datasource command
  *
  * @param Request $request, String $id (name of datasource or route prefix)
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function executeQuery(Request $request, $id, ?string $routePath = null)
  {
    return $this->runWithDatasourceTenantContext($request, function () use ($request, $id, $routePath) {
      $dataSourceMatch = $this->resolveDataSourceForExecution((string) $id, $routePath);

      if ($dataSourceMatch === null) {
        return response()->json(['error' => 'Data source not found', 'message' => 'Data source not found'], 422);
      }

      [$dataSource, $routeParameters, $cacheKeySuffix] = $dataSourceMatch;

      $routeParameterNames = array_keys($routeParameters);
      $request->attributes->set('datasources.data_source_code', (string) $id);
      $request->attributes->set('datasources.route_pattern', (string) $dataSource->name);
      $request->attributes->set('datasources.detected_parameters', $routeParameterNames);
      $request->attributes->set('datasources.route_parameter_names', $routeParameterNames);

      Log::debug('DataSource route resolution', [
        'dataSourceCode' => (string) $id,
        'routePattern' => (string) $dataSource->name,
        'detectedParameters' => $routeParameters,
        'request_all' => $request->all(),
      ]);

      if ($routeParameters !== []) {
        $request->merge($routeParameters);
      }

      return $this->runDataSourceMiddlewarePipeline($request, $dataSource, function (Request $request) use ($dataSource, $cacheKeySuffix) {
        return $this->dataQueryService->executeForDataSource($request, $dataSource, 'data_source_q' . $cacheKeySuffix);
      });
    });
  }

  /**
   * Execute a datasource runtime request using the canonical shared runtime path.
   *
   * @param Request $request
   * @param string $dynamicPath
   * @return JsonResponse|null
   */
  public function executeRuntimeRequest(Request $request, string $dynamicPath): ?JsonResponse
  {
    $path = trim($dynamicPath, '/');

    if ($path === '') {
      return null;
    }

    $segments = explode('/', $path);
    $identifier = array_shift($segments);
    $routePath = $segments === [] ? null : implode('/', $segments);

    if ($identifier === null) {
      return null;
    }

    $dataSourceMatch = $this->resolveDataSourceForExecution((string) $identifier, $routePath);

    if ($dataSourceMatch === null) {
      return null;
    }

    [$dataSource] = $dataSourceMatch;

    if ($databaseScopeResponse = $this->validateDataSourceDatabaseScope($request, $dataSource)) {
      return $databaseScopeResponse;
    }

    return $this->executeQuery($request, (string) $identifier, $routePath);
  }

  protected function resolveDataSourceForExecution(string $identifier, ?string $routePath = null): ?array
  {
    $connection = DatabaseConnection::configuredName();

    if ($routePath === null || trim($routePath, '/') === '') {
      $exactMatch = DataSource::on($connection)->with(['parameters', 'hooks'])->where('name', $identifier)->first();

      if ($exactMatch) {
        return [$exactMatch, [], $identifier];
      }
    }

    $candidatePath = trim($identifier . '/' . trim((string) $routePath, '/'), '/');
    if ($candidatePath === '') {
      return null;
    }

    $cacheKeyPath = $candidatePath;

    if (str_starts_with($cacheKeyPath, 'data-source/')) {
      $cacheKeyPath = substr($cacheKeyPath, strlen('data-source/'));
    }

    $dataSources = DataSource::on($connection)->with(['parameters', 'hooks'])->get();

    foreach ($dataSources as $dataSource) {
      [$matched, $routeParameters] = $this->matchRouteTemplate((string) $dataSource->name, $candidatePath);

      if ($matched) {
        return [$dataSource, $routeParameters, $this->sanitizeCacheKeySuffix($cacheKeyPath)];
      }
    }

    return null;
  }

  protected function validateDataSourceDatabaseScope(Request $request, DataSource $dataSource): ?JsonResponse
  {
    $requestScope = $this->resolveRequestDatabaseScope($request);
    $configuredScope = trim((string) ($dataSource->database_scope ?? 'central'));

    if (! in_array($configuredScope, ['central', 'tenant'], true)) {
      $configuredScope = 'central';
    }

    Log::debug('DataSource database_scope validation', [
      'X-Tenant' => trim((string) $request->header('X-Tenant', '')),
      'request_scope' => $requestScope,
      'data_source' => (string) ($dataSource->name ?? ''),
      'database_scope' => $configuredScope,
      'validation_result' => $requestScope === $configuredScope ? 'PASSED' : 'FAILED',
    ]);

    if ($requestScope === $configuredScope) {
      return null;
    }

    return response()->json([
      'status' => 403,
      'error' => 'Data source access denied',
      'message' => 'This data source cannot be accessed from the current database scope.',
    ], 403);
  }

  protected function matchRouteTemplate(string $template, string $path): array
  {
    $template = trim($template, '/');
    $path = trim($path, '/');

    if ($template === '' || $path === '') {
      return [false, []];
    }

    $routeParameters = $this->extractRouteTemplateParameters($template);

    if ($routeParameters === []) {
      return [$template === $path, []];
    }

    $segments = explode('/', $template);
    $pathSegments = explode('/', $path);

    if (count($segments) !== count($pathSegments)) {
      return [false, []];
    }

    $parameters = [];

    foreach ($segments as $index => $segment) {
      $pathSegment = $pathSegments[$index] ?? '';

      if ($segment === '') {
        return [false, []];
      }

      if (preg_match(self::ROUTE_PARAMETER_SEGMENT_PATTERN, $segment, $matches) === 1) {
        $parameters[$matches[1]] = $pathSegment;
        continue;
      }

      if ($segment !== $pathSegment) {
        return [false, []];
      }
    }

    return [true, $parameters];
  }

  protected function extractRouteTemplateParameters(string $template): array
  {
    $template = trim($template, '/');

    if ($template === '') {
      return [];
    }

    $segments = explode('/', $template);
    $parameters = [];

    foreach ($segments as $segment) {
      if (preg_match(self::ROUTE_PARAMETER_SEGMENT_PATTERN, $segment, $matches) === 1) {
        $parameters[] = $matches[1];
      }
    }

    return array_values(array_unique($parameters));
  }

  protected function validateRouteTemplate(string $template): ?string
  {
    $template = trim($template, '/');

    if ($template === '') {
      return 'The name field must contain a valid route pattern.';
    }

    $segments = explode('/', $template);
    $routeParameters = $this->extractRouteTemplateParameters($template);

    if ($routeParameters === []) {
      if (str_contains($template, '{') || str_contains($template, '}')) {
        return 'The name field must contain balanced route parameters like {id}.';
      }

      return null;
    }

    if (preg_match(self::ROUTE_PARAMETER_SEGMENT_PATTERN, $segments[0] ?? '') === 1) {
      return 'Route URL must contain a fixed path prefix before route parameters.';
    }

    foreach ($segments as $segment) {
      if ($segment === '') {
        return 'The name field must contain a valid route pattern.';
      }

      if (str_contains($segment, '{') || str_contains($segment, '}')) {
        if (preg_match(self::ROUTE_PARAMETER_SEGMENT_PATTERN, $segment) !== 1) {
          return 'The name field must contain balanced route parameters like {id}.';
        }
      }
    }

    return null;
  }

  protected function normalizeResponseType(mixed $value): string
  {
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['array', 'object'], true) ? $normalized : 'array';
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

      return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    return false;
  }

  protected function normalizeGenerateAfterExecuteHook(mixed $value): bool
  {
    return $this->normalizeGenerateBeforeExecuteHook($value);
  }

  protected function normalizeBeforeExecuteHookPath(mixed $value): ?string
  {
    if (! is_string($value)) {
      return null;
    }

    $normalized = trim($value);

    return $normalized === '' ? null : $normalized;
  }

  protected function normalizeAfterExecuteHookPath(mixed $value): ?string
  {
    return $this->normalizeBeforeExecuteHookPath($value);
  }

  protected function getBeforeExecuteHookName(string $routeName): string
  {
    $cleanString = preg_replace('/[^A-Za-z0-9]/', ' ', $routeName);
    $cleanString = ucwords((string) $cleanString);

    return 'BeforeExecute' . str_replace(' ', '', $cleanString) . 'Hook';
  }

  protected function getAfterExecuteHookName(string $routeName): string
  {
    $cleanString = preg_replace('/[^A-Za-z0-9]/', ' ', $routeName);
    $cleanString = ucwords((string) $cleanString);

    return 'AfterExecute' . str_replace(' ', '', $cleanString) . 'Hook';
  }

  protected function resolveBeforeExecuteHookClassFromPayload(array $payload, ?string $defaultHookClass = null, ?DataSource $existingConfig = null): ?string
  {
    $hookPath = $this->normalizeBeforeExecuteHookPath($payload['before_execute_hook_path'] ?? null);

    if ($hookPath !== null) {
      return $hookPath;
    }

    if (isset($payload['before_execute_hook']) && is_array($payload['before_execute_hook'])) {
      $listener = $this->normalizeBeforeExecuteHookPath($payload['before_execute_hook']['listener_class'] ?? null);

      if ($listener !== null) {
        return $listener;
      }
    }

    $existing = $existingConfig?->beforeExecuteHook?->listener_class;

    if (is_string($existing) && trim($existing) !== '') {
      return trim($existing);
    }

    if ($defaultHookClass !== null && trim($defaultHookClass) !== '') {
      return trim($defaultHookClass);
    }

    return null;
  }

  protected function resolveAfterExecuteHookClassFromPayload(array $payload, ?string $defaultHookClass = null, ?DataSource $existingConfig = null): ?string
  {
    $hookPath = $this->normalizeAfterExecuteHookPath($payload['after_execute_hook_path'] ?? null);

    if ($hookPath !== null) {
      return $hookPath;
    }

    if (isset($payload['after_execute_hook']) && is_array($payload['after_execute_hook'])) {
      $listener = $this->normalizeAfterExecuteHookPath($payload['after_execute_hook']['listener_class'] ?? null);

      if ($listener !== null) {
        return $listener;
      }
    }

    $existing = $existingConfig?->afterExecuteHook?->listener_class;

    if (is_string($existing) && trim($existing) !== '') {
      return trim($existing);
    }

    if ($defaultHookClass !== null && trim($defaultHookClass) !== '') {
      return trim($defaultHookClass);
    }

    return null;
  }

  protected function syncBeforeExecuteHook(
    DataSource $config,
    ?string $hookClass = null,
    bool $generateHook = true
  ): void {
    $existingHook = $config->beforeExecuteHook;

    if (! $generateHook) {
      if ($existingHook !== null) {
        $existingHook->delete();
      }

      return;
    }

    $hookClass = $hookClass ?? 'App\\Hooks\\Api\\' . $this->getBeforeExecuteHookName($config->name);
    $config->hooks()->updateOrCreate(
      ['action_type' => 'before_execute'],
      ['listener_class' => $hookClass]
    );
  }

  protected function syncAfterExecuteHook(
    DataSource $config,
    ?string $hookClass = null,
    bool $generateHook = true
  ): void {
    $existingHook = $config->afterExecuteHook;

    if (! $generateHook) {
      if ($existingHook !== null) {
        $existingHook->delete();
      }

      return;
    }

    $hookClass = $hookClass ?? 'App\\Hooks\\Api\\' . $this->getAfterExecuteHookName($config->name);
    $config->hooks()->updateOrCreate(
      ['action_type' => 'after_execute'],
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

use ESolution\DataSources\Contracts\DataSourceBeforeExecuteHookInterface;
use ESolution\DataSources\Exceptions\ApiHookException;
use ESolution\DataSources\Models\DataSource;
use Illuminate\Http\Request;

class {$hookName} implements DataSourceBeforeExecuteHookInterface
{
    public function handle(
        array &\$payload,
        DataSource \$dataSource,
        Request \$request
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Example: Stop execution with a business exception
        |--------------------------------------------------------------------------
        |
        | throw new ApiHookException(422, 'Invalid branch');
        |
        */

        /*
        |--------------------------------------------------------------------------
        | Example: Mutate the request payload before execution
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

  protected function ensureAfterExecuteHook(string $hookName, bool $generateHook): bool
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

use ESolution\DataSources\Contracts\AfterExecuteHookInterface;
use ESolution\DataSources\Models\DataSource;
use Illuminate\Http\Request;

class {$hookName} implements AfterExecuteHookInterface
{
    public function handle(
        Request \$request,
        DataSource \$dataSource,
        mixed \$data
    ): mixed {
        /*
        |--------------------------------------------------------------------------
        | Example: Transform only the data payload
        |--------------------------------------------------------------------------
        |
        | return collect(\$data)->map(fn (\$row) => array_merge((array) \$row, ['label' => strtoupper((string) (\$row['label'] ?? ''))]))->all();
        |
        */

        return \$data;
    }
}
PHP;

    File::put($classPath, $content);

    return true;
  }

  /**
   * Determine whether a table contains a given column.
   *
   * @param array<int, array<string, mixed>>|null $columns
   */
  protected function tableHasColumn(string $tableName, string $columnName, ?string $connectionName = null, ?array $columns = null): bool
  {
    try {
      $columns ??= $this->databaseMetadataProvider->listColumns($tableName, $connectionName);
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

  protected function sanitizeCacheKeySuffix(string $value): string
  {
    $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));

    return $normalized !== '' ? $normalized : 'default';
  }

  protected function runDataSourceMiddlewarePipeline(Request $request, DataSource $dataSource, \Closure $destination): mixed
  {
    $middlewares = array_values(array_filter($dataSource->middlewares ?? []));

    if ($middlewares === []) {
      return $destination($request);
    }

    $middlewares = $this->resolveMiddlewareDefinitions($middlewares);

    if ($middlewares === []) {
      return $destination($request);
    }

    return $this->pipeline
      ->send($request)
      ->through($middlewares)
      ->then(fn (Request $request) => $destination($request));
  }


  /**
  * Find column list in a custom query 
  *
  * @param Request $request
  *
  * @return Array
  */
  public function findAttributeSQlBuilder($request, $query)
  {
      return $this->runWithDatasourceTenantContext($request, function () use ($request, $query) {
        $connectionName = $this->resolveExecutionConnectionNameFromRequest($request);
        $result = $this->customQueryService->extractColumns(
          (string) $query,
          $connectionName,
          $this->syncCustomParametersInput(
            $this->normalizeCustomParametersInput($request->input('custom_parameters', [])),
            (string) $query
          )
        );

        if (! $result['valid']) {
          return [
            'error' => $result['message'],
            'columns' => [],
          ];
        }

        return [
          'columns' => $result['columns'],
        ];
      });
  }

}
