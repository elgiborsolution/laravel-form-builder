<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Contracts\BeforeExecuteHookInterface;
use ESolution\DataSources\Exceptions\ApiHookException;
use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Models\UploadConfig;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Support\UploadConfigResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pipeline\Pipeline;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    protected const DATA_BUILDER_MISSING = '__ESOLUTION_DATA_BUILDER_MISSING__';

    /**
     * Track whether the request is already running inside the datasource
     * validation connection scope.
     */
    protected bool $datasourceValidationConnectionActive = false;

    protected ?AfterHitApiDispatcher $afterHitApiDispatcher = null;
    protected ?DataSourceController $dataSourceController = null;
    protected ?UploadConfigResolver $uploadConfigResolver = null;
    protected ?DatabaseMetadataProvider $databaseMetadataProvider = null;

    /**
     * Keep track of the connection that should be used for runtime validation
     * so unique/exists rules follow the execution connection instead of the
     * package metadata connection.
     */
    protected ?string $runtimeValidationConnectionName = null;

    public function __construct(
        protected DynamicApiConfigResolver $resolver,
        protected DataQueryService $dataQueryService,
        protected Pipeline $pipeline,
        protected DynamicVariableParser $runtimeVariableParser,
        protected MiddlewareConnectionResolver $middlewareConnectionResolver,
        protected ExecutionConnectionResolver $executionConnectionResolver,
        ?AfterHitApiDispatcher $afterHitApiDispatcher = null,
        ?DataSourceController $dataSourceController = null,
        ?UploadConfigResolver $uploadConfigResolver = null,
        ?DatabaseMetadataProvider $databaseMetadataProvider = null
    ) {
        $this->uploadConfigResolver = $uploadConfigResolver;
        $this->afterHitApiDispatcher = $afterHitApiDispatcher;
        $this->dataSourceController = $dataSourceController;
        $this->databaseMetadataProvider = $databaseMetadataProvider;
    }

  /**
  * Handle API request based on the API configuration.
  *
  * @param Request $request
  * @param string $dynamicPath
  *
  * @return \Illuminate\Http\JsonResponse
  */
    public function handleRequest(Request $request, string $dynamicPath): JsonResponse
    {
        $headers = $request->header('x-tenant');

        if (!empty($headers)) {
            tenancy()->initialize($headers);
            $request->attributes->set('datasources.connection_name', DB::getDefaultConnection());
        }

        $previousValidationConnection = $this->runtimeValidationConnectionName;
        $this->runtimeValidationConnectionName = $this->executionConnectionResolver->resolve($request);

        try {
            if ($formBuilderResponse = $this->handleFormBuilderFallback($request, $dynamicPath)) {
                return $formBuilderResponse;
            }

            return $this->withDatasourceValidationConnection(function () use ($request, $dynamicPath) {
            if (($uploadResponse = $this->handleUploadBuilderFallback($request, $dynamicPath)) !== null) {
                return $uploadResponse;
            }

            $resolvedRoute = $this->resolver->resolve($dynamicPath, $request->method());
            /** @var ApiConfig|null $apiConfigs */
            $apiConfigs = $resolvedRoute['config'];
            $id = $resolvedRoute['id'];
            $action = $resolvedRoute['action'] ?? null;

            if (empty($apiConfigs)) {
                if ($request->isMethod('GET')) {
                    $dataSourceResponse = $this->handleDataSourceFallback($request, $dynamicPath);

                    if ($dataSourceResponse !== null) {
                        return $dataSourceResponse;
                    }
                }

                return response()->json(['status' => 404, 'error'=> 'API Builder tidak ditemukan', 'message'=>'API Builder tidak ditemukan'], 404);
            }

            return $this->runDynamicMiddlewarePipeline(
                $request,
                $apiConfigs,
                function (Request $request) use ($apiConfigs, $id, $action) {
                    $response = $this->dispatchResolvedRequest($request, $apiConfigs, $id, $action);
                    $this->dispatchAfterHitApiEvent($apiConfigs, $request, $response, $id);

                    return $response;
                }
            );
            });
        } catch (ApiHookException $exception) {
            return response()->json(
                $exception->toResponsePayload(),
                $exception->getStatusCode()
            );
        } finally {
            $this->runtimeValidationConnectionName = $previousValidationConnection;
        }
  }

  protected function handleDataSourceFallback(Request $request, string $dynamicPath): ?JsonResponse
  {
      $controller = $this->dataSourceController();

      return $controller?->executeRuntimeRequest($request, $dynamicPath);
  }

  protected function handleUploadBuilderFallback(Request $request, string $dynamicPath): ?JsonResponse
  {
      $path = trim($dynamicPath, '/');

      if ($path === '' || ! str_starts_with($path, 'upload/')) {
          return null;
      }

      if (! $request->isMethod('POST')) {
          return response()->json([
              'status' => 405,
              'error' => 'Method not allowed',
              'message' => 'Upload Builder only accepts POST requests',
          ], 405);
      }

      $uploadConfig = $this->uploadConfigResolver()->resolve($path);

      if ($uploadConfig === null) {
          return response()->json([
              'status' => 404,
              'error' => 'Upload Builder tidak ditemukan',
              'message' => 'Upload Builder tidak ditemukan',
          ], 404);
      }

      return $this->runDynamicUploadMiddlewarePipeline($request, $uploadConfig, function (Request $request) use ($uploadConfig) {
          return $this->handleUploadRequest($request, $uploadConfig);
      });
  }

  protected function handleUploadRequest(Request $request, UploadConfig $uploadConfig): JsonResponse
  {
      $maxFileSizeRules = $this->buildUploadMaxFileSizeRules($uploadConfig->max_file_size);
      $allowedExtensions = $this->normalizeUploadExtensions($uploadConfig->allowed_extensions ?? []);

      $rules = $uploadConfig->multiple
          ? [
              'files' => ['required', 'array', 'min:1'],
              'files.*' => array_values(array_filter(array_merge(
                  ['required', 'file'],
                  $allowedExtensions !== [] ? ['mimes:' . implode(',', $allowedExtensions)] : [],
                  $maxFileSizeRules,
              ))),
          ]
          : [
              'file' => array_values(array_filter(array_merge(
                  ['required', 'file'],
                  $allowedExtensions !== [] ? ['mimes:' . implode(',', $allowedExtensions)] : [],
                  $maxFileSizeRules,
              ))),
          ];

      $validator = Validator::make($request->all(), $rules);

      if ($validator->fails()) {
          return response()->json([
              'success' => false,
              'message' => $validator->errors()->first(),
              'errors' => $validator->errors()->toArray(),
          ], 422);
      }

      $storageDisk = $this->resolveUploadStorageDisk();
      $basePath = $this->resolveUploadBasePath($uploadConfig);

      if ($uploadConfig->multiple) {
          $paths = [];
          foreach ((array) $request->file('files', []) as $file) {
              if (! $file instanceof UploadedFile) {
                  continue;
              }

              $paths[] = ['path' => $this->storeUploadedFile($file, $storageDisk, $basePath)];
          }

          return response()->json([
              'success' => true,
              'message' => 'Upload success',
              'data' => $paths,
          ]);
      }

      $file = $request->file('file');

      if (! $file instanceof UploadedFile) {
          return response()->json([
              'success' => false,
              'message' => 'File is required',
          ], 422);
      }

      return response()->json([
          'success' => true,
          'message' => 'Upload success',
          'data' => [
              'path' => $this->storeUploadedFile($file, $storageDisk, $basePath),
              'disk' => $storageDisk,
          ],
      ]);
  }

  protected function dataSourceController(): ?DataSourceController
  {
      if ($this->dataSourceController instanceof DataSourceController) {
          return $this->dataSourceController;
      }

      try {
          $this->dataSourceController = app(DataSourceController::class);
      } catch (\Throwable $e) {
          return null;
      }

      return $this->dataSourceController;
  }

  protected function uploadConfigResolver(): UploadConfigResolver
  {
      if ($this->uploadConfigResolver instanceof UploadConfigResolver) {
          return $this->uploadConfigResolver;
      }

      return $this->uploadConfigResolver = app(UploadConfigResolver::class);
  }

  protected function runDynamicUploadMiddlewarePipeline(Request $request, UploadConfig $uploadConfig, \Closure $destination): JsonResponse
  {
      $middlewares = array_values(array_filter(array_merge(
          config('datasources.routes.dynamic.middleware', []),
          $uploadConfig->middlewares ?? []
      )));

      if ($middlewares === []) {
          return $destination($request);
      }

      $middlewares = $this->buildDynamicMiddlewarePipes($middlewares);

      return $this->pipeline
          ->send($request)
          ->through($middlewares)
          ->then(fn (Request $request) => $destination($request));
  }

  protected function handleFormBuilderFallback(Request $request, string $dynamicPath): ?JsonResponse
  {
      $path = trim($dynamicPath, '/');

      if ($path === '' || ! str_starts_with($path, 'form-builder')) {
          return null;
      }

      $segments = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));
      $controller = app(FormBuilderController::class);
      $method = strtoupper($request->method());

      if ($segments === ['form-builder']) {
          return match ($method) {
              'GET' => $controller->index($request),
              'POST' => $controller->store($request),
              default => null,
          };
      }

      if (($segments[0] ?? null) !== 'form-builder') {
          return null;
      }

      if (($segments[1] ?? null) === 'id' && isset($segments[2])) {
          return $method === 'GET'
              ? $controller->showById($request, $segments[2])
              : null;
      }

      if (count($segments) === 2) {
          return match ($method) {
              'GET' => $controller->showByCode($request, $segments[1]),
              'PUT' => $controller->update($request, $segments[1]),
              'DELETE' => $controller->destroy($request, $segments[1]),
              default => null,
          };
      }

      if (count($segments) === 3 && ($segments[2] ?? null) === 'status') {
          return $method === 'PATCH'
              ? $controller->updateStatus($request, $segments[1])
              : null;
      }

      return null;
  }

  protected function applyRuntimeVariables(Request $request): void
  {
      $request->merge($this->resolveRuntimeVariablesForParent($request->all()));
  }

  protected function resolveRuntimeVariablesForParent(mixed $payload): mixed
  {
      return $this->resolveRuntimeVariablesPayload($payload);
  }

  protected function resolveRuntimeVariablesForChild(mixed $payload): mixed
  {
      return $this->resolveRuntimeVariablesPayload($payload);
  }

  protected function resolveRuntimePayload(mixed $payload): mixed
  {
      return $this->resolveRuntimeVariablesForParent($payload);
  }

  private function resolveRuntimeVariablesPayload(mixed $payload): mixed
  {
      if (is_array($payload)) {
          $resolved = [];

          foreach ($payload as $key => $value) {
              $resolved[$key] = $this->resolveRuntimeVariablesPayload($value);
          }

          return $this->normalizeNumericIndexedArray($resolved);
      }

      return $this->runtimeVariableParser->parse($payload);
  }

  protected function applyRuntimeDefaults(Request $request, array $params): void
  {
      $request->merge($this->applyRuntimeDefaultsToPayload($request->all(), $params));
  }

  /**
   * Apply runtime defaults recursively without flattening nested collection
   * payloads into dot-notation paths.
   *
   * @param array<string, mixed> $payload
   * @param array<int, mixed> $params
   * @return array<string, mixed>
   */
  protected function applyRuntimeDefaultsToPayload(array $payload, array $params): array
  {
      foreach ($params as $param) {
          if (! is_array($param) || empty($param['name'])) {
              continue;
          }

          $name = (string) $param['name'];
          $type = (string) ($param['type'] ?? '');

          if ($this->isPrimitiveArrayApiBuilderParamType($type)) {
              if (array_key_exists($name, $payload) && is_array($payload[$name])) {
                  $payload[$name] = $this->normalizeNumericIndexedArray($payload[$name]);
              }

              continue;
          }

          if (in_array($type, ['array', 'object'], true)) {
              if (! array_key_exists($name, $payload) || ! is_array($payload[$name])) {
                  continue;
              }

              $childParams = is_array($param['params'] ?? null) ? $param['params'] : [];

              if ($childParams === []) {
                  continue;
              }

              $payload[$name] = $this->normalizeNumericIndexedArray($payload[$name]);

              if (array_is_list($payload[$name])) {
                  foreach ($payload[$name] as $index => $item) {
                      if (! is_array($item)) {
                          continue;
                      }

                      $payload[$name][$index] = $this->applyRuntimeDefaultsToPayload($item, $childParams);
                  }
              } else {
                  $payload[$name] = $this->applyRuntimeDefaultsToPayload($payload[$name], $childParams);
              }

              continue;
          }

          if (
              (! array_key_exists($name, $payload))
              || $payload[$name] === null
              || $payload[$name] === ''
          ) {
              if (array_key_exists('default', $param)) {
                  $payload[$name] = $this->runtimeVariableParser->parse($param['default']);
              }
          }
      }

      return $payload;
  }

  protected function normalizeDeclaredArrayPayload(array $payload, array $params): array
  {
      foreach ($params as $param) {
          if (! is_array($param) || empty($param['name']) || empty($param['type'])) {
              continue;
          }

          $name = trim((string) $param['name']);
          if ($name === '' || ! array_key_exists($name, $payload) || ! is_array($payload[$name])) {
              continue;
          }

          $type = $this->normalizeApiBuilderParamType($param['type'] ?? null);
          $payload[$name] = $this->normalizeNumericIndexedArray($payload[$name]);

          if (! $this->isContainerApiBuilderParamType($type)) {
              continue;
          }

          $childParams = is_array($param['params'] ?? null) ? $param['params'] : [];
          if ($childParams === []) {
              continue;
          }

          if (array_is_list($payload[$name])) {
              foreach ($payload[$name] as $index => $item) {
                  if (! is_array($item)) {
                      continue;
                  }

                  $payload[$name][$index] = $this->normalizeDeclaredArrayPayload($item, $childParams);
              }

              continue;
          }

          $payload[$name] = $this->normalizeDeclaredArrayPayload($payload[$name], $childParams);
      }

      return $payload;
  }

  protected function firstArrayValue(array $values): mixed
  {
      if ($values === []) {
          return null;
      }

      return reset($values);
  }

  protected function normalizeNumericIndexedArray(mixed $value): mixed
  {
      if (! is_array($value) || $value === []) {
          return $value;
      }

      foreach (array_keys($value) as $key) {
          if (! is_int($key)) {
              return $value;
          }
      }

      return array_values($value);
  }

  protected function normalizeUploadExtensions(mixed $extensions): array
  {
      if (! is_array($extensions)) {
          return [];
      }

      return array_values(array_filter(array_map(static function (mixed $extension): string {
          $extension = strtolower(trim((string) $extension));
          return ltrim($extension, '.');
      }, $extensions), static fn (string $extension): bool => $extension !== ''));
  }

  protected function buildUploadMaxFileSizeRules(mixed $maxFileSize): array
  {
      if (! is_numeric($maxFileSize) || (int) $maxFileSize <= 0) {
          return [];
      }

      return ['max:' . (int) $maxFileSize];
  }

  protected function normalizeUploadPath(mixed $uploadPath): string
  {
      return $this->normalizeUploadBasePath($uploadPath);
  }

  protected function normalizeUploadBasePath(mixed $basePath): string
  {
      $path = trim((string) $basePath, "/ \t\n\r\0\x0B");

      return $path !== '' ? $path : 'uploads/general';
  }

  protected function storeUploadedFile(UploadedFile $file, string $diskName, string $basePath): string
  {
      $disk = Storage::disk($diskName);
      $disk->makeDirectory($basePath);

      $extension = strtolower((string) $file->getClientOriginalExtension());
      $uniqueName = now()->timestamp . '-' . Str::random(13);

      if ($extension !== '') {
          $uniqueName .= '.' . $extension;
      }

      $relativePath = trim($basePath, '/') . '/' . $uniqueName;
      $disk->putFileAs($basePath, $file, $uniqueName);

      return $relativePath;
  }

  protected function resolveUploadStorageDisk(): string
  {
      $uploadStorage = config('datasources.upload_storage', []);
      $disk = trim((string) ($uploadStorage['disk'] ?? 'local'));

      if ($disk === '') {
          $disk = config('filesystems.default', 'local');
      }

      $disks = config('filesystems.disks', []);

      return is_array($disks) && array_key_exists($disk, $disks)
          ? $disk
          : config('filesystems.default', 'local');
  }

  protected function resolveUploadBasePath(?UploadConfig $uploadConfig = null): string
  {
      $uploadPath = $uploadConfig?->upload_path;

      if (is_string($uploadPath) && trim($uploadPath) !== '') {
          return $this->normalizeUploadBasePath($uploadPath);
      }

      $uploadStorage = config('datasources.upload_storage', []);

      return $this->normalizeUploadBasePath($uploadStorage['base_path'] ?? 'uploads/general');
  }

  protected function prepareRuntimeRequest(Request $request, array $params): ?JsonResponse
  {
      try {
          $this->applyRuntimeVariables($request);
          $this->applyRuntimeDefaults($request, $params);
          $request->merge($this->normalizeDeclaredArrayPayload($request->all(), $params));
      } catch (InvalidRuntimeVariableException $e) {
          return response()->json([
              'status' => 422,
              'error' => $e->getMessage(),
              'message' => $e->getMessage(),
          ], 422);
      }

      return null;
  }

  protected function dispatchResolvedRequest(Request $request, ApiConfig $apiConfigs, mixed $id, ?string $action = null): JsonResponse
  {
        $lookupKey = $this->resolveParentLookupKey($apiConfigs);

        if($apiConfigs->method == 'POST'){
            if ($action === 'restore') {
                if (empty($id)) {
                    return response()->json(['status' => 400, 'error'=> "{$lookupKey} is required", 'message'=> "{$lookupKey} is required"], 400);
                }

                return $this->restore($request, $apiConfigs, $id);
            }

            return $this->store($request, $apiConfigs);
        }

        if($apiConfigs->method == 'GET'){
            $this->runBeforeExecuteHooks($request, $apiConfigs);

            return $this->dataQueryService->executeForApiConfig(
                $request,
                $apiConfigs,
                'api_config_q' . $apiConfigs->id
            );
        }

        if(in_array($apiConfigs->method, ['PUT', 'PATCH'], true)){
            if(empty($id)){
              return response()->json(['status' => 400, 'error'=> "{$lookupKey} is required", 'message'=> "{$lookupKey} is required"], 400);
            }

            return $this->update($request, $apiConfigs, $id);
        }


        if($apiConfigs->method == 'DELETE'){
            if(empty($id)){
              return response()->json(['status' => 400, 'error'=> "{$lookupKey} is required", 'message'=> "{$lookupKey} is required"], 400);
            }

            return $this->destroy($request, $apiConfigs, $id);
        }

        return response()->json(['data' => []], 200);
  }

  protected function tableHasDeletedAtColumn(string $tableName, ?string $connectionName = null): bool
  {
        $tableName = trim($tableName);

        if ($tableName === '') {
            return false;
        }

        try {
            $columns = DatabaseConnection::schema($connectionName)->getColumnListing($tableName);
        } catch (\Throwable $e) {
            return false;
        }

        return in_array('deleted_at', $columns, true);
  }

  protected function usesSoftDeleteForTable(?array $table, ?string $tableName = null, ?string $connectionName = null): bool
  {
        if (!is_array($table)) {
            return false;
        }

        if (!array_key_exists('use_soft_delete', $table) || $table['use_soft_delete'] !== true) {
            return false;
        }

        $resolvedTableName = $tableName ?? (string) ($table['table_name'] ?? '');

        return $this->tableHasDeletedAtColumn($resolvedTableName, $connectionName);
  }

  protected function deleteTableRecord($connection, string $tableName, string $primaryKey, mixed $id, bool $useSoftDelete): void
  {
        $query = $connection->table($tableName)->where($primaryKey, $id);

        if ($useSoftDelete) {
            $query->update(['deleted_at' => now()]);
            return;
        }

        $query->delete();
  }

  protected function resolveParentLookupKey(ApiConfig $apiConfig): string
  {
        $lookupKey = trim((string) ($apiConfig->parentTable?->key_update_delete ?? ''));

        if ($lookupKey !== '') {
            return $lookupKey;
        }

        $primaryKey = trim((string) ($apiConfig->parentTable?->primary_key ?? ''));

        return $primaryKey !== '' ? $primaryKey : 'id';
  }

  protected function resolveRecordValue(mixed $record, string $field, mixed $fallback = null): mixed
  {
        if (is_object($record) && isset($record->{$field})) {
            return $record->{$field};
        }

        if (is_array($record) && array_key_exists($field, $record)) {
            return $record[$field];
        }

        return $fallback;
  }

  protected function resolveParentRecordContext(ApiConfig $apiConfig, $connection, string $cleanParentTable, mixed $id): ?array
  {
        $lookupKey = $this->resolveParentLookupKey($apiConfig);
        $primaryKey = trim((string) ($apiConfig->parentTable?->primary_key ?? ''));
        $record = $connection->table($cleanParentTable)
            ->where($lookupKey, $id)
            ->first();

        if ($record === null) {
            return null;
        }

        $resolvedPrimaryKey = $primaryKey !== '' ? $primaryKey : $lookupKey;
        $parentId = $this->resolveRecordValue($record, $resolvedPrimaryKey, $id);

        return [
            'lookup_key' => $lookupKey,
            'primary_key' => $resolvedPrimaryKey,
            'record' => $record,
            'parent_id' => $parentId,
        ];
  }

  protected function resolveChildUpdateKey(array $childTable, ?string $connectionName = null): string
  {
        $lookupKey = trim((string) ($childTable['child_update_key'] ?? ''));

        if ($lookupKey !== '') {
            return $lookupKey;
        }

        $primaryKey = trim((string) ($childTable['primary_key'] ?? ''));

        if ($primaryKey !== '') {
            return $primaryKey;
        }

        $tableName = trim((string) ($childTable['table_name'] ?? ''));

        if ($tableName !== '') {
            $resolvedPrimaryKey = $this->resolveTablePrimaryKeyName($tableName, $connectionName);

            if ($resolvedPrimaryKey !== '') {
                return $resolvedPrimaryKey;
            }
        }

        return 'id';
  }

  protected function normalizeMissingChildStrategy(mixed $value): string
  {
        $normalized = strtoupper(trim((string) ($value ?? 'KEEP_EXISTING')));

        return $normalized === 'DELETE_MISSING' ? 'DELETE_MISSING' : 'KEEP_EXISTING';
  }

  protected function resolveTablePrimaryKeyName(string $tableName, ?string $connectionName = null): string
  {
        $tableName = trim($tableName);

        if ($tableName === '' || $this->databaseMetadataProvider === null) {
            return '';
        }

        try {
            $indexes = $this->databaseMetadataProvider->listIndexes($tableName, $connectionName);
        } catch (\Throwable $e) {
            return '';
        }

        foreach ($indexes as $index) {
            if (! empty($index['primary']) && ! empty($index['column'])) {
                return trim((string) $index['column']);
            }
        }

        return '';
  }

  protected function resolveChildRowIdentifier(array $row, string $lookupKey): mixed
  {
        if ($lookupKey === '' || ! array_key_exists($lookupKey, $row)) {
            return null;
        }

        $value = $row[$lookupKey];

        if ($this->isMissingBuilderValue($value)) {
            return null;
        }

        return $value;
  }

  protected function isMissingBuilderValue(mixed $value): bool
  {
        if ($value === self::DATA_BUILDER_MISSING) {
            return true;
        }

        return is_string($value) && trim($value) === self::DATA_BUILDER_MISSING;
  }

  protected function sanitizePersistedRow(array $row, ?string $lookupKey = null): array
  {
        foreach ($row as $column => $value) {
            if ($this->isMissingBuilderValue($value)) {
                unset($row[$column]);
            }
        }

        if ($lookupKey !== null && array_key_exists($lookupKey, $row) && $this->isMissingBuilderValue($row[$lookupKey])) {
            unset($row[$lookupKey]);
        }

        return $row;
  }

  protected function persistChildTableRows(
      $connection,
      array $table,
      string $tableChild,
      array $childRows,
      array $parentIds,
      ?string $connectionName = null,
      ?Request $request = null
  ): void {
        $foreignKey = trim((string) ($table['foreign_key'] ?? ''));

        if ($foreignKey === '' || $tableChild === '' || $parentIds === []) {
            return;
        }

        $childLookupKey = $this->resolveChildUpdateKey($table, $connectionName);
        $missingChildStrategy = $this->normalizeMissingChildStrategy($table['missing_child_strategy'] ?? null);
        $childUsesSoftDelete = $this->usesSoftDeleteForTable($table, $tableChild, $connectionName);
        $tablePrimaryKey = trim((string) ($table['primary_key'] ?? ''));

        if ($tablePrimaryKey === '') {
            $tablePrimaryKey = $this->resolveTablePrimaryKeyName($tableChild, $connectionName);
        }

        $rawChildCollection = [];
        if ($request instanceof Request) {
            $detectedCollection = $this->detectLoopInsertCollection(
                is_array($table['data_params'] ?? null) ? $table['data_params'] : [],
                $request
            );

            if (is_array($detectedCollection)) {
                $rawChildCollection = array_values($this->normalizeNumericIndexedArray($detectedCollection));
            }
        }

        \Log::debug('API Builder child persistence config', [
            'table' => $tableChild,
            'foreign_key' => $foreignKey,
            'child_update_key' => $childLookupKey,
            'missing_child_strategy' => $missingChildStrategy,
            'child_row_count' => count($childRows),
            'raw_child_count' => count($rawChildCollection),
        ]);

        foreach ($parentIds as $parentId) {
            $incomingIdentifiers = [];
            $hasIdentifiedChildRows = false;

            foreach ($childRows as $index => $childRow) {
                $persistedRow = $this->sanitizePersistedRow($childRow, $childLookupKey);
                $persistedRow[$foreignKey] = $parentId;
                $identifier = $this->resolveChildRowIdentifier($persistedRow, $childLookupKey);

                if ($identifier === null && array_key_exists($index, $rawChildCollection)) {
                    $identifier = $this->resolveValueFromContext($childLookupKey, $rawChildCollection[$index]);
                    if ($this->isMissingBuilderValue($identifier)) {
                        $identifier = null;
                    }
                }

                if ($identifier !== null) {
                    $hasIdentifiedChildRows = true;
                    $incomingIdentifiers[] = $identifier;
                    $persistedRow[$childLookupKey] = $identifier;

                    $existingQuery = $connection->table($tableChild)
                        ->where($foreignKey, $parentId)
                        ->where($childLookupKey, $identifier);
                    $existingFound = $existingQuery->exists();

                    \Log::debug('API Builder child lookup', [
                        'table' => $tableChild,
                        'foreign_key' => $foreignKey,
                        'child_update_key' => $childLookupKey,
                        'payload_value' => $identifier,
                        'parent_id' => $parentId,
                        'existing_found' => $existingFound,
                    ]);

                    if ($existingFound) {
                        \Log::debug('API Builder child action', [
                            'table' => $tableChild,
                            'action' => 'UPDATE',
                            'payload_value' => $identifier,
                        ]);
                        $existingQuery->update($persistedRow);
                        continue;
                    }
                }
                else {
                    unset($persistedRow[$childLookupKey]);
                }

                \Log::debug('API Builder child action', [
                    'table' => $tableChild,
                    'action' => 'INSERT',
                    'payload_value' => $identifier,
                ]);
                $needsInsertedIds = $tablePrimaryKey !== '' && strcasecmp($childLookupKey, $tablePrimaryKey) === 0 && $identifier === null;
                $insertedIds = $this->insertTableRows(
                    $connection,
                    $tableChild,
                    [$persistedRow],
                    $tablePrimaryKey !== '' ? $tablePrimaryKey : null,
                    $needsInsertedIds
                );

                if ($tablePrimaryKey !== '' && strcasecmp($childLookupKey, $tablePrimaryKey) === 0 && $insertedIds !== []) {
                    $hasIdentifiedChildRows = true;
                    $incomingIdentifiers = array_merge($incomingIdentifiers, $insertedIds);
                }
            }

            if ($missingChildStrategy !== 'DELETE_MISSING') {
                continue;
            }

            if ($childRows === []) {
                $missingQuery = $connection->table($tableChild)->where($foreignKey, $parentId);

                if ($childUsesSoftDelete) {
                    $missingQuery->update(['deleted_at' => now()]);
                } else {
                    $missingQuery->delete();
                }

                continue;
            }

            if (! $hasIdentifiedChildRows) {
                continue;
            }

            $missingQuery = $connection->table($tableChild)->where($foreignKey, $parentId);
            $incomingIdentifiers = array_values(array_unique($incomingIdentifiers));
            $missingQuery->whereNotIn($childLookupKey, $incomingIdentifiers);

            if ($childUsesSoftDelete) {
                $missingQuery->update(['deleted_at' => now()]);
            } else {
                $missingQuery->delete();
            }
        }
  }

  protected function logDeleteMode(string $context, ApiConfig $apiConfig, string $tableName, bool $useSoftDelete, string $primaryKey, mixed $id): void
  {
        \Log::info('API Builder delete mode', [
            'context' => $context,
            'api_config_id' => $apiConfig->id,
            'table_name' => $tableName,
            'primary_key' => $primaryKey,
            'record_id' => $id,
            'use_soft_delete' => $useSoftDelete,
            'delete_mode' => $useSoftDelete ? 'soft_delete' : 'hard_delete',
            'executed_query' => $useSoftDelete
                ? "UPDATE {$tableName} SET deleted_at = CURRENT_TIMESTAMP WHERE {$primaryKey} = ?"
                : "DELETE FROM {$tableName} WHERE {$primaryKey} = ?",
        ]);
  }

  protected function restoreTableRecord($connection, string $tableName, string $primaryKey, mixed $id): void
  {
        $connection->table($tableName)
            ->where($primaryKey, $id)
            ->update(['deleted_at' => null]);
  }

  /**
   * Execute all before-execute hooks using the current tenant/runtime context.
   *
   * @param Request $request
   * @param ApiConfig $apiConfig
   */
  protected function runBeforeExecuteHooks(Request $request, ApiConfig $apiConfig): void
  {
        $hooks = $apiConfig->hooks()
            ->where('action_type', 'before_execute')
            ->orderBy('id')
            ->get();

        if ($hooks->isEmpty()) {
            return;
        }

        $payload = $request->all();

        foreach ($hooks as $hook) {
            $hookClass = trim((string) ($hook->listener_class ?? ''));

            if ($hookClass === '') {
                continue;
            }

            if (! class_exists($hookClass)) {
                throw new \RuntimeException("Before execute hook class not found: {$hookClass}");
            }

            $instance = app($hookClass);

            if (! $instance instanceof BeforeExecuteHookInterface) {
                throw new \RuntimeException("Before execute hook must implement " . BeforeExecuteHookInterface::class);
            }

            $instance->handle($payload, $apiConfig, $request);
        }

        $request->replace($payload);
  }

  protected function dispatchAfterHitApiEvent(
      ApiConfig $apiConfig,
      Request $request,
      JsonResponse $response,
      mixed $resolvedId = null
  ): void {
        $dispatcher = $this->afterHitApiDispatcher();

        if ($dispatcher === null) {
            return;
        }

        try {
            $dispatcher->dispatchIfSuccessful(
                $apiConfig,
                $request,
                $response,
                $resolvedId,
                $this->resolveAfterHitPayload($request, $apiConfig),
                $this->resolveAfterHitResult($request, $apiConfig, $resolvedId),
                $this->resolveAfterHitAction($request, $apiConfig),
                $this->resolveAfterHitBeforeData($request, $apiConfig)
            );
        } catch (\Throwable $e) {
            \Log::error('AFTER HIT API DISPATCH ERROR => ' . $e->getMessage(), [
                'route_name' => $apiConfig->route_name,
                'endpoint' => $apiConfig->endpoint,
                'method' => $apiConfig->method,
            ]);
        }
  }

  protected function afterHitApiDispatcher(): ?AfterHitApiDispatcher
  {
        if ($this->afterHitApiDispatcher !== null) {
            return $this->afterHitApiDispatcher;
        }

        if (! app()->bound(AfterHitApiDispatcher::class)) {
            return null;
        }

        $this->afterHitApiDispatcher = app(AfterHitApiDispatcher::class);

        return $this->afterHitApiDispatcher;
  }

  /**
   * Resolve the data payload that should be visible to the listener.
   *
   * For create/update requests we forward the request data after runtime
   * defaults have been applied. For delete we keep the payload empty so the
   * listener can rely on resolvedId as the deleted identifier.
   *
   * @return array<string, mixed>
   */
  protected function resolveAfterHitPayload(Request $request, ApiConfig $apiConfig): array
  {
        if (strtoupper((string) $apiConfig->method) === 'DELETE') {
            return [];
        }

        return $request->all();
  }

  /**
   * Resolve the final API result for the after-hit listener.
   *
   * @return array<string, mixed>
   */
  protected function resolveAfterHitResult(Request $request, ApiConfig $apiConfig, mixed $resolvedId = null): array
  {
        $result = $request->attributes->get('datasources.after_hit.result');

        if (is_array($result)) {
            return $result;
        }

        if (is_object($result)) {
            return json_decode(json_encode($result), true) ?: [];
        }

        if (strtoupper((string) $apiConfig->method) === 'DELETE') {
            return $resolvedId === null ? [] : ['id' => $resolvedId];
        }

        return [];
  }

  protected function resolveAfterHitBeforeData(Request $request, ApiConfig $apiConfig): array
  {
        $beforeData = $request->attributes->get('datasources.after_hit.before_data');

        if (is_array($beforeData)) {
            return $beforeData;
        }

        if (is_object($beforeData)) {
            return json_decode(json_encode($beforeData), true) ?: [];
        }

        return [];
  }

  protected function resolveAfterHitAction(Request $request, ApiConfig $apiConfig): string
  {
        $action = trim((string) $request->attributes->get('datasources.after_hit.action', ''));

        if ($action !== '') {
            return $action;
        }

        return strtolower((string) $apiConfig->method);
  }

  /**
   * Normalize a database record to an associative array.
   *
   * @return array<string, mixed>
   */
  protected function normalizeAfterHitRecord(mixed $record): array
  {
        if (is_array($record)) {
            return $record;
        }

        if (is_object($record)) {
            return json_decode(json_encode($record), true) ?: [];
        }

        return [];
  }

  protected function runDynamicMiddlewarePipeline(Request $request, ApiConfig $apiConfig, \Closure $destination): JsonResponse
  {
        $middlewares = array_values(array_filter(array_merge(
            config('datasources.routes.dynamic.middleware', []),
            $apiConfig->middlewares ?? []
        )));

        if ($middlewares === []) {
            return $destination($request);
        }

        $middlewares = $this->buildDynamicMiddlewarePipes($middlewares);

        return $this->pipeline
            ->send($request)
            ->through($middlewares)
            ->then(fn (Request $request) => $destination($request));
  }

  /** 
   * Build a middleware pipe list where sensitive middleware is wrapped in a
   * connection-scoped closure and regular middleware stays untouched.
   *
   * @param array<int, mixed> $middlewares
   * @return array<int, mixed>
   */
  protected function buildDynamicMiddlewarePipes(array $middlewares): array
  {
        $pipes = [];

        foreach ($middlewares as $middleware) {
            if (! is_string($middleware) || trim($middleware) === '') {
                continue;
            }

            $connection = $this->middlewareConnectionResolver->resolveConnection($middleware);

            if ($connection !== null) {
                $pipes[] = $this->wrapMiddlewareWithConnection($middleware, $connection);
                continue;
            }

            foreach ($this->resolveMiddlewareDefinitions([$middleware]) as $resolvedMiddleware) {
                $pipes[] = $resolvedMiddleware;
            }
        }

        return $pipes;
  }

  /**
   * Wrap a middleware so it runs under a specific database connection and the
   * original connection is always restored before the outer pipeline continues.
   *
   * @param string $middleware
   * @param string $connection
   * @return \Closure
   */
  protected function wrapMiddlewareWithConnection(string $middleware, string $connection): \Closure
  {
        return function (Request $request, \Closure $next) use ($middleware, $connection) {
            $databaseManager = app('db');
            $validatorFactory = app('validator');
            $previousDefaultConnection = DB::getDefaultConnection();
            $previousPresenceVerifier = method_exists($validatorFactory, 'getPresenceVerifier')
                ? $validatorFactory->getPresenceVerifier()
                : null;

            try {
                config(['database.default' => $connection]);
                $databaseManager->setDefaultConnection($connection);
                DB::setDefaultConnection($connection);

                $presenceVerifier = new DatabasePresenceVerifier($databaseManager);
                $presenceVerifier->setConnection($connection);
                $validatorFactory->setPresenceVerifier($presenceVerifier);

                $middlewarePipe = $this->resolveMiddlewareDefinitions([$middleware]);

                if ($middlewarePipe === []) {
                    $middlewarePipe = [$middleware];
                }

                return $this->pipeline
                    ->send($request)
                    ->through($middlewarePipe)
                    ->then(fn (Request $request) => $next($request));
            } finally {
                if ($previousPresenceVerifier !== null) {
                    $validatorFactory->setPresenceVerifier($previousPresenceVerifier);
                }

                config(['database.default' => $previousDefaultConnection]);
                $databaseManager->setDefaultConnection($previousDefaultConnection);
                DB::setDefaultConnection($previousDefaultConnection);
            }
        };
  }

  /**
   * Resolve middleware definitions using Laravel router middleware aliases and groups.
   *
   * @param array $middlewares
   * @return array
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
  * Store new data into the database.
  *
  * @param Request $request
  * @param object $apiConfigs API Configuration object
  *
  * @return \Illuminate\Http\JsonResponse
  */
    public function store(Request $request, $apiConfigs)
  {
    
      if ($runtimeError = $this->prepareRuntimeRequest($request, $apiConfigs->params ?? [])) {
          return $runtimeError;
      }

      $connection = $this->executionConnectionResolver->connection($request);
      // Validate input data based on API configurations
      $validationConnectionName = $this->executionConnectionResolver->resolve($request);
      $validationRules = $this->buildValidationRulesFromParams($apiConfigs->params ?? [], $apiConfigs->parentTable->table_name);

      if ($validationRules !== []) {
          $this->validateWithDatasourceConnection($request->all(), $validationRules, $validationConnectionName);
      }

      $this->runBeforeExecuteHooks($request, $apiConfigs);

      $parentTable = $apiConfigs->parentTable->table_name;

      $prefix = $connection->getTablePrefix();
      $childTables = $apiConfigs->childTables->toArray();

      // Flatten parameters for easier access
      $masterParam1Level = $this->flattenArray($apiConfigs->params);

      try {
          $connection->beginTransaction();
          $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);

          $parentRows = $this->buildMappedTableRows(
              is_array($apiConfigs->parentTable->data_params ?? null) ? $apiConfigs->parentTable->data_params : [],
              $request,
              $masterParam1Level
          );
          $parentRows = array_values($this->normalizeNumericIndexedArray($parentRows));

          if ($parentRows === []) {
              $parentRows = [[]];
          }

          $parentIds = $this->insertTableRows(
              $connection,
              $cleanParentTable,
              $parentRows,
              trim((string) ($apiConfigs->parentTable->primary_key ?? ''))
          );
          if ($parentIds === []) {
              throw new \RuntimeException('Unable to insert parent table data.');
          }

          foreach ($childTables as $table) {
              $cleanChildTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']);
              $childRows = $this->buildMappedTableRows(
                  is_array($table['data_params'] ?? null) ? $table['data_params'] : [],
                  $request,
                  $masterParam1Level
              );
              $childRows = array_values($this->normalizeNumericIndexedArray($childRows));

              if ($childRows === []) {
                  continue;
              }

              foreach ($parentIds as $parentId) {
                  $rowsToInsert = [];

                  foreach ($childRows as $childRow) {
                      $childRow[$table['foreign_key']] = $parentId;
                      $rowsToInsert[] = $childRow;
                  }

                  $this->insertTableRows($connection, $cleanChildTable, $rowsToInsert, null, false);
              }
          }

          $firstParentId = $this->firstArrayValue($parentIds);
          if ($firstParentId === null) {
              throw new \RuntimeException('Unable to resolve inserted parent identifier.');
          }

          $finalRecord = $this->normalizeAfterHitRecord(
              $connection->table($cleanParentTable)
                  ->where($apiConfigs->parentTable->primary_key, $firstParentId)
                  ->first()
          );

          $request->attributes->set('datasources.after_hit.action', 'create');
          $request->attributes->set('datasources.after_hit.result', $finalRecord);
          $request->attributes->set('datasources.after_hit.before_data', []);

          $connection->commit();
          return response()->json([
              "status" => 200,
              'message' => 'Data has been successfully created',
              'data' => []
          ], 201);

      } catch (ApiHookException $e) {
          return response()->json(
              $e->toResponsePayload(),
              $e->getStatusCode()
          );
      } catch (\Exception $e) {
          $connection->rollBack();
          \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
          return response()->json([
              "status" => 422,
              "data" => [],
              "error" => $e->getMessage()
          ], 422);
      }
  }




  /**
  * Update data into the database.
  *
  * @param Request $request
  * @param object $apiConfigs API Configuration object
  * @param string $id primary key
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function update(Request $request, $apiConfigs, $id)
  {
        if ($runtimeError = $this->prepareRuntimeRequest($request, $apiConfigs->params ?? [])) {
            return $runtimeError;
        }

        $validationConnectionName = $this->executionConnectionResolver->resolve($request);
        $validationRules = $this->buildValidationRulesFromParams(
            $apiConfigs->params ?? [],
            $apiConfigs->parentTable->table_name,
            $id,
            '',
            $this->resolveParentLookupKey($apiConfigs)
        );

        if ($validationRules !== []) {
            $this->validateWithDatasourceConnection($request->all(), $validationRules, $validationConnectionName);
        }

        $this->runBeforeExecuteHooks($request, $apiConfigs);

        $parentTable = $apiConfigs->parentTable->table_name;

        $connection = $this->executionConnectionResolver->connection($request);
        $connectionName = $this->executionConnectionResolver->resolve($request);
        $prefix = $connection->getTablePrefix();
        $childTables = $apiConfigs->childTables->toArray();
        $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);
        $parentContext = $this->resolveParentRecordContext($apiConfigs, $connection, $cleanParentTable, $id);

        if ($parentContext === null) {
            return response()->json(['status' => 404, 'error' => 'Data not found', 'message' => 'Data not found'], 404);
        }

        $lookupKey = $parentContext['lookup_key'];
        $primarykey = $parentContext['primary_key'];
        $parentRecordId = $parentContext['parent_id'];

        $masterParam1Level = $this->flattenArray($apiConfigs->params);

        try {
            $connection->beginTransaction();

            $parentRows = $this->buildMappedTableRows(
                is_array($apiConfigs->parentTable->data_params ?? null) ? $apiConfigs->parentTable->data_params : [],
                $request,
                $masterParam1Level
            );
            $parentRows = array_values($this->normalizeNumericIndexedArray($parentRows));

            if ($parentRows === []) {
                $parentRows = [[]];
            }

            if (count($parentRows) === 1) {
                $singleParentRow = $this->firstArrayValue($parentRows);
                if (! is_array($singleParentRow)) {
                    throw new \RuntimeException('Unable to resolve parent row payload.');
                }

                $connection->table($cleanParentTable)
                    ->where($lookupKey, $id)
                    ->update($singleParentRow);
                $parentIds = [$parentRecordId];
            } else {
                $connection->table($cleanParentTable)
                    ->where($lookupKey, $id)
                    ->delete();
                $parentIds = $this->insertTableRows(
                    $connection,
                    $cleanParentTable,
                    $parentRows,
                    $primarykey
                );
            }

            if ($parentIds === []) {
                throw new \RuntimeException('Unable to persist parent table data.');
            }

            $replaceChildRows = count($parentRows) > 1;

            foreach ($childTables as $table) {
                $tableChild = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']);
                if ($replaceChildRows) {
                    $childUsesSoftDelete = $this->usesSoftDeleteForTable($table, $tableChild, $connectionName);
                    $childQuery = $connection->table($tableChild)->where($table['foreign_key'], $parentRecordId);

                    if ($childUsesSoftDelete) {
                        $childQuery->update(['deleted_at' => now()]);
                    } else {
                        $childQuery->delete();
                    }
                }

                $childRows = $this->buildMappedTableRows(
                    is_array($table['data_params'] ?? null) ? $table['data_params'] : [],
                    $request,
                    $masterParam1Level
                );
                $childRows = array_values($this->normalizeNumericIndexedArray($childRows));

                $missingChildStrategy = $this->normalizeMissingChildStrategy($table['missing_child_strategy'] ?? null);

                if ($childRows === [] && $missingChildStrategy !== 'DELETE_MISSING') {
                    continue;
                }

                $this->persistChildTableRows(
                    $connection,
                    $table,
                    $tableChild,
                    $childRows,
                    $parentIds,
                    $connectionName,
                    $request
                );
            }

            $firstParentId = $this->firstArrayValue($parentIds);
            if ($firstParentId === null) {
                throw new \RuntimeException('Unable to resolve persisted parent identifier.');
            }

            $finalRecord = $this->normalizeAfterHitRecord(
                $connection->table($cleanParentTable)
                    ->where($primarykey, $firstParentId)
                    ->first()
            );

            $request->attributes->set('datasources.after_hit.action', 'update');
            $request->attributes->set('datasources.after_hit.result', $finalRecord);
            $request->attributes->set('datasources.after_hit.before_data', []);

            $connection->commit();
            return response()->json(["status" => 200, 'message' => 'Data has been successfully updated', 'data' => []], 201);
        } catch (ApiHookException $e) {
            $connection->rollBack();
            return response()->json(
                $e->toResponsePayload(),
                $e->getStatusCode()
            );
        } catch (\Exception $e) {
            $connection->rollBack();
            \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
            return response()->json([
                "status" => 422,
                "data" => [],
                "error" => $e->getMessage()
            ], 422);
        }
    }



  /**
  * Delete data into the database.
  *
  * @param Request $request
  * @param object $apiConfigs API Configuration object
  * @param string $id primary key
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function destroy(Request $request, $apiConfigs, $id)
  {
        if ($runtimeError = $this->prepareRuntimeRequest($request, $apiConfigs->params ?? [])) {
            return $runtimeError;
        }

        $validationConnectionName = $this->executionConnectionResolver->resolve($request);
        $validationRules = $this->buildValidationRulesFromParams(
            $apiConfigs->params ?? [],
            $apiConfigs->parentTable->table_name,
            $id,
            '',
            $this->resolveParentLookupKey($apiConfigs)
        );

        if ($validationRules !== []) {
            $this->validateWithDatasourceConnection($request->all(), $validationRules, $validationConnectionName);
        }

        $this->runBeforeExecuteHooks($request, $apiConfigs);

        $parentTable = $apiConfigs->parentTable->table_name;

        $connection = $this->executionConnectionResolver->connection($request);
        $connectionName = $this->executionConnectionResolver->resolve($request);
        $prefix = $connection->getTablePrefix();
        $childTables = $apiConfigs->childTables->toArray();
        $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);
        $parentContext = $this->resolveParentRecordContext($apiConfigs, $connection, $cleanParentTable, $id);

        if ($parentContext === null) {
            return response()->json(['status' => 404, 'error' => 'Data not found', 'message' => 'Data not found'], 404);
        }

        $lookupKey = $parentContext['lookup_key'];
        $primarykey = $parentContext['primary_key'];
        $parentRecordId = $parentContext['parent_id'];

        $masterParam1Level = $this->flattenArray($apiConfigs->params);

        try {
            $connection->beginTransaction();

            $beforeData = $this->normalizeAfterHitRecord(
                $connection->table($cleanParentTable)
                    ->where($primarykey, $parentRecordId)
                    ->first()
            );

            $request->attributes->set('datasources.after_hit.action', 'delete');
            $request->attributes->set('datasources.after_hit.before_data', $beforeData);
            $request->attributes->set('datasources.after_hit.result', [
                'id' => $id,
                'deleted' => true,
            ]);

            $parentUsesSoftDelete = $this->usesSoftDeleteForTable(
                [
                    'use_soft_delete' => $apiConfigs->parentTable?->use_soft_delete ?? false,
                    'table_name' => $parentTable,
                ],
                $cleanParentTable,
                $connectionName
            );

            $this->logDeleteMode('parent', $apiConfigs, $cleanParentTable, $parentUsesSoftDelete, $lookupKey, $id);
            $this->deleteTableRecord($connection, $cleanParentTable, $lookupKey, $id, $parentUsesSoftDelete);

            foreach ($childTables as $key => $table) {
                $tableChild = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']);
                $childUsesSoftDelete = $this->usesSoftDeleteForTable($table, $tableChild, $connectionName);

                $this->logDeleteMode('child', $apiConfigs, $tableChild, $childUsesSoftDelete, (string) ($table['foreign_key'] ?? ''), $parentRecordId);
                $this->deleteTableRecord($connection, $tableChild, $table['foreign_key'], $parentRecordId, $childUsesSoftDelete);

            }

            $connection->commit();
            return response()->json(["status" => 200, 'message' => 'Data has been successfully deleted', 'data' => []], 201);
        } catch (ApiHookException $e) {
            $connection->rollBack();
            return response()->json(
                $e->toResponsePayload(),
                $e->getStatusCode()
            );
        } catch (\Exception $e) {
            $connection->rollBack();
            \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
    }

/**
 * Validates API request parameters based on configuration.
 *
 * This function processes and categorizes validation rules for different types of input parameters.
 * It separates parameters into three categories:
 * - Parent validation rules
 * - Child validation rules for array-type parameters
 * - A list of parameters that are arrays
 *
 * @param array $params The API parameters configuration.
 * @param string $tableParent The name of the parent table (optional).
 *
 * @return array Returns an associative array containing:
 *   - 'parentValidate': Validation rules for single-level parameters.
 *   - 'childValidate': Validation rules for array-type parameters.
 *   - 'paramIsArray': A list of parameters identified as arrays.
 */
