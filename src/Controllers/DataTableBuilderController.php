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
    $builders = $this->visibleBuilderRecords($request);
    $usedBy = $this->usedByMap($builders);
    foreach ($builders as &$builder) {
      $builder['used_by_count'] = count($usedBy[(string) $builder['code']] ?? []);
    }
    unset($builder);
    $builders = $this->filterSearchRows($builders, $request, ['code', 'name', 'description'], 'data_table_builders');
    foreach ($builders as &$builder) {
      if (($builder['type'] ?? 'table') !== 'tabs') continue;
      try {
        $builder = $this->resolveTabsForResponse($request, $builder);
      } catch (\RuntimeException $exception) {
        // Keep a broken legacy record visible so an administrator can edit or
        // delete it; valid records are always expanded recursively.
        $builder['resolution_error'] = $exception->getMessage();
      }
    }
    unset($builder);
    return response()->json(['data' => $builders], 200);
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
      $recordType = $request->input('type', 'table');
      if (! in_array($recordType, ['table', 'tabs'], true)) {
          return response()->json(['error' => ['type' => ['The type field must be table or tabs.']], 'message' => 'Invalid Table Builder type.'], 422);
      }
      if ($recordType === 'tabs') {
          return $this->validateTabsDetail($request);
      }

      $validateFilter = [
        'type' => ["required" , "string", "in:text,textarea,email,number,currency,date,datetime,select,radio,checkbox,switch,data-picker,dropdown,date_range,multiselect,autocomplete"],
        // Filter labels are presentation-only. Keep this relaxation scoped to
        // the direct filters[] item; nested option validation stays unchanged.
        'label' => 'nullable|string',
        'name' => 'required|string',
        'name_from' => 'nullable|string',
        'name_to' => 'nullable|string',
        'value' => 'nullable',
      'options' => 'nullable|array'
      ];
      foreach ($request->input('filters', []) ?? [] as $key => $value) {

          $validator = Validator::make($value, $validateFilter);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload filters at row '.strval(intval($key)+1)], 401);
          }
      }

      $validateColumn = [
        'header' => 'nullable|string',
        'detail' => 'required|string'
      ];
      foreach ($request->input('columns', []) ?? [] as $key => $value) {
          $validator = Validator::make($value, $validateColumn);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload columns at row '.strval(intval($key)+1)], 401);
          }
      }



      $validateFilter = [
        'label' => 'nullable|string',
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
        'data_source_id' => 'nullable|integer',
        'data_source_name' => 'nullable|string',
        'data_source_code' => 'nullable|string',
        'api_config' => $sourceType === 'custom_api' ? 'required|array' : 'nullable|array',
      ];
      // foreach ($request->params as $key => $value) {

          $params = $request->input('params', []);
          $validator = Validator::make(is_array($params) ? $params : [], $validateParams);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload params'], 401);
          }
          if ($sourceType === 'data_source'
              && empty($params['data_source_code'])
              && empty($params['data_source_name'])
              && empty($params['data_source_id'])) {
              return response()->json(['error' => ['params.data_source_code' => ['A data source is required.']], 'message' => 'Invalid payload params'], 422);
          }
      // }

      return null;
  }

  /** Validate and canonicalize a tabs record without requiring ordinary table fields. */
  protected function validateTabsDetail(Request $request)
  {
    $tabs = $request->input('tabs');
    $validator = Validator::make($request->all(), [
      'code' => 'required|string|max:255',
      'name' => 'required|string|max:255',
      'default_tab' => 'required|string|max:255',
      'tabs' => 'required|array|min:1',
      'tabs.*' => 'required|array',
    ]);
    if ($validator->fails()) {
      return response()->json(['error' => $validator->errors(), 'message' => 'Invalid Tab Builder configuration.'], 422);
    }

    $recordCode = trim((string) $request->input('code'));
    $keys = [];
    foreach ($tabs as $index => $tab) {
      $row = $index + 1;
      $tabValidator = Validator::make($tab, [
        'key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
        'label' => 'required|string|max:255',
        'type' => 'required|in:table,tabs',
        'table_builder_code' => 'required|string|max:255',
      ]);
      if ($tabValidator->fails()) {
        return response()->json(['error' => $tabValidator->errors(), 'message' => 'Invalid Tab Builder tab at row ' . $row . '.'], 422);
      }

      $key = trim((string) $tab['key']);
      if (in_array($key, $keys, true)) {
        return response()->json(['error' => ['tabs.' . $index . '.key' => ['Tab keys must be unique within a Tab Builder.']], 'message' => 'Duplicate tab key at row ' . $row . '.'], 422);
      }
      $keys[] = $key;

      $targetCode = trim((string) $tab['table_builder_code']);
      if ($targetCode === $recordCode) {
        return response()->json(['error' => ['tabs.' . $index . '.table_builder_code' => ['A Tab Builder cannot reference itself.']], 'message' => 'Tab Builder references contain a cycle.'], 422);
      }

      $target = $this->findVisibleBuilder($request, $targetCode);
      if ($target === null) {
        return response()->json(['error' => ['tabs.' . $index . '.table_builder_code' => ['The selected Table Builder does not exist.']], 'message' => 'Tab Builder reference "' . $targetCode . '" was not found.'], 422);
      }
      $targetType = $this->normalizedBuilderType($target);
      if ($targetType !== $tab['type']) {
        return response()->json(['error' => ['tabs.' . $index . '.type' => ['The selected record type does not match this tab.']], 'message' => 'Tab Builder reference type does not match at row ' . $row . '.'], 422);
      }

      if ($tab['type'] === 'tabs') {
        foreach (['source_type', 'default_params', 'count'] as $unsupported) {
          if (array_key_exists($unsupported, $tab)) {
            return response()->json(['error' => ['tabs.' . $index . '.' . $unsupported => ['Container tabs cannot define table parameters or counts.']], 'message' => 'Container tabs only accept a Tab Builder reference.'], 422);
          }
        }
        if ($this->tabBuilderGraphReaches($request, $targetCode, $recordCode, [])) {
          return response()->json(['error' => ['tabs.' . $index . '.table_builder_code' => ['This reference would create a Tab Builder cycle.']], 'message' => 'Tab Builder references contain a cycle.'], 422);
        }
        continue;
      }

      $params = $this->normalizeJsonPayload($target['params'] ?? null, 'data_table_builders.reference.params');
      $sourceType = (string) ($params['source_type'] ?? 'data_source');
      if (! in_array($sourceType, ['data_source', 'custom_api'], true)) {
        return response()->json(['error' => ['tabs.' . $index . '.source_type' => ['The referenced table has an invalid source type.']], 'message' => 'Invalid Table Builder source type.'], 422);
      }
      if (isset($tab['default_params'])) {
        if (! $this->isScalarParameterMap($tab['default_params'])) {
          return response()->json(['error' => ['tabs.' . $index . '.default_params' => ['Default parameters must be key/value pairs with scalar values.']], 'message' => 'Tab default parameters must be scalar values.'], 422);
        }
      }
      if (isset($tab['count'])) {
        if (! is_array($tab['count'])) {
          return response()->json(['error' => ['tabs.' . $index . '.count' => ['Count configuration must be an object.']], 'message' => 'Invalid Tab Builder count configuration.'], 422);
        }
        $countSource = (string) ($tab['count']['source_type'] ?? $sourceType);
        $countMode = $tab['count']['mode'] ?? null;
        if ($countMode !== null && ! in_array($countMode, ['automatic', 'manual'], true)) {
          return response()->json(['error' => ['tabs.' . $index . '.count.mode' => ['Count mode must be automatic or manual.']], 'message' => 'Invalid Tab Builder count mode.'], 422);
        }
        if (! in_array($countSource, ['data_source', 'custom_api'], true)) {
          return response()->json(['error' => ['tabs.' . $index . '.count.source_type' => ['Select a Data Source or custom API for the count.']], 'message' => 'Invalid Tab Builder count source.'], 422);
        }
        if ($countMode === null && $countSource !== $sourceType) {
          return response()->json(['error' => ['tabs.' . $index . '.count.source_type' => ['Count source must match the referenced table source.']], 'message' => 'Invalid Tab Builder count source.'], 422);
        }
        if (isset($tab['count']['path']) && ! is_string($tab['count']['path'])) {
          return response()->json(['error' => ['tabs.' . $index . '.count.path' => ['The count path must be a string.']], 'message' => 'Invalid Tab Builder count path.'], 422);
        }
        if ($countMode === 'automatic') {
          if ($sourceType !== 'data_source' || empty($params['pagination'])) {
            return response()->json(['error' => ['tabs.' . $index . '.count.mode' => ['Automatic counts require a paginated Data Source table. Configure a manual count instead.']], 'message' => 'Automatic count is unavailable for the selected table.'], 422);
          }
          continue;
        }
        if (isset($tab['count']['default_params']) && ! $this->isScalarParameterMap($tab['count']['default_params'])) {
          return response()->json(['error' => ['tabs.' . $index . '.count.default_params' => ['Default parameters must be key/value pairs with scalar values.']], 'message' => 'Invalid count parameters.'], 422);
        }
        if ($countMode === 'manual') {
          $manualValidator = Validator::make($tab['count'], [
            'path' => 'required|string|max:255',
            'default_params' => 'nullable|array',
            'data_source_code' => 'nullable|string|max:255',
            'data_source_id' => 'nullable|integer',
          ]);
          if ($manualValidator->fails()) {
            return response()->json(['error' => $manualValidator->errors(), 'message' => 'Invalid manual count configuration.'], 422);
          }
          if ($countSource === 'data_source' && empty($tab['count']['data_source_code']) && empty($tab['count']['data_source_id'])) {
            return response()->json(['error' => ['tabs.' . $index . '.count.data_source_code' => ['A count Data Source is required.']], 'message' => 'Select a Data Source for the manual count.'], 422);
          }
        }
        if ($countSource === 'custom_api') {
          $countValidator = Validator::make($tab['count'], [
            'api_config' => 'required|array',
            'default_params' => 'nullable|array',
            'path' => 'nullable|string|max:255',
          ]);
          if ($countValidator->fails()) {
            return response()->json(['error' => $countValidator->errors(), 'message' => 'Invalid custom API count configuration.'], 422);
          }
          if (isset($tab['count']['default_params']) && ! $this->isScalarParameterMap($tab['count']['default_params'])) {
            return response()->json(['error' => ['tabs.' . $index . '.count.default_params' => ['Default parameters must be key/value pairs with scalar values.']], 'message' => 'Invalid custom API count parameters.'], 422);
          }
          $apiValidator = Validator::make($tab['count']['api_config'], [
            'type' => 'required|in:internal,external',
            'method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'url' => 'required|string|max:2048',
            'headers' => 'nullable|array',
            'headers.*.key' => 'nullable|string|max:255',
            'headers.*.value' => 'nullable|string|max:4096',
            'query_params' => 'nullable|array',
            'query_params.*.key' => 'nullable|string|max:255',
            'query_params.*.value' => 'nullable',
            'body_params' => 'nullable|array',
            'body_params.*.key' => 'nullable|string|max:255',
            'body_params.*.value' => 'nullable',
          ]);
          if ($apiValidator->fails()) {
            return response()->json(['error' => $apiValidator->errors(), 'message' => 'Invalid custom API count request settings.'], 422);
          }
        }
      }
    }

    if (! in_array((string) $request->input('default_tab'), $keys, true)) {
      return response()->json(['error' => ['default_tab' => ['The default tab must match one of the tab keys.']], 'message' => 'Invalid Tab Builder default tab.'], 422);
    }

    return null;
  }

  protected function isScalarParameterMap(mixed $params): bool
  {
    if (! is_array($params)) return false;
    foreach ($params as $key => $value) {
      if (! is_string($key) || trim($key) === '' || ($value !== null && ! is_scalar($value))) return false;
    }
    return true;
  }

  /** Legacy rows without a type remain ordinary tables. */
  protected function normalizedBuilderType(array $record): string
  {
    return ($record['type'] ?? null) === 'tabs' ? 'tabs' : 'table';
  }

  /** Normalize a model or tenant query row to the API's JSON-friendly shape. */
  protected function normalizeBuilderRecord(mixed $record): ?array
  {
    if ($record === null) return null;
    $data = is_object($record) && method_exists($record, 'toArray')
      ? $record->toArray()
      : (array) $record;
    foreach (['columns', 'filters', 'params', 'actions', 'tabs'] as $field) {
      if (array_key_exists($field, $data)) {
        $data[$field] = $this->normalizeJsonPayload($data[$field], 'data_table_builders.' . $field);
      }
    }
    $data['type'] = $this->normalizedBuilderType($data);
    if ($data['type'] === 'table') {
      unset($data['default_tab'], $data['tabs']);
    } else {
      $data['tabs'] = is_array($data['tabs'] ?? null) ? $data['tabs'] : [];
      $data['default_tab'] = $data['default_tab'] ?? null;
      unset($data['columns'], $data['filters'], $data['params'], $data['actions']);
    }
    return $data;
  }

  /** Resolve a code using the current tenant override first, then central data. */
  protected function findVisibleBuilder(Request $request, string $code): ?array
  {
    $central = DataTableBuilder::where('code', $code)->first();
    $headers = $request->header('x-tenant');
    if (! empty($headers)) {
      tenancy()->initialize($headers);
      if (DatabaseConnection::schema()->hasTable('data_table_builders')) {
        $tenant = DatabaseConnection::table('data_table_builders')->where('code', $code)->first();
        if ($tenant !== null) {
          $record = $this->normalizeBuilderRecord($tenant);
          if ($record !== null) $record['is_central'] = false;
          return $record;
        }
      }
    }
    $record = $this->normalizeBuilderRecord($central);
    if ($record !== null) $record['is_central'] = true;
    return $record;
  }

  /** Central records with tenant overrides applied, matching the existing list behavior. */
  protected function visibleBuilderRecords(Request $request): array
  {
    $records = [];
    foreach (DataTableBuilder::query()->orderBy('id')->get() as $builder) {
      $record = $this->normalizeBuilderRecord($builder);
      if ($record !== null) {
        $record['is_central'] = true;
        $records[$record['code']] = $record;
      }
    }

    $headers = $request->header('x-tenant');
    if (! empty($headers)) {
      tenancy()->initialize($headers);
      if (DatabaseConnection::schema()->hasTable('data_table_builders')) {
        foreach (DatabaseConnection::table('data_table_builders')->orderBy('id')->get() as $builder) {
          $record = $this->normalizeBuilderRecord($builder);
          if ($record !== null && isset($records[$record['code']])) {
            $record['is_central'] = false;
            $records[$record['code']] = $record;
          }
        }
      }
    }
    return array_values($records);
  }

  /** Which tabs records link to a code, used by the editor and delete guard. */
  protected function usedByForCode(string $code, Request $request): array
  {
    return $this->usedByMap($this->visibleBuilderRecords($request))[$code] ?? [];
  }

  /** Build usage metadata in one pass for list responses. */
  protected function usedByMap(array $records): array
  {
    $usedBy = [];
    foreach ($records as $record) {
      if (($record['type'] ?? 'table') !== 'tabs') continue;
      foreach ($record['tabs'] ?? [] as $tab) {
        $code = $tab['table_builder_code'] ?? null;
        if (! is_string($code) || $code === '') continue;
        $entry = ['code' => $record['code'], 'name' => $record['name'], 'type' => 'tabs'];
        if (! in_array($entry, $usedBy[$code] ?? [], true)) $usedBy[$code][] = $entry;
      }
    }
    return $usedBy;
  }

  /** True when following stored container references from $code reaches $target. */
  protected function tabBuilderGraphReaches(Request $request, string $code, string $target, array $visited): bool
  {
    if ($code === $target) return true;
    if (isset($visited[$code])) return false;
    $visited[$code] = true;
    $record = $this->findVisibleBuilder($request, $code);
    if ($record === null || $this->normalizedBuilderType($record) !== 'tabs') return false;
    foreach ($record['tabs'] ?? [] as $tab) {
      if (($tab['type'] ?? null) === 'tabs'
          && $this->tabBuilderGraphReaches($request, (string) ($tab['table_builder_code'] ?? ''), $target, $visited)) {
        return true;
      }
    }
    return false;
  }

  /** The stable route identifier for a Data Source, including legacy ID-only bindings. */
  protected function dataSourceCode(array $params): ?string
  {
    $code = trim((string) ($params['data_source_code'] ?? ''));
    if ($code !== '') return $code;
    $name = trim((string) ($params['data_source_name'] ?? ''));
    if ($name !== '') return $name;
    $id = $params['data_source_id'] ?? null;
    if (is_numeric($id)) {
      $source = DataSource::find((int) $id);
      if ($source !== null) return (string) $source->name;
      return (string) $id;
    }
    return null;
  }

  /** Store only references and tab-owned settings; derive table source/count bindings from the linked table. */
  protected function normalizeTabsForSave(Request $request, array $tabs): array
  {
    $normalized = [];
    foreach ($tabs as $tab) {
      $item = [
        'key' => trim((string) $tab['key']),
        'label' => trim((string) $tab['label']),
        'type' => $tab['type'],
        'table_builder_code' => trim((string) $tab['table_builder_code']),
      ];
      if ($tab['type'] === 'tabs') {
        $normalized[] = $item;
        continue;
      }

      $table = $this->findVisibleBuilder($request, $item['table_builder_code']);
      $tableParams = $this->normalizeJsonPayload($table['params'] ?? null, 'data_table_builders.reference.params');
      $sourceType = (string) ($tableParams['source_type'] ?? 'data_source');
      $item['source_type'] = $sourceType;
      $defaultParams = is_array($tab['default_params'] ?? null) ? $tab['default_params'] : [];
      $item['default_params'] = $defaultParams;

      if (isset($tab['count'])) {
        $item['count'] = $this->normalizeTabCount($tab['count'], $tableParams, $defaultParams);
      }
      $normalized[] = $item;
    }
    return $normalized;
  }

  /** Additive count modes; records without a mode retain their original binding contract. */
  protected function normalizeTabCount(array $count, array $tableParams, array $tabParams): array
  {
    $tableSource = (string) ($tableParams['source_type'] ?? 'data_source');
    $mode = $count['mode'] ?? null;
    if ($mode === 'automatic') {
      if ($tableSource === 'data_source' && ! empty($tableParams['pagination'])) {
        $payload = ['mode' => 'automatic', 'source_type' => 'data_source', 'default_params' => $tabParams, 'path' => 'total'];
        $sourceCode = $this->dataSourceCode($tableParams);
        if ($sourceCode !== null) $payload['data_source_code'] = $sourceCode;
        return $payload;
      }
      // A changed referenced table can no longer provide automatic counts.
      $mode = 'manual';
    }
    $sourceType = $mode === 'manual' ? (string) ($count['source_type'] ?? $tableSource) : $tableSource;
    $payload = ['source_type' => $sourceType, 'path' => trim((string) ($count['path'] ?? ''))];
    if ($mode !== null) $payload['mode'] = $mode;
    if ($sourceType === 'data_source') {
      $sourceCode = $this->dataSourceCode($mode === 'manual' ? $count : $tableParams);
      if ($sourceCode !== null) $payload['data_source_code'] = $sourceCode;
      $payload['default_params'] = $mode === 'manual'
        ? (is_array($count['default_params'] ?? null) ? $count['default_params'] : $tabParams) : $tabParams;
    } else {
      $apiConfig = is_array($count['api_config'] ?? null) ? $count['api_config'] : [];
      unset($apiConfig['response_data_path']);
      $payload['api_config'] = $apiConfig;
      $payload['default_params'] = is_array($count['default_params'] ?? null) ? $count['default_params'] : [];
    }
    return $payload;
  }

  /** Fill the nested response from shared records without copying child data into storage. */
  protected function resolveTabsForResponse(Request $request, array $record, array $path = []): array
  {
    if (($record['type'] ?? 'table') !== 'tabs') return $record;
    $code = (string) ($record['code'] ?? '');
    if ($code !== '' && in_array($code, $path, true)) {
      throw new \RuntimeException('Tab Builder references contain a cycle.');
    }
    if ($code !== '') $path[] = $code;

    $resolvedTabs = [];
    foreach ($record['tabs'] ?? [] as $tab) {
      $targetCode = (string) ($tab['table_builder_code'] ?? '');
      $target = $this->findVisibleBuilder($request, $targetCode);
      if ($target === null) {
        throw new \RuntimeException('Tab Builder reference "' . $targetCode . '" was not found.');
      }
      $type = $this->normalizedBuilderType($target);
      if (($tab['type'] ?? null) !== $type) {
        throw new \RuntimeException('Tab Builder reference type does not match for "' . $targetCode . '".');
      }
      $item = [
        'key' => $tab['key'],
        'label' => $tab['label'],
        'type' => $type,
        'table_builder_code' => $targetCode,
      ];
      if ($type === 'tabs') {
        $resolvedChild = $this->resolveTabsForResponse($request, $target, $path);
        $item['default_tab'] = $resolvedChild['default_tab'] ?? null;
        $item['tabs'] = $resolvedChild['tabs'] ?? [];
      } else {
        $tableParams = $this->normalizeJsonPayload($target['params'] ?? null, 'data_table_builders.reference.params');
        $sourceType = (string) ($tableParams['source_type'] ?? 'data_source');
        $item['source_type'] = $sourceType;
        if (isset($tab['default_params'])) $item['default_params'] = $tab['default_params'];
        if (isset($tab['count'])) {
          $item['count'] = $this->normalizeTabCount($tab['count'], $tableParams, $item['default_params'] ?? []);
        }
      }
      $resolvedTabs[] = $item;
    }
    $record['tabs'] = $resolvedTabs;
    return $record;
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
      'raw_response' => 'nullable|boolean',
    ]);
    $config = $validated['api_config'];

    try {
      $context = [];
      $rows = $this->executeCustomApiConfig($request, $config, $validated['runtime_params'] ?? [], $context, (bool) ($validated['raw_response'] ?? false));
      if (($validated['raw_response'] ?? false) === true || ($validated['raw_response'] ?? false) === 1 || ($validated['raw_response'] ?? false) === '1') {
        return response()->json($rows);
      }
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

  protected function executeCustomApiConfig(Request $request, array $config, array $runtimeParams = [], array &$context = [], bool $returnRawResponse = false): mixed
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
    if ($returnRawResponse) return $payload;

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
        $type = $this->normalizedBuilderType($builder->toArray());
        $payload = [
          'type' => $type,
          'code' => $builder->code,
          'name' => $builder->name,
        ];
        if ($type === 'tabs') {
          $payload['default_tab'] = $builder->default_tab;
          $payload['tabs'] = $this->normalizeJsonPayload($builder->tabs, 'data_table_builders.export.tabs');
        } else {
          $payload['columns'] = $this->normalizeJsonPayload($builder->columns, 'data_table_builders.export.columns');
          $payload['filters'] = $this->normalizeJsonPayload($builder->filters, 'data_table_builders.export.filters');
          $payload['params'] = $this->normalizeJsonPayload($builder->params, 'data_table_builders.export.params');
          $payload['actions'] = $this->normalizeJsonPayload($builder->actions, 'data_table_builders.export.actions');
        }
        return $payload;
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

    $rows = $this->orderImportedTableBuilderRows($rows);
    $summary = ['selected' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0];
    $errors = [];
    $processedCodes = [];
    $connection = DatabaseConnection::connection();
    $connection->beginTransaction();

    try {
      foreach ($rows as $index => $row) {
        $rowNumber = (int) ($row['__import_row'] ?? $index + 1);
        unset($row['__import_row']);
        $normalized = $this->normalizeImportedTableBuilderRow($row);
        $type = $this->normalizedBuilderType($normalized);
        $rowValidator = Validator::make($normalized, [
          'code' => ['required', 'string', 'max:255'],
          'name' => ['required', 'string', 'max:255'],
          'type' => ['nullable', 'in:table,tabs'],
          'columns' => $type === 'tabs' ? ['sometimes'] : ['required', 'array'],
          'filters' => ['nullable', 'array'],
          'actions' => ['nullable', 'array'],
          'params' => $type === 'tabs' ? ['sometimes'] : ['required', 'array'],
          'default_tab' => $type === 'tabs' ? ['required', 'string', 'max:255'] : ['nullable'],
          'tabs' => $type === 'tabs' ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
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

        if ($type === 'table') {
          $sourceType = (string) ($normalized['params']['source_type'] ?? 'data_source');
          if ($sourceType === 'data_source') {
            $dataSource = $this->findImportedTableBuilderDataSource($normalized['params']);
            if ($dataSource === null) {
              $reference = trim((string) ($normalized['params']['data_source_code'] ?? $normalized['params']['data_source_name'] ?? $normalized['params']['data_source_id'] ?? 'unknown'));
              $summary['failed']++;
              $errors[] = ['row' => $rowNumber, 'message' => 'Table Builder import failed because the referenced Data Source "' . $reference . '" does not exist. Import or create the Data Source first.'];
              continue;
            }

            // New exports use the stable route code. Older ID-based exports
            // are resolved in this environment and keep their old fields.
            if (empty($normalized['params']['data_source_code'])) {
              $normalized['params']['data_source_id'] = $dataSource->id;
              $normalized['params']['data_source_name'] = $dataSource->name;
            } else {
              $normalized['params']['data_source_code'] = $dataSource->name;
            }
          } elseif ($sourceType !== 'custom_api') {
            $summary['failed']++;
            $errors[] = ['row' => $rowNumber, 'message' => 'Invalid Table Builder source type.'];
            continue;
          }
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

        $attributes = [
          'code' => $code,
          'name' => $normalized['name'],
          'type' => $type,
        ];
        if ($type === 'tabs') {
          $attributes['default_tab'] = $normalized['default_tab'];
          $attributes['tabs'] = $this->normalizeTabsForSave($rowRequest, $normalized['tabs']);
        } else {
          $attributes['columns'] = $normalized['columns'];
          $attributes['filters'] = $this->normalizeFiltersForSave($normalized['filters'] ?? []);
          $attributes['params'] = $normalized['params'];
          $attributes['actions'] = $normalized['actions'] ?? [];
        }
        DataTableBuilder::create($attributes);
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
    if (($row['type'] ?? 'table') === 'tabs') {
      $row['tabs'] = $this->normalizeJsonPayload($row['tabs'] ?? [], 'data_table_builders.import.tabs');
      return $row;
    }
    foreach (['columns', 'filters', 'params', 'actions'] as $field) {
      $row[$field] = $this->normalizeJsonPayload($row[$field] ?? ($field === 'params' ? null : []), 'data_table_builders.import.' . $field);
    }

    return $row;
  }

  protected function findImportedTableBuilderDataSource(array $params): ?DataSource
  {
    $code = trim((string) ($params['data_source_code'] ?? ''));
    if ($code !== '') return DataSource::where('name', $code)->first();
    $name = trim((string) ($params['data_source_name'] ?? ''));
    if ($name !== '') return DataSource::where('name', $name)->first();

    $id = $params['data_source_id'] ?? null;
    return is_numeric($id) ? DataSource::find((int) $id) : null;
  }

  /** Import referenced tables before their container records; no depth cutoff is used. */
  protected function orderImportedTableBuilderRows(array $rows): array
  {
    foreach ($rows as $index => &$row) {
      if (! is_array($row)) continue;
      $row['__import_row'] = $index + 1;
    }
    unset($row);

    $ordered = array_values(array_filter($rows, fn ($row) => $this->normalizedBuilderType(is_array($row) ? $row : []) === 'table'));
    $pending = array_values(array_filter($rows, fn ($row) => $this->normalizedBuilderType(is_array($row) ? $row : []) === 'tabs'));
    while ($pending !== []) {
      $pendingCodes = array_map(fn ($row) => (string) ($row['code'] ?? ''), $pending);
      $ready = array_values(array_filter($pending, function ($row) use ($pendingCodes): bool {
        $tabs = $row['tabs'] ?? [];
        if (is_string($tabs)) {
          $decoded = json_decode($tabs, true);
          $tabs = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($tabs)) $tabs = [];
        foreach ($tabs as $tab) {
          if (($tab['type'] ?? null) !== 'tabs') continue;
          $code = (string) ($tab['table_builder_code'] ?? '');
          if (in_array($code, $pendingCodes, true) && ! DataTableBuilder::where('code', $code)->exists()) return false;
        }
        return true;
      }));
      if ($ready === []) break;
      $ordered = array_merge($ordered, $ready);
      $pending = array_values(array_filter($pending, fn ($row) => ! in_array($row, $ready, true)));
    }
    return array_merge($ordered, $pending);
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
    $type = $request->input('type', 'table');
    $rules = [
      'code' => 'required|string|unique:' . DatabaseConnection::validationTable('data_table_builders') . ',code',
      'name' => 'required|string',
      'type' => 'nullable|in:table,tabs',
    ];
    if ($type === 'tabs') {
      $rules += ['default_tab' => 'required|string', 'tabs' => 'required|array|min:1'];
    } else {
      $rules += ['filters' => 'nullable|array', 'columns' => 'required|array', 'actions' => 'nullable|array', 'params' => 'required|array'];
    }
    $validated = $request->validate($rules);

    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }


    $attributes = [
      'code' => $validated['code'],
      'name' => $validated['name'],
      'type' => $type === 'tabs' ? 'tabs' : 'table',
    ];
    if ($type === 'tabs') {
      $attributes['default_tab'] = $validated['default_tab'];
      $attributes['tabs'] = $this->normalizeTabsForSave($request, $validated['tabs']);
    } else {
      $attributes['filters'] = $this->normalizeFiltersForSave($validated['filters'] ?? []);
      $attributes['columns'] = $validated['columns'];
      $attributes['params'] = $validated['params'];
      $attributes['actions'] = $validated['actions'] ?? [];
    }
    $dataTableBuilder = DataTableBuilder::create($attributes);


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
    $dataTableBuilder = $this->findVisibleBuilder($request, (string) $id);
    if ($dataTableBuilder === null) {
      return response()->json(['error' => 'Data table builder not found', 'message' => 'Data table builder not found'], 400);
    }
    try {
      $resolved = $this->resolveTabsForResponse($request, $dataTableBuilder);
    } catch (\RuntimeException $exception) {
      return response()->json(['error' => $exception->getMessage(), 'message' => $exception->getMessage()], 409);
    }
    $resolved['used_by'] = $this->usedByForCode((string) $id, $request);
    return response()->json(['status' => 200, 'data' => $resolved], 200);
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

    $type = $request->input('type', $dataTableBuilder->type ?? 'table');
    $rules = [
      'code' => 'required|string|unique:' . DatabaseConnection::validationTable('data_table_builders') . ',code,'.$dataTableBuilder->id,
      'name' => 'required|string',
      'type' => 'nullable|in:table,tabs',
    ];
    if ($type === 'tabs') {
      $rules += ['default_tab' => 'required|string', 'tabs' => 'required|array|min:1'];
    } else {
      $rules += ['filters' => 'nullable|array', 'columns' => 'required|array', 'actions' => 'nullable|array', 'params' => 'required|array'];
    }
    $validated = $request->validate($rules);
    
    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }

    if ($validated['code'] !== (string) $id && $this->usedByForCode((string) $id, $request) !== []) {
      return response()->json(['error' => ['code' => ['This code is used by a Tab Builder and cannot be changed.']], 'message' => 'This Table Builder is used by another Tab Builder.'], 422);
    }

    $attributes = ['code' => $validated['code'], 'name' => $validated['name'], 'type' => $type === 'tabs' ? 'tabs' : 'table'];
    if ($type === 'tabs') {
      $attributes['default_tab'] = $validated['default_tab'];
      $attributes['tabs'] = $this->normalizeTabsForSave($request, $validated['tabs']);
      $attributes['columns'] = null;
      $attributes['filters'] = null;
      $attributes['params'] = null;
      $attributes['actions'] = null;
    } else {
      $attributes['filters'] = $this->normalizeFiltersForSave($validated['filters'] ?? []);
      $attributes['columns'] = $validated['columns'];
      $attributes['params'] = $validated['params'];
      $attributes['actions'] = $validated['actions'] ?? [];
      $attributes['default_tab'] = null;
      $attributes['tabs'] = null;
    }

    $headers = $request->header('x-tenant');
    $alreadyUpdate = false;
    if(!empty($headers)){
        tenancy()->initialize($headers);
        //if the tenant has table
        if(DatabaseConnection::schema()->hasTable('data_table_builders')){
          $schema = DatabaseConnection::schema();
          if ($type === 'tabs' && (! $schema->hasColumn('data_table_builders', 'type') || ! $schema->hasColumn('data_table_builders', 'default_tab') || ! $schema->hasColumn('data_table_builders', 'tabs'))) {
            return response()->json(['message' => 'Run the Tab Builder schema migration for this tenant before saving tabs.'], 422);
          }
          $values = [
            'name' => $attributes['name'],
            'filters' => json_encode($attributes['filters']),
            'columns' => json_encode($attributes['columns']),
            'params' => json_encode($attributes['params']),
            'actions' => json_encode($attributes['actions']),
          ];
          foreach (['type', 'default_tab', 'tabs'] as $field) {
            if ($schema->hasColumn('data_table_builders', $field)) {
              $values[$field] = is_array($attributes[$field] ?? null) ? json_encode($attributes[$field]) : ($attributes[$field] ?? null);
            }
          }
          DatabaseConnection::table('data_table_builders')->updateOrInsert(['code' => $validated['code']], $values);
          $dataTableBuilder = DataTableBuilder::where('code', $validated['code'])->first();
          $alreadyUpdate = true;
        }else{
          tenancy()->end();
        }
    }

    if(!$alreadyUpdate){
        $dataTableBuilder->update($attributes);
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
    $usedBy = $this->usedByForCode((string) $id, $request);
    if ($usedBy !== []) {
      return response()->json([
        'error' => 'Data table builder is used by one or more Tab Builders.',
        'message' => 'Remove this reference before deleting the Table Builder.',
        'used_by' => $usedBy,
      ], 409);
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
