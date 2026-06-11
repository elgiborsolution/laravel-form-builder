<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\DatabasePresenceVerifier;

class ApiController extends Controller
{
    /**
     * Track whether the request is already running inside the datasource
     * validation connection scope.
     */
    protected bool $datasourceValidationConnectionActive = false;

    protected ?AfterHitApiDispatcher $afterHitApiDispatcher = null;

    public function __construct(
        protected DynamicApiConfigResolver $resolver,
        protected DataQueryService $dataQueryService,
        protected Pipeline $pipeline,
        protected DynamicVariableParser $runtimeVariableParser,
        protected MiddlewareConnectionResolver $middlewareConnectionResolver,
        protected ExecutionConnectionResolver $executionConnectionResolver,
        ?AfterHitApiDispatcher $afterHitApiDispatcher = null
    ) {
        $this->afterHitApiDispatcher = $afterHitApiDispatcher;
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

        return $this->withDatasourceValidationConnection(function () use ($request, $dynamicPath) {
            $resolvedRoute = $this->resolver->resolve($dynamicPath, $request->method());
            /** @var ApiConfig|null $apiConfigs */
            $apiConfigs = $resolvedRoute['config'];
            $id = $resolvedRoute['id'];

            if (empty($apiConfigs)) {
                return response()->json(['status' => 404, 'error'=> 'API Builder tidak ditemukan', 'message'=>'API Builder tidak ditemukan'], 404);
            }

            return $this->runDynamicMiddlewarePipeline(
                $request,
                $apiConfigs,
                function (Request $request) use ($apiConfigs, $id) {
                    $response = $this->dispatchResolvedRequest($request, $apiConfigs, $id);
                    $this->dispatchAfterHitApiEvent($apiConfigs, $request, $response, $id);

                    return $response;
                }
            );
        });
  }

  protected function applyRuntimeVariables(Request $request): void
  {
      $request->merge($this->resolveRuntimePayload($request->all()));
  }