public function validateRule($params=[], $tableParent = '', $primaryKey = 0, ?string $ignoreColumn = null)
  {
      $params = is_array($params) ? $params : [];
      $validationRules = $this->buildValidationRulesFromParams($params, $tableParent, $primaryKey, '', $ignoreColumn);

      return [
          'parentValidate' => $validationRules,
          'childValidate' => [],
          'paramIsArray' => $this->collectArrayParamNames($params),
      ];
  }

/**
 * Generates Laravel validation rules for a given parameter.
 *
 * This function determines whether a parameter is required or nullable and
 * applies type-based validation. If the parameter has a uniqueness constraint,
 * it ensures that it is unique within the specified database table.
 *
 * @param array $rowParam The parameter definition containing validation rules.
 * @param string $tableParent The name of the parent table for uniqueness validation.
 *
 * @return string The generated validation rule string.
 */
public function findValidateRule($rowParam, $tableParent, $primaryKey = 0, ?string $ignoreColumn = null)
{
    $value = $rowParam;
    $customRules = trim((string) ($value['validation_rules'] ?? ''));
    $rules = [];
    $typeRule = $this->mapValidationTypeRule($value['type'] ?? null);
    $ignoreColumn = trim((string) ($ignoreColumn ?? 'id'));

    if (!empty($value['required']) && $value['required']) {
        $rules[] = 'required';
    } else {
        $rules[] = 'nullable';
    }

    if ($typeRule !== null) {
        $rules[] = $typeRule;
    }

    if (!empty($value['unique']) && $value['unique']) {
        $prefix = DatabaseConnection::connection()->getTablePrefix();
        $table = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $tableParent);
        $uniqueRule = 'unique:' . $this->validationTableForCurrentConnection($table) . ',' . $value['name'];

        if ($primaryKey != 0) {
            $uniqueRule .= ',' . strval($primaryKey) . ',' . $ignoreColumn;
        }

        $rules[] = $uniqueRule;
    }

    if ($customRules !== '') {
        $rules[] = $customRules;
    }

    return implode('|', array_values(array_filter($rules, static fn ($rule) => is_string($rule) && trim($rule) !== '')));
}

