<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataTableBuilder;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use ESolution\DataSources\Support\Concerns\NormalizesJsonPayload;
use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DataTableBuilderController extends Controller
{
  use AppliesSearchFilter;
  use NormalizesJsonPayload;

  /**
  * Display list data table builder configuration
  *
  * @param Request $request
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function index(Request $request)
  {
    $dataTableBuilder = DataTableBuilder::query()->orderBy('id')->get()->toArray();
    foreach ($dataTableBuilder as $key => $value) {

      $dataTableBuilder[$key]['filters'] = $this->normalizeJsonPayload($value['filters'], 'data_table_builders.filters');
      $dataTableBuilder[$key]['columns'] = $this->normalizeJsonPayload($value['columns'], 'data_table_builders.columns');
      $dataTableBuilder[$key]['params'] = $this->normalizeJsonPayload($value['params'], 'data_table_builders.params');
      $dataTableBuilder[$key]['actions'] = $this->normalizeJsonPayload($value['actions'], 'data_table_builders.actions');
      $dataTableBuilder[$key]['is_central'] = true;
    }

    $headers = $request->header('x-tenant');
    // if it has tenant
    if(!empty($headers)){
        tenancy()->initialize($headers);
        //if the tenant has table
        if(DatabaseConnection::schema()->hasTable('data_table_builders')){

            $dataTableBuilderInTenant = DatabaseConnection::table('data_table_builders')->orderBy('id')->get();
            $dataTableBuilderInTenantMap = [];
            foreach ($dataTableBuilderInTenant as $key => $value) { 
              $dataArray = (array) $value;
              $dataArray['filters'] = $this->normalizeJsonPayload($dataArray['filters'] ?? null, 'tenant.data_table_builders.filters');
              $dataArray['columns'] = $this->normalizeJsonPayload($dataArray['columns'] ?? null, 'tenant.data_table_builders.columns');
              $dataArray['params'] = $this->normalizeJsonPayload($dataArray['params'] ?? null, 'tenant.data_table_builders.params');
              $dataArray['actions'] = $this->normalizeJsonPayload($dataArray['actions'] ?? null, 'tenant.data_table_builders.actions');
              $dataArray['is_central'] = false;

              $dataTableBuilderInTenantMap[$value->code] = $dataArray;
            }
           
            foreach ($dataTableBuilder as $key => $value) {

              if(!empty($dataTableBuilderInTenantMap[$value['code']])){

                  // replace central data with data tenant
                  $dataTableBuilder[$key] = $dataTableBuilderInTenantMap[$value['code']];
              }

            }
        }
        
    }

    $dataTableBuilder = $this->filterSearchRows($dataTableBuilder, $request, ['code', 'name', 'description'], 'data_table_builders');

        return response()->json(['data' => $dataTableBuilder], 200);
  }


  /**
  * Validate detail param when create new or update data table builder configuration
  *
  * @param Request $request
  *
  * @return \Illuminate\Http\JsonResponse || NUll
  */
  public function validateDetail($request)
  {

      $validateFilter = [
        'type' => ["required" , "string", "in:text,textarea,email,number,currency,date,datetime,select,radio,checkbox,switch,data-picker,dropdown"],
        'label' => 'required|string',
        'name' => 'required|string',
        'value' => 'nullable',
      'options' => 'nullable|array'
      ];
      foreach ($request->filters as $key => $value) {

          $validator = Validator::make($value, $validateFilter);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload filters at row '.strval(intval($key)+1)], 401);
          }
      }

      $validateColumn = [
        'header' => 'required|string',
        'detail' => 'required|string'
      ];
      foreach ($request->columns as $key => $value) {
          $validator = Validator::make($value, $validateColumn);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload columns at row '.strval(intval($key)+1)], 401);
          }
      }



      $validateFilter = [
        'label' => 'required|string',
        'type' => ["required" , "string", "in:link,emit"],
        'icon' => 'nullable|string',
        'class' => 'nullable|string',
        'url' =>  ['nullable', 'required_if:type,==,link', "string"],
        'event' =>  ['nullable', 'required_if:type,==,emit', "string"]
      ];
      foreach ($request->actions??[] as $key => $value) {

          $validator = Validator::make($value, $validateFilter);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload actions at row '.strval(intval($key)+1)], 401);
          }
      }

      $sourceType = (string) $request->input('params.source_type', 'data_source');
      $validateParams = [
        'enable_no' => 'nullable|boolean',
        'pagination' => 'nullable|boolean',
        'source_type' => 'nullable|in:data_source,custom_api',
        'data_source_id' => $sourceType === 'custom_api' ? 'nullable|integer' : 'required|integer',
        'data_source_name' => $sourceType === 'custom_api' ? 'nullable|string' : 'required|string',
        'api_config' => $sourceType === 'custom_api' ? 'required|array' : 'nullable|array',
      ];
      // foreach ($request->params as $key => $value) {

          $validator = Validator::make($request->params, $validateParams);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload params'], 401);
          }
      // }

      return null;
  }

  public function testCustomApi(Request $request)
  {
    $config = $request->validate(['api_config' => 'required|array'])['api_config'];
    $context = [];

    try {
      $rows = $this->executeCustomApiConfig($request, $config, [], $context);
      return response()->json(['success' => true, 'data' => $rows, 'count' => count($rows), 'message' => 'API connection successful. ' . count($rows) . ' records found.']);
    } catch (\Throwable $exception) {
      $error = $this->normalizeCustomApiTestError($exception, $context);
      Log::warning('Custom API test failed.', [
        'method' => $context['method'] ?? null,
        'url' => $context['url'] ?? null,
        'status' => $context['status'] ?? null,
        'exception' => get_class($exception),
        'detail' => $this->safeApiErrorMessage($exception),
      ]);

      $httpStatus = $error['data']['type'] === 'http_error'
        ? ($error['data']['status'] ?? 422)
        : 422;
      return response()->json($error, $httpStatus);
    }
  }

  public function executeCustomApi(Request $request)
  {
    $validated = $request->validate([
      'api_config' => 'required|array',
      'runtime_params' => 'nullable|array',
      'page' => 'nullable|integer|min:1',
      'per_page' => 'nullable|integer|min:1|max:100',
    ]);
    $config = $validated['api_config'];

    try {
      $rows = $this->executeCustomApiConfig($request, $config, $validated['runtime_params'] ?? []);
      $total = count($rows);
      $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 10)));
      $currentPage = max(1, (int) ($validated['page'] ?? 1));
      $offset = ($currentPage - 1) * $perPage;
      return response()->json([
        'data' => array_slice($rows, $offset, $perPage),
        'total' => $total,
        'current_page' => $currentPage,
        'per_page' => $perPage,
      ]);
    } catch (\Throwable $exception) {
      return response()->json(['message' => 'API request failed: ' . $this->safeApiErrorMessage($exception)], 422);
    }
  }

  protected function executeCustomApiConfig(Request $request, array $config, array $runtimeParams = [], array &$context = []): array
  {
    $validator = Validator::make($config, [
      'type' => 'required|in:internal,external',
      'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
      'url' => 'required|string|max:2048',
      'headers' => 'nullable|array', 'headers.*.key' => 'nullable|string|max:255', 'headers.*.value' => 'nullable|string|max:4096',
      'query_params' => 'nullable|array', 'query_params.*.key' => 'nullable|string|max:255', 'query_params.*.value' => 'nullable',
      'body_params' => 'nullable|array', 'body_params.*.key' => 'nullable|string|max:255', 'body_params.*.value' => 'nullable',
      'response_data_path' => 'nullable|string|max:255',
    ]);
    $validator->validate();

    $url = trim((string) $config['url']);
    $context = [
      'method' => strtoupper((string) ($config['method'] ?? '')),
      'url' => $this->sanitizeCustomApiUrl($url),
    ];
    if ($config['type'] === 'internal') {
      if (! str_starts_with($url, '/')) throw new \InvalidArgumentException('Internal routes must start with /.');
      if ($this->isCustomApiInternalEndpoint($url)) {
        throw new \InvalidArgumentException('Internal Custom API routes cannot target the Custom API test or executor endpoint.');
      }
    } elseif (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('/^https?:\/\//i', $url)) {
      throw new \InvalidArgumentException('External API URL must be a valid HTTP(S) URL.');
    }

    $headers = $this->keyValueRows($config['headers'] ?? []);
    if ($config['type'] === 'internal') {
      foreach (['authorization', 'x-tenant'] as $header) {
        if ($request->headers->has($header) && ! $this->hasCustomApiHeader($headers, $header)) {
          $headers[$header] = $request->header($header);
        }
      }
    }
    $query = $this->keyValueRows($config['query_params'] ?? []);
    foreach ($runtimeParams as $key => $value) {
      if (is_string($key) && $key !== '' && ! is_array($value) && ! is_object($value)) $query[$key] = $value;
    }
    $body = $this->keyValueRows($config['body_params'] ?? []);
    $method = strtoupper((string) $config['method']);
    $context = [
      'method' => $method,
      'url' => $this->sanitizeCustomApiUrl($url),
    ];
    if ($config['type'] === 'internal') {
      $internalResponse = $this->executeInternalCustomApiRoute($request, $method, $url, $headers, $query, $body);
      $status = $internalResponse['status'];
      $responseBody = $internalResponse['body'];
    } else {
      $client = Http::timeout(20)->withHeaders($headers);
      $response = in_array($method, ['POST', 'PUT', 'PATCH'], true)
        ? $client->send($method, $url, ['query' => $query, 'json' => $body])
        : $client->send($method, $url, ['query' => $query]);
      $status = $response->status();
      $responseBody = $response->body();
    }

    $context['status'] = $status;
    $context['response_body'] = $this->safeCustomApiResponseBody($responseBody);
    if ($status < 200 || $status >= 300) throw new \RuntimeException('Custom API returned an HTTP error.');

    try {
      $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      throw new \RuntimeException('Custom API returned an invalid JSON response.', 0, $exception);
    }
    $responseDataPath = trim((string) ($config['response_data_path'] ?? ''));
    $rows = $this->resolveResponseDataPath($payload, $responseDataPath);
    if (! is_array($rows) || ! array_is_list($rows)) {
      $context['response_data_path'] = $responseDataPath === '' ? null : $responseDataPath;
      $context['resolved_type'] = $this->customApiValueType($rows);
      $context['response'] = $context['response_body'];
      throw new \RuntimeException('Resolved API response is not an array.');
    }

    return $rows;
  }

  protected function executeInternalCustomApiRoute(
    Request $request,
    string $method,
    string $url,
    array $headers,
    array $query,
    array $body
  ): array {
    $server = ['HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $name => $value) {
      if (! is_scalar($value)) continue;
      $serverName = strtoupper(str_replace('-', '_', (string) $name));
      $server[$serverName === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_' . $serverName] = (string) $value;
    }

    foreach (['authorization', 'x-tenant'] as $name) {
      if ($request->headers->has($name) && ! $this->hasCustomApiHeader($headers, $name)) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = (string) $request->header($name);
      }
    }

    $route = $url;
    if ($query) $route .= (str_contains($route, '?') ? '&' : '?') . http_build_query($query);
    $content = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
      $server['CONTENT_TYPE'] = 'application/json';
      $content = json_encode($body, JSON_THROW_ON_ERROR);
    }

    $internalRequest = Request::create($route, $method, [], [], [], $server, $content);
    $internalRequest->setUserResolver(static fn () => $request->user());
    $kernel = app(HttpKernel::class);
    try {
      $response = $kernel->handle($internalRequest);
    } finally {
      app()->instance('request', $request);
    }

    return [
      'status' => $response->getStatusCode(),
      'body' => (string) $response->getContent(),
    ];
  }

  protected function hasCustomApiHeader(array $headers, string $name): bool
  {
    foreach (array_keys($headers) as $headerName) {
      if (strcasecmp((string) $headerName, $name) === 0) return true;
    }

    return false;
  }

  protected function isCustomApiInternalEndpoint(string $url): bool
  {
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    return (bool) preg_match('#(^|/)table-builder/custom-api/(?:test|execute)$#', $path);
  }

  protected function keyValueRows(array $rows): array
  {
    $values = [];
    foreach ($rows as $row) {
      $key = trim((string) ($row['key'] ?? ''));
      if ($key !== '') $values[$key] = $row['value'] ?? null;
    }
    return $values;
  }

  protected function resolveResponseDataPath(mixed $payload, string $path): mixed
  {
    if (trim($path) === '') return $payload;
    foreach (explode('.', trim($path)) as $segment) {
      if (! is_array($payload) || ! array_key_exists($segment, $payload)) return null;
      $payload = $payload[$segment];
    }
    return $payload;
  }

  protected function customApiValueType(mixed $value): string
  {
    if (is_array($value)) return array_is_list($value) ? 'array' : 'object';
    if (is_object($value)) return 'object';
    if ($value === null) return 'null';
    return gettype($value);
  }

  protected function safeApiErrorMessage(\Throwable $exception): string
  {
    $message = $exception->getMessage();
    if ($message === '' || strlen($message) > 160) return 'Unable to complete the request.';

    $message = preg_replace('/(bearer\s+)[a-z0-9._~+\/-]+/i', '$1[REDACTED]', $message) ?? $message;
    $message = preg_replace('/([?&](?:token|access_token|api[_-]?key|secret|password)=)[^&\s]*/i', '$1[REDACTED]', $message) ?? $message;
    return preg_replace('#://[^/@\s]+@#', '://[REDACTED]@', $message) ?? $message;
  }

  protected function normalizeCustomApiTestError(\Throwable $exception, array $context): array
  {
    $status = isset($context['status']) ? (int) $context['status'] : null;
    $detail = $this->safeApiErrorMessage($exception);
    $message = 'Custom API test failed.';
    $type = 'invalid_response';
    $exceptionMessage = strtolower($exception->getMessage());

    if (str_contains($exceptionMessage, 'resolved api response is not an array')) {
      $type = 'invalid_response';
      $message = 'Resolved API response is not an array. Please set Response Data Path to the array location, for example: data.';
    } elseif (str_contains($exceptionMessage, 'invalid json')) {
      $type = 'invalid_response';
      $message = 'Custom API returned an invalid JSON response.';
    } elseif ($status && $status >= 400) {
      $type = 'http_error';
      $message = 'Custom API returned HTTP ' . $status . ' ' . $this->httpStatusText($status) . '.';
    } elseif (str_contains($exceptionMessage, 'timed out') || str_contains($exceptionMessage, 'timeout')) {
      $type = 'timeout';
      $message = 'Custom API request timed out.';
    } elseif (str_contains($exceptionMessage, 'could not resolve') || str_contains($exceptionMessage, 'name or service not known') || str_contains($exceptionMessage, 'getaddrinfo')) {
      $type = 'dns_error';
      $message = 'Unable to resolve Custom API host.';
    } elseif (str_contains($exceptionMessage, 'ssl') || str_contains($exceptionMessage, 'certificate')) {
      $type = 'ssl_error';
      $message = 'Custom API SSL verification failed.';
    } elseif (str_contains($exceptionMessage, 'connection refused') || str_contains($exceptionMessage, 'failed to connect')) {
      $type = 'connection_error';
      $message = 'Unable to connect to Custom API host.';
    } elseif ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
      $type = 'connection_error';
      $message = 'Unable to connect to Custom API host.';
    }

    $data = array_filter([
      'type' => $type,
      'method' => $context['method'] ?? null,
      'url' => $context['url'] ?? null,
      'status' => $status,
      'response_body' => $context['response_body'] ?? null,
      'detail' => $detail,
    ], static fn ($value) => $value !== null);

    if (array_key_exists('response_data_path', $context)) {
      $data['response_data_path'] = $context['response_data_path'];
      $data['resolved_type'] = $context['resolved_type'];
      $data['response'] = $context['response'];
    }

    return ['success' => false, 'message' => $message, 'data' => $data];
  }

  protected function safeCustomApiResponseBody(string $body): mixed
  {
    $body = substr($body, 0, 16384);
    $decoded = json_decode($body, true);

    return json_last_error() === JSON_ERROR_NONE
      ? $this->redactSensitiveCustomApiValues($decoded)
      : preg_replace('/(bearer\s+)[a-z0-9._~+\/-]+/i', '$1[REDACTED]', $body);
  }

  protected function redactSensitiveCustomApiValues(mixed $value): mixed
  {
    if (! is_array($value)) return $value;

    foreach ($value as $key => $item) {
      if (preg_match('/(authorization|token|secret|password|cookie|api[_-]?key)/i', (string) $key)) {
        $value[$key] = '[REDACTED]';
      } else {
        $value[$key] = $this->redactSensitiveCustomApiValues($item);
      }
    }

    return $value;
  }

  protected function sanitizeCustomApiUrl(string $url): string
  {
    return preg_replace('/([?&](?:token|access_token|api[_-]?key|secret|password)=)[^&]*/i', '$1[REDACTED]', $url) ?? $url;
  }

  protected function httpStatusText(int $status): string
  {
    return match ($status) {
      401 => 'Unauthorized',
      403 => 'Forbidden',
      404 => 'Not Found',
      408 => 'Request Timeout',
      422 => 'Unprocessable Entity',
      429 => 'Too Many Requests',
      500 => 'Internal Server Error',
      502 => 'Bad Gateway',
      503 => 'Service Unavailable',
      504 => 'Gateway Timeout',
      default => 'Error',
    };
  }

  /**
   * Remove legacy operator data before persisting a builder payload.
   *
   * @param array<int, mixed> $filters
   * @return array<int, mixed>
   */
  protected function normalizeFiltersForSave(array $filters): array
  {
      return array_map(static function (mixed $filter): mixed {
          if (! is_array($filter)) {
              return $filter;
          }

          unset($filter['operator']);

          return $filter;
      }, $filters);
  }

  /**
   * Export complete Table Builder configurations as JSON.
   */
  public function export(Request $request)
  {
    $ids = $this->normalizeTableBuilderIds($request->input('ids', []));
    $query = DataTableBuilder::query()->orderBy('id');

    if ($ids !== []) $query->whereIn('id', $ids);

    $payload = $query->get()
      ->map(function (DataTableBuilder $builder): array {
        return [
          'code' => $builder->code,
          'name' => $builder->name,
          'columns' => $this->normalizeJsonPayload($builder->columns, 'data_table_builders.export.columns'),
          'filters' => $this->normalizeJsonPayload($builder->filters, 'data_table_builders.export.filters'),
          'params' => $this->normalizeJsonPayload($builder->params, 'data_table_builders.export.params'),
          'actions' => $this->normalizeJsonPayload($builder->actions, 'data_table_builders.export.actions'),
        ];
      })
      ->values()
      ->all();

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return response()->json(['message' => 'Failed to generate export file.'], 500);

    return response()->streamDownload(
      static function () use ($json): void { echo $json; },
      'table-builders-' . now()->format('Y-m-d_His') . '.json',
      ['Content-Type' => 'application/json']
    );
  }

  /**
   * Import Table Builder configurations using the same selected-row JSON flow
   * as Data Source and API Builder imports.
   */
  public function import(Request $request)
  {
    $rows = $request->input('rows');
    if (is_string($rows)) {
      try {
        $rows = json_decode($rows, true, 512, JSON_THROW_ON_ERROR);
      } catch (\JsonException) {
        return response()->json(['message' => 'Invalid JSON format in rows payload.'], 422);
      }
    }

    $validator = Validator::make(['rows' => $rows], [
      'rows' => ['required', 'array'],
      'rows.*' => ['required', 'array'],
    ]);
    if ($validator->fails()) {
      return response()->json([
        'message' => $validator->errors()->first('rows') ?: 'Invalid import format: rows must be an array.',
        'errors' => $validator->errors()->toArray(),
      ], 422);
    }
    if ($rows === []) return response()->json(['message' => 'No data to import.'], 422);

    $summary = ['selected' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0];
    $errors = [];
    $processedCodes = [];
    $connection = DatabaseConnection::connection();
    $connection->beginTransaction();

    try {
      foreach ($rows as $index => $row) {
        $rowNumber = $index + 1;
        $normalized = $this->normalizeImportedTableBuilderRow($row);
        $rowValidator = Validator::make($normalized, [
          'code' => ['required', 'string', 'max:255'],
          'name' => ['required', 'string', 'max:255'],
          'columns' => ['required', 'array'],
          'filters' => ['nullable', 'array'],
          'actions' => ['nullable', 'array'],
          'params' => ['required', 'array'],
        ]);

        if ($rowValidator->fails()) {
          $summary['failed']++;
          $errors[] = ['row' => $rowNumber, 'message' => $rowValidator->errors()->first()];
          continue;
        }

        $code = trim((string) $normalized['code']);
        if (isset($processedCodes[$code]) || DataTableBuilder::where('code', $code)->exists()) {
          $summary['skipped']++;
          $errors[] = ['row' => $rowNumber, 'message' => 'Table Builder code "' . $code . '" already exists.'];
          continue;
        }

        $sourceType = (string) ($normalized['params']['source_type'] ?? 'data_source');
        if ($sourceType === 'data_source') {
          $dataSource = $this->findImportedTableBuilderDataSource($normalized['params']);
          if ($dataSource === null) {
            $reference = trim((string) ($normalized['params']['data_source_name'] ?? $normalized['params']['data_source_id'] ?? 'unknown'));
            $summary['failed']++;
            $errors[] = ['row' => $rowNumber, 'message' => 'Table Builder import failed because the referenced Data Source "' . $reference . '" does not exist. Import or create the Data Source first.'];
            continue;
          }

          // IDs are environment-specific; retain the imported reference by
          // resolving it to the matching Data Source in this context.
          $normalized['params']['data_source_id'] = $dataSource->id;
          $normalized['params']['data_source_name'] = $dataSource->name;
        } elseif ($sourceType !== 'custom_api') {
          $summary['failed']++;
          $errors[] = ['row' => $rowNumber, 'message' => 'Invalid Table Builder source type.'];
          continue;
        }

        $rowRequest = Request::create('/', 'POST', $normalized + [
          'filters' => [], 'actions' => [], 'columns' => [], 'params' => [],
        ]);
        $invalid = $this->validateDetail($rowRequest);
        if ($invalid !== null) {
          $payload = $invalid->getData(true);
          $summary['failed']++;
          $errors[] = ['row' => $rowNumber, 'message' => $payload['message'] ?? 'Invalid Table Builder configuration.'];
          continue;
        }

        DataTableBuilder::create([
          'code' => $code,
          'name' => $normalized['name'],
          'columns' => $normalized['columns'],
          'filters' => $this->normalizeFiltersForSave($normalized['filters'] ?? []),
          'params' => $normalized['params'],
          'actions' => $normalized['actions'] ?? [],
        ]);
        $processedCodes[$code] = true;
        $summary['imported']++;
      }
      $connection->commit();
    } catch (\Throwable $exception) {
      $connection->rollBack();
      Log::error('Table Builder import failed.', ['exception' => $exception->getMessage()]);
      return response()->json(['message' => 'Failed to import Table Builders.'], 422);
    }

    return response()->json([
      'status' => 200,
      'message' => 'Import completed.',
      ...$summary,
      'summary' => $summary,
      'errors' => $errors,
    ]);
  }

  protected function normalizeImportedTableBuilderRow(array $row): array
  {
    foreach (['columns', 'filters', 'params', 'actions'] as $field) {
      $row[$field] = $this->normalizeJsonPayload($row[$field] ?? ($field === 'params' ? null : []), 'data_table_builders.import.' . $field);
    }

    return $row;
  }

  protected function findImportedTableBuilderDataSource(array $params): ?DataSource
  {
    $name = trim((string) ($params['data_source_name'] ?? ''));
    if ($name !== '') return DataSource::where('name', $name)->first();

    $id = $params['data_source_id'] ?? null;
    return is_numeric($id) ? DataSource::find((int) $id) : null;
  }

  protected function normalizeTableBuilderIds(mixed $ids): array
  {
    if (! is_array($ids)) return [];

    return array_values(array_filter(array_map(static function (mixed $id): ?int {
      return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }, $ids)));
  }


  /**
  * Create new data table builder configuration
  *
  * @param Request $request
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function store(Request $request)
  {
    $validated = $request->validate([
      'code' => 'required|string|unique:' . DatabaseConnection::validationTable('data_table_builders') . ',code',
      'name' => 'required|string',
      'filters' => 'nullable|array',
      'columns' => 'required|array',
      'actions' => 'nullable|array',
      'params' => 'required|array',
    ]);

    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }


    $dataTableBuilder = DataTableBuilder::create([
      'code' => $validated['code'],
      'name' => $validated['name'],
      'filters' => $this->normalizeFiltersForSave($validated['filters'] ?? []),
      'columns' => $validated['columns'],
      'params' => $validated['params'],
      'actions' => $validated['actions'],
    ]);


    return response()->json(["status" => 200, 'message' => 'Data table builder created', 'data'=>$dataTableBuilder], 201);
  }


  /**
  * Show some data table builder configuration
  *
  * @param Request $request, String $id (DataTableBuilder code)
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function show(Request $request, $id)
  {

    $headers = $request->header('x-tenant');
    $dataTableBuilder = DataTableBuilder::where('code', $id)->first();
    if (empty($dataTableBuilder)) {
        return response()->json(['error' => 'Data table builder not found', 'message' => 'Data table builder not found'], 400);
    }
    $queryParams = [];

    if(!empty($headers)){
        tenancy()->initialize($headers);
        //if the tenant has table
        if(DatabaseConnection::schema()->hasTable('data_table_builders')){
          $dataTableBuilderInTenant = DatabaseConnection::table('data_table_builders')->where('code', $id)->first();
          if(!empty($dataTableBuilderInTenant)) $dataTableBuilder = $dataTableBuilderInTenant;
        }
    }

    $dataTableBuilder->filters = $this->normalizeJsonPayload($dataTableBuilder->filters ?? null, 'data_table_builders.show.filters');
    $dataTableBuilder->columns = $this->normalizeJsonPayload($dataTableBuilder->columns ?? null, 'data_table_builders.show.columns');
    $dataTableBuilder->params = $this->normalizeJsonPayload($dataTableBuilder->params ?? null, 'data_table_builders.show.params');
    $dataTableBuilder->actions = $this->normalizeJsonPayload($dataTableBuilder->actions ?? null, 'data_table_builders.show.actions');

    return response()->json(["status" => 200, 'data'=>$dataTableBuilder], 200);
  }


  /**
  * Update data table builder configuration
  *
  * @param Request $request, String $id (DataTableBuilder code)
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function update(Request $request, $id)
  {
    $dataTableBuilder = DataTableBuilder::where('code', $id)->first();
    if (empty($dataTableBuilder)) {
        return response()->json(['error' => 'Data table builder not found', 'message' => 'Data table builder not found'], 400);
    }

    $validated = $request->validate([
      'code' => 'required|string|unique:' . DatabaseConnection::validationTable('data_table_builders') . ',code,'.$dataTableBuilder->id,
      'name' => 'required|string',
      'filters' => 'nullable|array',
      'columns' => 'required|array',
      'actions' => 'nullable|array',
      'params' => 'required|array',
    ]);
    
    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }

    $headers = $request->header('x-tenant');
    $alreadyUpdate = false;
    if(!empty($headers)){
        tenancy()->initialize($headers);
        //if the tenant has table
        if(DatabaseConnection::schema()->hasTable('data_table_builders')){
          $dataTableBuilder = DatabaseConnection::table('data_table_builders')->updateOrInsert(
                                   [ 'code' => $validated['code'] ],
                                  [
                                     'name' => $validated['name'],
                                     'filters' => json_encode($this->normalizeFiltersForSave($validated['filters'] ?? [])),
                                     'columns' => json_encode($validated['columns']),
                                     'params' => json_encode($validated['params']),
                                     'actions' => json_encode($validated['actions'])
                                   ]
                                );
          $dataTableBuilder = DataTableBuilder::where('code', $validated['code'])->first();
          $alreadyUpdate = true;
        }else{
          tenancy()->end();
        }
    }

    if(!$alreadyUpdate){

        $dataTableBuilder->update([
          'code' => $validated['code'],
          'name' => $validated['name'],
          'filters' => $this->normalizeFiltersForSave($validated['filters'] ?? []),
          'columns' => $validated['columns'],
          'params' => $validated['params'],
          'actions' => $validated['actions']
        ]);
    }

    
    return response()->json(["status" => 200, 'message' => 'Data table builder updated', 'data'=>$dataTableBuilder], 201);
  }

  /**
  * Delete data table builder configuration
  *
  * @param Request $request, String $id (DataTableBuilder code)
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function destroy(Request $request, $id)
  {
    $dataTableBuilder = DataTableBuilder::where('code', $id)->first();
    if (empty($dataTableBuilder)) {
      return response()->json(['error' => 'Data table builder not found'], 400);
    }
  


    $headers = $request->header('x-tenant');
    if(!empty($headers)){
        tenancy()->initialize($headers);
        //if the tenant has table
        if(DatabaseConnection::schema()->hasTable('data_table_builders')){
          $dataTableBuilderInTenant = DatabaseConnection::table('data_table_builders')->where('code', $id)->first();
          if(empty($dataTableBuilderInTenant)) return response()->json(['error' => 'You not allowed to delete data central'], 400);

          DatabaseConnection::table('data_table_builders')->where('code', $id)->delete();
        }else{

          tenancy()->end();
          $createdDate = date('Y-m-d', strtotime($dataTableBuilder->created_at));
          
          if($createdDate == date('Y-m-d')){

              $dataTableBuilder->delete();
          }else{

              return response()->json(['error' => 'You not allowed to delete data central'], 400);
          }

        }
    
    }else{


       $dataTableBuilder->delete();
    }

    return response()->json(['message' => 'Data table builder deleted']);
  }

}