  protected function resolveRuntimePayload(mixed $payload): mixed
  {
      if (is_array($payload)) {
          $resolved = [];

          foreach ($payload as $key => $value) {
              $resolved[$key] = $this->resolveRuntimePayload($value);
          }

          return $resolved;
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

          if (in_array($type, ['array', 'object'], true)) {
              if (! array_key_exists($name, $payload) || ! is_array($payload[$name])) {
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

  protected function prepareRuntimeRequest(Request $request, array $params): ?JsonResponse
  {
      try {
          $this->applyRuntimeVariables($request);
          $this->applyRuntimeDefaults($request, $params);
      } catch (InvalidRuntimeVariableException $e) {
          return response()->json([
              'status' => 422,
              'error' => $e->getMessage(),
              'message' => $e->getMessage(),
          ], 422);
      }

      return null;
  }

  protected function dispatchResolvedRequest(Request $request, ApiConfig $apiConfigs, mixed $id): JsonResponse
  {
        if($apiConfigs->method == 'POST'){
            return $this->store($request, $apiConfigs);
        }

        if($apiConfigs->method == 'GET'){
            return $this->dataQueryService->executeForApiConfig(
                $request,
                $apiConfigs,
                'api_config_q' . $apiConfigs->id
            );
        }

        if($apiConfigs->method == 'PUT'){
            if(empty($id)){

              return response()->json(['status' => 400, 'error'=> 'primary_key is required', 'message'=>'primary_key is required'], 400);
            }

            return $this->update($request, $apiConfigs, $id);
        }


        if($apiConfigs->method == 'DELETE'){
            if(empty($id)){

              return response()->json(['status' => 400, 'error'=> 'primary_key is required', 'message'=>'primary_key is required'], 400);
            }

            return $this->destroy($request, $apiConfigs, $id);
        }

        return response()->json(['data' => []], 200);
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
      $checkValidateRule = $this->validateRule($apiConfigs->params ?? [], $apiConfigs->parentTable->table_name);

      // Validate parent-level parameters
      if (count($checkValidateRule['parentValidate']) > 0) {
          $validated = $this->validateWithDatasourceConnection($request->all(), $checkValidateRule['parentValidate']);
      }

      // Validate child-level parameters
      if (count($checkValidateRule['childValidate']) > 0) {
          foreach ($checkValidateRule['childValidate'] as $key => $valueValidateRule) {
              foreach ($request[$key] ?? [] as $keyParam => $valueParam) {
                  // Perform validation on each child record
                  $validator = $this->makeDatasourceValidator($valueParam, $valueValidateRule);
                  if ($validator->fails()) {
                      return response()->json([
                          'error' => $validator->errors(),
                          'message' => 'Invalid payload ' . $key . ' at row ' . strval(intval($keyParam) + 1)
                      ], 400);
                  }
              }
          }
      }

      // Retrieve tables that contain array parameters
      $tableMutipleValue = $this->getTablesWithArrayParams($apiConfigs->toArray());
      $multipleInsertTable = array_keys($tableMutipleValue);
      $parentTable = $apiConfigs->parentTable->table_name;

      // Ensure that parent table does not have multiple records
      if (in_array($parentTable, $multipleInsertTable) && count($multipleInsertTable) > 1) {
          return response()->json([
              'status' => 400,
              'error' => 'Invalid Api Builder',
              'message' => 'The input data for the parent table cannot be plural (cannot use an array parameter).'
          ], 400);
      }

      $prefix = $connection->getTablePrefix();
      $childTables = $apiConfigs->childTables->toArray();

      // Flatten parameters for easier access
      $masterParam1Level = $this->flattenArray($apiConfigs->params);

      try {
          $connection->beginTransaction();
          $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);

          // Prepare parent table data for insertion
          $parentData = [];
          foreach ($apiConfigs->parentTable->data_params as $column => $paramPath) {
              $parentData[$column] = $this->resolveDataParamValue($paramPath, $request);
          }

          // Insert data into the parent table and get the generated ID
          $id = $connection->table($cleanParentTable)->insertGetId($parentData);

          $insertDataChild = [];

          // Process child table insertions
          foreach ($childTables as $key => $table) {
              $childData = [];
              $insert_type = 'singular';

              // Handle plural insertions (arrays of child records)
              if (in_array($table['table_name'], $multipleInsertTable)) {
                  $insert_type = 'plural';
                  $dataFilled = $this->generateDynamicCombinations($request->all(), $tableMutipleValue[$table['table_name']], $table['data_params']);

                  foreach ($dataFilled as $key => $value) {
                      // Set foreign key reference to parent ID
                      $dataFilled[$key][$table['foreign_key']] = $id;
                      foreach ($table['data_params'] as $keyMap => $valueMap) {
                          if (!in_array((explode('.', $valueMap)[0]), $tableMutipleValue[$table['table_name']])) {
                              $dataFilled[$key][$keyMap] = $this->resolveDataParamValue($valueMap, $request);
                          }
                      }
                  }
                  $childData = $dataFilled;

              } else {
                  // Handle singular insertions (single child record)
                  $childData[$table['foreign_key']] = $id;
                  foreach ($table['data_params'] as $key => $value) {
                      $childData[$key] = $this->resolveDataParamValue($value, $request);
                  }
              }

              // Prepare child table data for insertion
              if (count($childData) > 0) {
                  $insertDataChild[] = [
                      'table' => preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']),
                      'table_values' => $childData,
                      'insert_type' => $insert_type,
                  ];
              }
          }

          // Insert all child table data into the database
          foreach ($insertDataChild as $key => $value) {
              $connection->table($value['table'])->insert($value['table_values']);
          }

          $finalRecord = $this->normalizeAfterHitRecord(
              $connection->table($cleanParentTable)
                  ->where($apiConfigs->parentTable->primary_key, $id)
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

        $checkValidateRule = $this->validateRule($apiConfigs->params??[], $apiConfigs->parentTable->table_name, $id);

        if(count($checkValidateRule['parentValidate']) > 0){
          
          $validated = $this->validateWithDatasourceConnection($request->all(), $checkValidateRule['parentValidate']);
        }

        if(count($checkValidateRule['childValidate']) > 0){
          
            foreach ($checkValidateRule['childValidate'] as $key => $valueValidateRule) {

                foreach ($request[$key]??[] as $keyParam => $valueParam) {
                    $validator = $this->makeDatasourceValidator($valueParam,  $valueValidateRule);
                    if ($validator->fails()) {
                        return response()->json(['error' => $validator->errors(), 'message' => 'Invalid payload '.$key.' at row ' . strval(intval($keyParam) + 1)], 400);
                    }
                }

            }

        }

        $tableMutipleValue = $this->getTablesWithArrayParams($apiConfigs->toArray());
        $multipleInsertTable = array_keys($tableMutipleValue);
        $parentTable = $apiConfigs->parentTable->table_name;
        $primarykey = $apiConfigs->parentTable->primary_key;
        if(in_array($parentTable, $multipleInsertTable) && count($multipleInsertTable) > 1){
            return response()->json(['status' => 400, 'error' => 'Invalid Api Builder', 'message' => 'The input data for the parent table cannot be plural (cannot use an array parameter).'], 400);
        }

        $connection = $this->executionConnectionResolver->connection($request);
        $prefix = $connection->getTablePrefix();
        $childTables = $apiConfigs->childTables->toArray();

        $masterParam1Level = $this->flattenArray($apiConfigs->params);

        try {
            $connection->beginTransaction();
            $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);

            // insert parent table
            $parentData  = [];
            foreach ($apiConfigs->parentTable->data_params as $column => $paramPath) {
                $parentData[$column] = $this->resolveDataParamValue($paramPath, $request);
            }

            $connection->table($cleanParentTable)
                ->where($primarykey, $id)
                ->update($parentData);

            $insertDataChild = [];
            // Insert ke child tables berdasarkan mapping
            foreach ($childTables as $key => $table) {
                $childData = [];
                $insert_type = 'singular';
                if(in_array($table['table_name'], $multipleInsertTable)){
                  $insert_type = 'plural';
                  $dataFilled = $this->generateDynamicCombinations($request->all(), $tableMutipleValue[$table['table_name']], $table['data_params']);
                  foreach ($dataFilled as $key => $value) {
                      $dataFilled[$key][$table['foreign_key']] = $id;
                      foreach ($table['data_params'] as $keyMap => $valueMap) {
                        if(!in_array((explode('.', $valueMap)[0]), $tableMutipleValue[$table['table_name']])){
                          $dataFilled[$key][$keyMap] = $this->resolveDataParamValue($valueMap, $request);
                        } 
                      }
                  }
                  $childData = $dataFilled;

                }else{
                  $childData[$table['foreign_key']] = $id;
                  foreach ($table['data_params'] as $key => $value) {
                    $childData[$key] = $this->resolveDataParamValue($value, $request);
                  }

                }

                if(count($childData) > 0){

                   $insertDataChild[] = [
                        'table' => preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']),
                        'foreign_key' => $table['foreign_key'],
                        'table_values' => $childData,
                        'insert_type' => $insert_type,
                   ];
                }

            }

            foreach ($insertDataChild as $key => $value) {
              $connection->table($value['table'])->where($value['foreign_key'], $id)->delete();
              $connection->table($value['table'])->insert($value['table_values']);
            }

            $finalRecord = $this->normalizeAfterHitRecord(
                $connection->table($cleanParentTable)
                    ->where($primarykey, $id)
                    ->first()
            );

            $request->attributes->set('datasources.after_hit.action', 'update');
            $request->attributes->set('datasources.after_hit.result', $finalRecord);
            $request->attributes->set('datasources.after_hit.before_data', []);

            $connection->commit();
            return response()->json(["status" => 200, 'message' => 'Data has been successfully updated', 'data' => []], 201);
        } catch (\Exception $e) {
            $connection->rollBack();
            \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
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

        $checkValidateRule = $this->validateRule($apiConfigs->params??[], $apiConfigs->parentTable->table_name, $id);

        if(count($checkValidateRule['parentValidate']) > 0){
          
          $validated = $this->validateWithDatasourceConnection($request->all(), $checkValidateRule['parentValidate']);
        }

        if(count($checkValidateRule['childValidate']) > 0){
          
            foreach ($checkValidateRule['childValidate'] as $key => $valueValidateRule) {

                foreach ($request[$key]??[] as $keyParam => $valueParam) {
                    $validator = $this->makeDatasourceValidator($valueParam,  $valueValidateRule);
                    if ($validator->fails()) {
                        return response()->json(['error' => $validator->errors(), 'message' => 'Invalid payload '.$key.' at row ' . strval(intval($keyParam) + 1)], 400);
                    }
                }

            }

        }

        $parentTable = $apiConfigs->parentTable->table_name;
        $primarykey = $apiConfigs->parentTable->primary_key;

        $connection = $this->executionConnectionResolver->connection($request);
        $prefix = $connection->getTablePrefix();
        $childTables = $apiConfigs->childTables->toArray();

        $masterParam1Level = $this->flattenArray($apiConfigs->params);

        try {
            $connection->beginTransaction();
            $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);

            $beforeData = $this->normalizeAfterHitRecord(
                $connection->table($cleanParentTable)
                    ->where($primarykey, $id)
                    ->first()
            );

            $request->attributes->set('datasources.after_hit.action', 'delete');
            $request->attributes->set('datasources.after_hit.before_data', $beforeData);
            $request->attributes->set('datasources.after_hit.result', [
                'id' => $id,
                'deleted' => true,
            ]);

            $connection->table($cleanParentTable)
                ->where($primarykey, $id)
                ->delete();

            foreach ($childTables as $key => $table) {
                $tableChild = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']);
                $connection->table($tableChild)->where($table['foreign_key'], $id)->delete();

            }

            $connection->commit();
            return response()->json(["status" => 200, 'message' => 'Data has been successfully deleted', 'data' => []], 201);
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
  public function validateRule($params=[], $tableParent = '', $primaryKey = 0)
  {

      $validateRule = []; // Stores validation rules for direct parameters
      $paramArray = []; // Stores parameters that are of type 'array'
      $paramObject = []; // Stores parameters that are of type 'object
      // Iterate through each parameter to categorize them
      foreach ($params as $key => $value) {
          if (empty($value['type']) || empty($value['name'])) continue;

          // Separate array and object parameters
          if ($value['type'] == 'array') {
              $paramArray[] = $value;
          } else if ($value['type'] == 'object') {
              $value['type'] = 'array'; // Convert 'object' to 'array' for uniform processing
              $paramObject[] = $value;
          }

          // Generate validation rules for non-array/object parameters
          $validateRow = $this->findValidateRule($value, $tableParent, $primaryKey);
          $validateRule[$value['name']] = $validateRow;
      }

      // Process object-type parameters by handling nested properties
      foreach ($paramObject as $key => $value) {
          foreach ($value['params'] ?? [] as $keyParams => $valueParams) {
              if (empty($valueParams['type']) || empty($valueParams['name']) || in_array($valueParams['type'], ['array', 'object'])) continue;

              // Generate validation rules for object properties
              $validateRow = $this->findValidateRule($valueParams, $tableParent, $primaryKey);
              $validateRule[$value['name'] . '.' . $valueParams['name']] = $validateRow;
          }
      }

      $childValidateRule = []; // Stores validation rules for child elements in arrays
      $paramIsArray = []; // Stores names of parameters that are arrays

      // Process array-type parameters
      foreach ($paramArray as $key => $value) {
          $paramIsArray[] = $value['name'];
          $currentValidate = [];

          foreach ($value['params'] ?? [] as $keyParams => $valueParams) {
              if (empty($valueParams['type']) || empty($valueParams['name']) || in_array($valueParams['type'], ['array', 'object'])) continue;

              // Generate validation rules for array elements
              $validateRow = $this->findValidateRule($valueParams, $tableParent, $primaryKey);
              $currentValidate[$valueParams['name']] = $validateRow;
          }

          $childValidateRule[$value['name']] = $currentValidate;
      }

      // Return structured validation rules
      return [
          'parentValidate' => $validateRule,
          'childValidate' => $childValidateRule,
          'paramIsArray' => $paramIsArray
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
public function findValidateRule($rowParam, $tableParent, $primaryKey = 0)
{
    $prefix = DatabaseConnection::connection()->getTablePrefix(); // Get the database table prefix
    $value = $rowParam;

    // Determine if the parameter is required or nullable
    $dataValidate = !empty($value['required']) && $value['required'] ? 'required' : 'nullable';

    // Append the data type to the validation rule
    $dataValidate .= '|' . $value['type'];

    // Check if the field needs to be unique in the database
    if (!empty($value['unique']) && $value['unique'] && $primaryKey == 0) {
        // Remove the table prefix from the table name
        $table = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $tableParent);
        // Add uniqueness validation rule
        $unique = '|unique:' . DatabaseConnection::validationTable($table) . ',' . $value['name'];
        $dataValidate .= $unique;
    }

    return $dataValidate; // Return the validation rule string
}

/**
 * Validate payload using the package datasource connection.
 *
 * @param array $data
 * @param array $rules
 * @return array<string, mixed>
 */
protected function validateWithDatasourceConnection(array $data, array $rules): array
{
    return $this->withDatasourceValidationConnection(function () use ($data, $rules) {
        return Validator::make($data, $rules)->validate();
    });
}

/**
 * Build a validator instance bound to the package datasource connection.
 *
 * @param array $data
 * @param array $rules
 * @return \Illuminate\Contracts\Validation\Validator
 */
protected function makeDatasourceValidator(array $data, array $rules)
{
    return $this->withDatasourceValidationConnection(function () use ($data, $rules) {
        return Validator::make($data, $rules);
    });
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

    $connectionName = $this->getDatasourceConnectionName();
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
 * Resolve the package datasource connection name in a single place.
 *
 * @return string
 */
protected function getDatasourceConnectionName(): string
{
    try {
        $connection = DatabaseConnection::connection();

        if (method_exists($connection, 'getName')) {
            $name = $connection->getName();

            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }
    } catch (\Throwable $e) {
        // Fall back to the configured datasource connection name below.
    }

    return DatabaseConnection::name();
}

/**
 * Retrieve a list of tables that contain parameters of type "array".
 *
 * @param array $config Configuration array containing parent and child tables with their parameters.
 * @return array An associative array where keys are table names, and values are arrays of "array"-type parameter names.
 */
  public function getTablesWithArrayParams($config) {
      $tables = [];

      // Check the parent table for "array" type parameters
      if (!empty($config['parent_table']) && !empty($config['parent_table']['data_params'])) {
          foreach ($config['parent_table']['data_params'] as $column => $param) {
              // If the parameter is an array type, store it under the parent table
              if ($this->isArrayType($param, $config['params'])) {
                  $tables[$config['parent_table']['table_name']][] = explode('.', $param)[0];
              }
          }
      }

      // Check child tables for "array" type parameters
      if (!empty($config['child_tables'])) {
          foreach ($config['child_tables'] as $childTable) {
              if (!empty($childTable['data_params'])) {
                  foreach ($childTable['data_params'] as $column => $param) {
                      // If the parameter is an array type, store it under the child table
                      if ($this->isArrayType($param, $config['params'])) {
                          $tables[$childTable['table_name']][] = explode('.', $param)[0];
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
      foreach ($params as $p) {
          // Extract the base name of the parameter and check if it exists in the params list as an "array" type
          if ($p['name'] === explode('.', $param)[0] && $p['type'] === 'array') {
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
  protected function resolveDataParamValue(mixed $value, Request $request): mixed
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

      if ($request->has($normalized)) {
          return $request->input($normalized);
      }

      return $value;
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