protected function buildValidationRulesFromParams(array $params, string $tableParent = '', mixed $primaryKey = 0, string $pathPrefix = '', ?string $ignoreColumn = null): array
{
    $rules = [];

    foreach ($params as $param) {
        if (! is_array($param) || empty($param['name']) || empty($param['type'])) {
            continue;
        }

        $paramName = trim((string) $param['name']);

        if ($paramName === '') {
            continue;
        }

        $path = $pathPrefix !== '' ? $pathPrefix . '.' . $paramName : $paramName;
        $type = $this->normalizeApiBuilderParamType($param['type'] ?? null);

        if ($this->isContainerApiBuilderParamType($type)) {
            $rules[$path] = $this->buildContainerValidationRule($param, $tableParent, $primaryKey, $ignoreColumn);

            $childPrefix = $type === 'object' ? $path : $path . '.*';
            $childParams = is_array($param['params'] ?? null) ? $param['params'] : [];

            if ($childParams !== []) {
                $rules = array_merge(
                    $rules,
                    $this->buildValidationRulesFromParams($childParams, $tableParent, $primaryKey, $childPrefix, $ignoreColumn)
                );
            }

            continue;
        }

        if ($this->isPrimitiveArrayApiBuilderParamType($type)) {
            $rules[$path] = $this->buildPrimitiveArrayValidationRule($param);
            $rules[$path . '.*'] = $this->buildPrimitiveArrayItemValidationRule($param, $tableParent, $primaryKey, $ignoreColumn);
            continue;
        }

        $rules[$path] = $this->findValidateRule($param, $tableParent, $primaryKey, $ignoreColumn);
    }

    return $rules;
}

protected function buildContainerValidationRule(array $param, string $tableParent, mixed $primaryKey, ?string $ignoreColumn = null): string
{
    $baseParam = $param;
    $baseParam['type'] = 'array';

    return $this->findValidateRule($baseParam, $tableParent, $primaryKey, $ignoreColumn);
}

protected function buildPrimitiveArrayValidationRule(array $param): string
{
    $required = ! empty($param['required']);
    $rules = $required ? ['required', 'array'] : ['nullable', 'array'];

    return implode('|', $rules);
}

protected function buildPrimitiveArrayItemValidationRule(array $param, string $tableParent, mixed $primaryKey, ?string $ignoreColumn = null): string
{
    $itemParam = $param;
    $itemParam['type'] = $this->primitiveArrayItemType($param['type'] ?? null);
    $itemParam['required'] = true;

    $rule = $this->findValidateRule($itemParam, $tableParent, $primaryKey, $ignoreColumn);

    if (! empty($param['unique'])) {
        $rule .= '|distinct';
    }

    return $rule;
}

protected function primitiveArrayItemType(mixed $type): string
{
    return match ($this->normalizeApiBuilderParamType($type)) {
        'array integer' => 'integer',
        default => 'string',
    };
}

protected function collectArrayParamNames(array $params, array &$names = [], string $pathPrefix = ''): array
{
    foreach ($params as $param) {
        if (! is_array($param) || empty($param['name']) || empty($param['type'])) {
            continue;
        }

        $paramName = trim((string) $param['name']);
        if ($paramName === '') {
            continue;
        }

        $path = $pathPrefix !== '' ? $pathPrefix . '.' . $paramName : $paramName;
        $type = $this->normalizeApiBuilderParamType($param['type'] ?? null);

        if ($this->isArrayContainerApiBuilderParamType($type)) {
            $names[] = $path;
        }

        if ($this->isContainerApiBuilderParamType($type) && is_array($param['params'] ?? null)) {
            $childPrefix = $type === 'object' ? $path : $path . '.*';
            $this->collectArrayParamNames($param['params'], $names, $childPrefix);
        }
    }

    return array_values(array_unique($names));
}

  public function restore(Request $request, $apiConfigs, $id)
  {
        if ($runtimeError = $this->prepareRuntimeRequest($request, $apiConfigs->params ?? [])) {
            return $runtimeError;
        }

        $validationConnectionName = $this->executionConnectionResolver->resolve($request);
        $validationRules = $this->buildValidationRulesFromParams(
            $apiConfigs->params ?? [],
            $apiConfigs->parentTable->table_name,
            $id,
            '',
            $this->resolveParentLookupKey($apiConfigs)
        );

        if ($validationRules !== []) {
            $this->validateWithDatasourceConnection($request->all(), $validationRules, $validationConnectionName);
        }

        $this->runBeforeExecuteHooks($request, $apiConfigs);

        $parentTable = $apiConfigs->parentTable->table_name;

        $connection = $this->executionConnectionResolver->connection($request);
        $connectionName = $this->executionConnectionResolver->resolve($request);
        $prefix = $connection->getTablePrefix();
        $childTables = $apiConfigs->childTables->toArray();
        $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);
        $parentContext = $this->resolveParentRecordContext($apiConfigs, $connection, $cleanParentTable, $id);

        if ($parentContext === null) {
            return response()->json(['status' => 404, 'error' => 'Data not found', 'message' => 'Data not found'], 404);
        }

        $primarykey = $parentContext['primary_key'];
        $parentRecordId = $parentContext['parent_id'];

        try {
            $connection->beginTransaction();

            $beforeData = $this->normalizeAfterHitRecord(
                $connection->table($cleanParentTable)
                    ->where($primarykey, $parentRecordId)
                    ->first()
            );

            $parentUsesSoftDelete = $this->usesSoftDeleteForTable(
                [
                    'use_soft_delete' => $apiConfigs->parentTable?->use_soft_delete ?? false,
                    'table_name' => $parentTable,
                ],
                $cleanParentTable,
                $connectionName
            );

            if ($parentUsesSoftDelete) {
                $this->restoreTableRecord($connection, $cleanParentTable, $primarykey, $parentRecordId);
            }

            foreach ($childTables as $table) {
                $tableChild = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']);
                $childUsesSoftDelete = $this->usesSoftDeleteForTable($table, $tableChild, $connectionName);

                if ($childUsesSoftDelete) {
                    $this->restoreTableRecord($connection, $tableChild, $table['foreign_key'], $parentRecordId);
                }
            }

            $restoredRecord = $this->normalizeAfterHitRecord(
                $connection->table($cleanParentTable)
                    ->where($primarykey, $parentRecordId)
                    ->first()
            );

            $request->attributes->set('datasources.after_hit.action', 'restore');
            $request->attributes->set('datasources.after_hit.before_data', $beforeData);
            $request->attributes->set('datasources.after_hit.result', $restoredRecord);

            $connection->commit();
            return response()->json(["status" => 200, 'message' => 'Data has been successfully restored', 'data' => []], 201);
        } catch (ApiHookException $e) {
            $connection->rollBack();
            return response()->json(
                $e->toResponsePayload(),
                $e->getStatusCode()
            );
        } catch (\Exception $e) {
            $connection->rollBack();
            \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
  }

/**
 * Validate payload using the package datasource connection.
 *
 * @param array $data
 * @param array $rules
 * @return array<string, mixed>
 */
protected function validateWithDatasourceConnection(array $data, array $rules, ?string $connectionName = null): array
{
    $previousValidationConnection = $this->runtimeValidationConnectionName;
    $this->runtimeValidationConnectionName = $this->resolveValidationConnectionName($connectionName);

    try {
        return $this->withDatasourceValidationConnection(function () use ($data, $rules) {
        return Validator::make($data, $rules)->validate();
        });
    } finally {
        $this->runtimeValidationConnectionName = $previousValidationConnection;
    }
}

/**
 * Build a validator instance bound to the package datasource connection.
 *
 * @param array $data
 * @param array $rules
 * @return \Illuminate\Contracts\Validation\Validator
 */
protected function makeDatasourceValidator(array $data, array $rules, ?string $connectionName = null)
{
    $previousValidationConnection = $this->runtimeValidationConnectionName;
    $this->runtimeValidationConnectionName = $this->resolveValidationConnectionName($connectionName);

    try {
        return $this->withDatasourceValidationConnection(function () use ($data, $rules) {
        return Validator::make($data, $rules);
        });
    } finally {
        $this->runtimeValidationConnectionName = $previousValidationConnection;
    }
}

/**
 * Temporarily switch the default database connection and presence verifier
 * so database validation rules run against the active datasource connection.
 *
 * @template TReturn
 * @param \Closure():TReturn $callback
 * @return mixed
 */
protected function withDatasourceValidationConnection(\Closure $callback)
{
    if ($this->datasourceValidationConnectionActive) {
        return $callback();
    }

    $connectionName = $this->resolveValidationConnectionName();
    $databaseManager = app('db');
    $validatorFactory = app('validator');

    $this->datasourceValidationConnectionActive = true;

    try {
        config(['database.default' => $connectionName]);
        $databaseManager->setDefaultConnection($connectionName);
        DB::setDefaultConnection($connectionName);
        $databaseManager->purge($connectionName);

        $presenceVerifier = new DatabasePresenceVerifier($databaseManager);
        $presenceVerifier->setConnection($connectionName);
        $validatorFactory->setPresenceVerifier($presenceVerifier);

        return $callback();
    } finally {
        config(['database.default' => $connectionName]);
        $databaseManager->setDefaultConnection($connectionName);
        DB::setDefaultConnection($connectionName);
        $databaseManager->purge($connectionName);

        $restoredPresenceVerifier = new DatabasePresenceVerifier($databaseManager);
        $restoredPresenceVerifier->setConnection($connectionName);
        $validatorFactory->setPresenceVerifier($restoredPresenceVerifier);

        $this->datasourceValidationConnectionActive = false;
    }
}

/**
 * Resolve the active connection that should be used for runtime validation.
 *
 * This follows the execution connection when a tenant is active and falls
 * back to the package metadata connection for non-tenant requests.
 */
protected function resolveValidationConnectionName(?string $connectionName = null): string
{
    if (is_string($connectionName) && trim($connectionName) !== '') {
        return trim($connectionName);
    }

    if (is_string($this->runtimeValidationConnectionName) && trim($this->runtimeValidationConnectionName) !== '') {
        return trim($this->runtimeValidationConnectionName);
    }

    return $this->fallbackValidationConnectionName();
}

/**
 * Build the validation table name using the active validation connection.
 */
protected function validationTableForCurrentConnection(string $table): string
{
    $connectionName = trim($this->resolveValidationConnectionName());

    return $connectionName !== '' ? $connectionName . '.' . $table : $table;
}

/**
 * Fall back to the package metadata connection when no execution connection
 * is available.
 */
protected function fallbackValidationConnectionName(): string
{
    return DatabaseConnection::name();
}

/**
 * Map API Builder parameter types to Laravel validation rules.
 *
 * @param mixed $type
 * @return string|null
 */
protected function mapValidationTypeRule(mixed $type): ?string
{
    $normalized = $this->normalizeApiBuilderParamType($type);

    return match ($normalized) {
        'string', 'integer', 'numeric', 'boolean', 'array', 'email', 'date',
        'uuid', 'json', 'url', 'ip', 'ipv4', 'ipv6', 'file', 'image',
        'alpha', 'alpha_num', 'alpha_dash' => $normalized,
        'float', 'double' => 'numeric',
        'object', 'array object' => 'array',
        'array string' => 'array',
        'array integer' => 'array',
        default => $normalized !== '' ? $normalized : null,
    };
}

protected function normalizeApiBuilderParamType(mixed $type): string
{
    $normalized = strtolower(trim((string) $type));

    return match ($normalized) {
        'array-object' => 'array object',
        'array-string' => 'array string',
        'array-integer' => 'array integer',
        default => $normalized,
    };
}

protected function isContainerApiBuilderParamType(string $type): bool
{
    return in_array($this->normalizeApiBuilderParamType($type), ['object', 'array', 'array object'], true);
}

protected function isPrimitiveArrayApiBuilderParamType(string $type): bool
{
    return in_array($this->normalizeApiBuilderParamType($type), ['array string', 'array integer'], true);
}

protected function isArrayContainerApiBuilderParamType(string $type): bool
{
    return in_array($this->normalizeApiBuilderParamType($type), ['array', 'array object'], true);
}

/**
 * Retrieve a list of tables that contain LOOP_INSERT mappings.
 *
 * @param array $config Configuration array containing parent and child tables with their parameters.
 * @return array An associative array where keys are table names, and values are arrays of mapped source names.
 */
  public function getTablesWithArrayParams($config) {
      $tables = [];

      // Check the parent table for "array" type parameters
      if (!empty($config['parent_table']) && !empty($config['parent_table']['data_params'])) {
          foreach ($config['parent_table']['data_params'] as $column => $param) {
              $mapping = $this->normalizeDataParamMapping($param, is_string($column) ? $column : null);

              // Only parameters explicitly marked for loop insert should create plural rows
              if (($mapping['array_handling'] ?? 'RAW_VALUE') === 'LOOP_INSERT' && $this->isArrayType($mapping['value'], $config['params'])) {
                  $tables[$config['parent_table']['table_name']][] = explode('.', (string) $mapping['value'])[0];
              }
          }
      }

      // Check child tables for "array" type parameters
      if (!empty($config['child_tables'])) {
          foreach ($config['child_tables'] as $childTable) {
              if (!empty($childTable['data_params'])) {
                  foreach ($childTable['data_params'] as $column => $param) {
                      $mapping = $this->normalizeDataParamMapping($param, is_string($column) ? $column : null);

                      // Only parameters explicitly marked for loop insert should create plural rows
                      if (($mapping['array_handling'] ?? 'RAW_VALUE') === 'LOOP_INSERT' && $this->isArrayType($mapping['value'], $config['params'])) {
                          $tables[$childTable['table_name']][] = explode('.', (string) $mapping['value'])[0];
                      }
                  }
              }
          }
      }

      // Remove duplicate parameter names and re-index the arrays
      foreach ($tables as $key => $value) {
          $value = array_unique($value);
          $tables[$key] = array_values($value);
      }

      return $tables;
  }

    /**
   * Check if a given parameter is of type "array".
   *
   * @param string $param The parameter name (possibly in dot notation).
   * @param array $params List of parameter definitions, each containing 'name' and 'type'.
   * @return bool Returns true if the parameter is of type "array", otherwise false.
   */
  public function isArrayType($param, $params) {
      if (is_array($param)) {
          $param = $param['value'] ?? $param['path'] ?? $param['column'] ?? '';
      }

      foreach ($params as $p) {
          // Extract the base name of the parameter and check if it exists in the params list as an "array" type
          if (! is_array($p) || empty($p['name']) || empty($p['type'])) {
              continue;
          }

          if (
              $p['name'] === explode('.', $param)[0]
              && (
                  $this->isArrayContainerApiBuilderParamType($this->normalizeApiBuilderParamType($p['type']))
                  || $this->isPrimitiveArrayApiBuilderParamType($this->normalizeApiBuilderParamType($p['type']))
              )
          ) {
              return true;
          }
      }
      return false;
  }

  /**
   * Retrieve a nested value from an associative array using a dot-separated path.
   *
   * @param array $data The associative array to search.
   * @param string $path The dot-separated key path (e.g., "user.profile.name").
   * @return mixed Returns the value at the specified path or null if not found.
   */
  public function getValueFromPath($data, $path)
  {
      // Split the path into an array of keys
      $keys = explode('.', $path);

      // Traverse the data array following the key path
      foreach ($keys as $key) {
          // If the key does not exist, return null
          if (!isset($data[$key])) return null;
          
          // Move deeper into the array
          $data = $data[$key];
      }

      // Return the found value
      return $data;
  }

  /**
   * Resolve a data_params value into the final payload value.
   *
   * Resolution order:
   * 1. Runtime variable syntax such as `{{ auth.id }}`
   * 2. Request input reference when the request contains the key
   * 3. Static value fallback
   *
   * @param mixed $value
   * @param Request $request
   * @return mixed
   */
  protected function resolveDataParamValue(mixed $value, Request $request, mixed $context = null): mixed
  {
      if (! is_string($value)) {
          return $value;
      }

      $normalized = trim($value);

      if ($normalized === '') {
          return $value;
      }

      if (str_contains($normalized, '{{')) {
          return $this->runtimeVariableParser->parse($value);
      }

      if ($context !== null) {
          $contextValue = $this->resolveValueFromContext($normalized, $context);

          if ($contextValue !== self::DATA_BUILDER_MISSING) {
              return $contextValue;
          }
      }

      if ($request->has($normalized)) {
          return $request->input($normalized);
      }

      $nestedValue = data_get($request->all(), $normalized, self::DATA_BUILDER_MISSING);

      if ($nestedValue !== self::DATA_BUILDER_MISSING) {
          return $nestedValue;
      }

      return $value;
  }

  protected function isRuntimeVariableExpression(mixed $value): bool
  {
      return is_string($value) && str_contains(trim($value), '{{');
  }

  /**
   * Resolve a mapped data parameter into a database-safe value.
   *
   * Arrays and objects are serialized to JSON for RAW_VALUE mappings so query
   * builder inserts always receive scalar values. LOOP_INSERT handling is
   * performed by the table row builder.
   *
   * @param mixed $value
   * @param Request $request
   * @param array<string, array{type?: string, required?: bool}> $flattenedParams
   * @return mixed
   */
  protected function resolveMappedDataParamValue(mixed $value, Request $request, array $flattenedParams, mixed $context = null): mixed
  {
      if ($this->isDataParamMappingDescriptor($value)) {
          $mapping = $this->normalizeDataParamMapping($value);
          $resolved = $this->resolveDataParamValue($mapping['value'] ?? null, $request, $context);

          return $this->serializeDatabaseValue($resolved);
      }

      $resolved = $this->resolveDataParamValue($value, $request, $context);

      return $this->serializeDatabaseValue($resolved);
  }

  protected function isDataParamMappingDescriptor(mixed $value): bool
  {
      return is_array($value) && (
          array_key_exists('value', $value)
          || array_key_exists('path', $value)
          || array_key_exists('array_handling', $value)
          || array_key_exists('arrayHandling', $value)
      );
  }

  protected function normalizeArrayHandlingMode(mixed $value): string
  {
      $normalized = strtoupper(trim((string) ($value ?? 'RAW_VALUE')));

      return $normalized === 'LOOP_INSERT' ? 'LOOP_INSERT' : 'RAW_VALUE';
  }

  protected function normalizeDataParamMapping(mixed $mapping, ?string $column = null): array
  {
      if (! is_array($mapping)) {
          return [
              'column' => $column,
              'value' => is_string($mapping) ? trim($mapping) : $mapping,
              'array_handling' => 'RAW_VALUE',
          ];
      }

      $value = $mapping['value'] ?? $mapping['path'] ?? $mapping['column'] ?? null;
      if (is_string($value)) {
          $value = trim($value);
      }

      return [
          'column' => $column ?? ($mapping['column'] ?? null),
          'value' => $value,
          'array_handling' => $this->normalizeArrayHandlingMode(
              $mapping['array_handling'] ?? $mapping['arrayHandling'] ?? null
          ),
      ];
  }

  protected function resolveValueFromContext(string $path, mixed $context): mixed
  {
      if (! is_array($context) && ! is_object($context)) {
          return self::DATA_BUILDER_MISSING;
      }

      $candidatePaths = [$path];

      if (str_contains($path, '.')) {
          $segments = explode('.', $path);

          while (count($segments) > 1) {
              array_shift($segments);
              $candidatePaths[] = implode('.', $segments);
          }
      }

      foreach (array_values(array_unique($candidatePaths)) as $candidatePath) {
          $resolved = data_get($context, $candidatePath, self::DATA_BUILDER_MISSING);

          if ($resolved !== self::DATA_BUILDER_MISSING) {
              return $resolved;
          }
      }

      return self::DATA_BUILDER_MISSING;
  }

  protected function isLoopInsertCollection(mixed $value): bool
  {
      return is_array($value) && array_is_list($value);
  }

  protected function isNestedLoopInsertCollection(array $value): bool
  {
      foreach ($value as $item) {
          if (is_array($item) || is_object($item)) {
              return true;
          }
      }

      return false;
  }

  protected function detectLoopInsertCollection(array $dataParams, Request $request, mixed $context = null): ?array
  {
      foreach ($dataParams as $mapping) {
          $normalized = $this->normalizeDataParamMapping($mapping);
          $sourceValue = $normalized['value'] ?? null;

          if (! is_string($sourceValue)) {
              continue;
          }

          $trimmed = trim($sourceValue);
          if ($trimmed === '' || ! str_contains($trimmed, '.')) {
              continue;
          }

          $root = explode('.', $trimmed)[0] ?? '';
          if ($root === '') {
              continue;
          }

          $candidate = $context !== null
              ? $this->resolveValueFromContext($root, $context)
              : (data_get($request->all(), $root, self::DATA_BUILDER_MISSING));

          if ($candidate === self::DATA_BUILDER_MISSING || ! is_array($candidate)) {
              continue;
          }

          $candidate = $this->normalizeNumericIndexedArray($candidate);

          if ($this->isLoopInsertCollection($candidate) && $this->isNestedLoopInsertCollection($candidate)) {
              return $candidate;
          }
      }

      return null;
  }

  protected function serializeDatabaseValue(mixed $value): mixed
  {
      if (is_array($value) || is_object($value)) {
          $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

          return $encoded === false ? '[]' : $encoded;
      }

      return $value;
  }

  protected function buildMappedTableRows(array $dataParams, Request $request, array $flattenedParams, mixed $context = null): array
  {
      if ($dataParams === []) {
          return [[]];
      }

      if ($context === null) {
          $nestedCollection = $this->detectLoopInsertCollection($dataParams, $request);

          if (is_array($nestedCollection) && $nestedCollection !== []) {
              $rows = [];

              foreach ($nestedCollection as $item) {
                  $nestedContext = is_array($item) || is_object($item) ? $item : ['value' => $item];
                  $nestedRows = $this->buildMappedTableRows($dataParams, $request, $flattenedParams, $nestedContext);

                  $rows = array_merge($rows, $nestedRows);
              }

              return $rows === [] ? [] : $rows;
          }
      }

      $staticRow = [];
      $loopColumns = [];
      $deferredRuntimeColumns = [];

      foreach ($dataParams as $column => $mapping) {
          $normalized = $this->normalizeDataParamMapping($mapping, is_string($column) ? $column : null);
          $targetColumn = is_string($normalized['column'] ?? null)
              ? (string) $normalized['column']
              : (is_string($column) ? $column : null);
          $sourceValue = $normalized['value'] ?? null;

          if ($targetColumn === null || $targetColumn === '') {
              continue;
          }

          if (
              ($normalized['array_handling'] ?? 'RAW_VALUE') !== 'LOOP_INSERT'
              && $this->isRuntimeVariableExpression($sourceValue)
          ) {
              $deferredRuntimeColumns[$targetColumn] = $sourceValue;
              continue;
          }

          $resolvedValue = $this->resolveDataParamValue($sourceValue, $request, $context);
          $resolvedValue = $this->normalizeNumericIndexedArray($resolvedValue);

          if (
              ($normalized['array_handling'] ?? 'RAW_VALUE') === 'LOOP_INSERT'
              && is_array($resolvedValue)
          ) {
              if (
                  $resolvedValue !== []
                  && $this->isLoopInsertCollection($resolvedValue)
                  && $this->isNestedLoopInsertCollection($resolvedValue)
              ) {
                  $rows = [];

                  foreach ($resolvedValue as $item) {
                      $nestedContext = is_array($item) || is_object($item) ? $item : ['value' => $item];
                      $nestedRows = $this->buildMappedTableRows($dataParams, $request, $flattenedParams, $nestedContext);

                      $rows = array_merge($rows, $nestedRows);
                  }

                  return $rows === [] ? [] : $rows;
              }

              $loopColumns[$targetColumn] = array_values($resolvedValue);
              continue;
          }

          $staticRow[$targetColumn] = $this->serializeDatabaseValue($resolvedValue);
      }

      if ($loopColumns === []) {
          $resolvedRow = $this->resolveRuntimeVariablesForParent($staticRow);

          foreach ($deferredRuntimeColumns as $column => $sourceValue) {
              $resolvedRuntimeValue = $this->resolveRuntimeVariablesForParent(
                  $this->resolveDataParamValue($sourceValue, $request, $context)
              );
              $resolvedRow[$column] = $this->serializeDatabaseValue($resolvedRuntimeValue);
          }

          return [$resolvedRow];
      }

      $rows = [[]];

      foreach ($loopColumns as $column => $values) {
          $nextRows = [];

          foreach ($rows as $row) {
              foreach ($values as $value) {
                  $nextRows[] = array_merge($row, [
                      $column => $this->serializeDatabaseValue($value),
                  ]);
              }
          }

          $rows = $nextRows;
      }

      if ($rows === []) {
          return [];
      }

      foreach ($rows as &$row) {
          $row = $this->resolveRuntimeVariablesForParent(array_merge($staticRow, $row));

          foreach ($deferredRuntimeColumns as $column => $sourceValue) {
              $resolvedRuntimeValue = $this->resolveRuntimeVariablesForChild(
                  $this->resolveDataParamValue($sourceValue, $request, $context)
              );
              $row[$column] = $this->serializeDatabaseValue($resolvedRuntimeValue);
          }
      }
      unset($row);

      return $rows;
  }

  protected function insertTableRows(
      $connection,
      string $tableName,
      array $rows,
      ?string $primaryKey = null,
      bool $returnInsertedIds = true
  ): array
  {
      $insertedIds = [];
      $primaryKey = is_string($primaryKey) ? trim($primaryKey) : '';

      foreach ($rows as $row) {
          if ($row === []) {
              continue;
          }

          $knownPrimaryKey = null;
          if ($primaryKey !== '' && array_key_exists($primaryKey, $row) && $row[$primaryKey] !== null && $row[$primaryKey] !== '') {
              $knownPrimaryKey = $row[$primaryKey];
          }

          if (! $returnInsertedIds) {
              $connection->table($tableName)->insert($row);
              continue;
          }

          if ($knownPrimaryKey !== null) {
              $connection->table($tableName)->insert($row);
              $insertedIds[] = $knownPrimaryKey;
              continue;
          }

          $insertedIds[] = $connection->table($tableName)->insertGetId($row);
      }

      return $insertedIds;
  }

    /**
   * Flattens a nested array structure into a single-level associative array
   * with dot-separated keys.
   *
   * @param array $array The nested array to flatten.
   * @param string $prefix The prefix for nested keys (used for recursion).
   * @return array A flattened associative array with dot-separated keys.
   */
  public function flattenArray($array, $prefix = '') {
      $result = [];

      foreach ($array as $item) {
          // Construct the key with the prefix
          $key = $prefix . $item['name'];

          // If the item has nested parameters (object or array), recursively flatten it
          if (isset($item['params']) && is_array($item['params'])) {
              $flattened = $this->flattenArray($item['params'], $key . '.');
              $result = array_merge($result, $flattened);
          }

          // Store the current item in the result with its type and required flag
          $result[$key] = [
              'type' => $item['type'],
              'required' => $item['required'] ?? false
          ];
      }

      return $result;
  }


    /**
   * Generates dynamic combinations from request data based on given keys and mapping parameters.
   *
   * @param array $request The request data containing arrays of values.
   * @param array $keys The keys in the request to generate combinations from.
   * @param array $mappingParam Mapping of the result structure to extract specific values.
   * @return array An array of dynamically generated combinations.
   */
  public function generateDynamicCombinations($request, $keys, $mappingParam)
  {
      $dataSets = [];

      // Extract relevant data from the request based on provided keys
      foreach ($keys as $key) {
          $dataSets[$key] = $request[$key] ?? [];
      }

      // Initialize result array with an empty set for recursive combination building
      $result = [[]];

      // Generate all possible combinations of the provided arrays
      foreach ($dataSets as $key => $dataSet) {
          $tempResult = [];
          foreach ($result as $partial) {
              foreach ($dataSet as $item) {
                  // Merge each existing combination with the new key-value pair
                  $tempResult[] = array_merge($partial, [$key => $item]);
              }
          }
          $result = $tempResult;
      }

      $resultData = [];

      // Map the generated combinations to the expected result format
      foreach ($result as $key => $value) {
          $dataRowResult = [];
          foreach ($mappingParam as $keyMap => $valueMap) {
              // Check if the mapping key exists in the provided keys before extracting the value
              if (in_array((explode('.', $valueMap)[0]), $keys)) {
                  $dataRowResult[$keyMap] = $this->getValueFromPath($value, $valueMap);
              }
          }
          $resultData[] = $dataRowResult;
      }

      return $resultData;
  }

}
