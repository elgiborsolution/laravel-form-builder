<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Models\DataSourceParameter;
use ESolution\DataSources\Services\DataQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DataSourceController extends Controller
{
  public function __construct(
    protected DataQueryService $dataQueryService
  ) {
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
    $data = DataSource::with('parameters');
    if(!empty($request->page)){
      return $data->paginate(10);
    }else{
      $data = $data->get();
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

    $query = DataSource::query()->orderBy('id');

    if (! empty($ids)) {
      $query->whereIn('id', $ids);
    }

    $payload = $query->get($columns)
      ->map(static function (DataSource $dataSource) use ($columns): array {
        $row = [];

        foreach ($columns as $column) {
          $row[$column] = $dataSource->getAttribute($column);
        }

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

    try {
      if (empty($rows)) {
        return response()->json([
          'message' => 'No data to import',
        ], 422);
      }

      DB::beginTransaction();

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
        ];

        try {
          $dataSource = DataSource::create($data);
          if ($dataSource && $dataSource->exists) {
            $insertedCount++;
            $summary['imported']++;
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

      DB::commit();
    } catch (\Throwable $exception) {
      DB::rollBack();

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
      'columns' => $this->normalizeColumnsInput($request->input('columns')),
    ]);

    $validated = $request->validate([
      'use_custom_query' => 'required|boolean',
      'name' => 'required|string|unique:data_sources,name',
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
      'custom_query' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return $request->boolean('use_custom_query');
        }),
        "string",
      ],
    ]);

    if (!empty($validated['custom_query']) && !DataSource::validateQuery($validated['custom_query'])) {
      return response()->json(['error' => 'Only SELECT queries are allowed', 'message' => 'Only SELECT queries are allowed'], 422);
    }

    $validateParam = [
      'param_name' => 'required|string',
      'param_default_value' => 'nullable|string',
      'param_type' => ["required" , "string", "in:string,integer,boolean,date,float"],
      'is_required' => 'nullable|integer',
    ];
    $dataParam = [];
    foreach ($request->parameters??[] as $key => $value) {

        $validator = Validator::make($value, $validateParam);

        if ($validator->fails()) {
            return response()->json(['error'=>$validator->errors()], 422);
        }

        $dataParam[] = $value;

    }

    if($validated['use_custom_query']){

        $dataPrepare = $this->findAttributeSQlBuilder($request, $validated['custom_query']);
        if(!empty($dataPrepare['error'])){

            return response()->json(['error' => $dataPrepare['error'], 'message' => $dataPrepare['error']], 422);
        }
        $validated['columns'] = [];
        foreach ((!empty($dataPrepare['columns'])?$dataPrepare['columns']:[]) as $key => $value) {
          $dataParam[] = [
                            'param_name' => $value,
                            'param_type' => 'string',
                            'is_required' => 0
                        ];
          $validated['columns'][] = $value;
        }
    }

    $dataSource = DataSource::create([
      'name' => $validated['name'],
      'table_name' => $validated['table_name']??'',
      'use_custom_query' => $validated['use_custom_query'],
      'columns' => $validated['columns'],
      'custom_query' => $validated['custom_query'] ?? null
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
    $dataSource = DataSource::with(['parameters'])->findOrFail($id);
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }
    return response()->json($dataSource);
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
      'columns' => $this->normalizeColumnsInput($request->input('columns')),
    ]);

    $validated = $request->validate([
      'name' => 'required|string|unique:data_sources,name,'. $dataSource->id ,
      'use_custom_query' => 'boolean',
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
          return $request->boolean('use_custom_query');
        }),
        'array',
      ],
      'columns.*' => ['string'],
      'parameters' => ['nullable', "array"],
      'custom_query' => [
        'nullable',
        Rule::requiredIf(function () use ($request) {
          return $request->boolean('use_custom_query');
        }),
        "string",
      ],
    ]);

    if (!empty($validated['custom_query']) && !DataSource::validateQuery($validated['custom_query'])) {
      return response()->json(['error' => 'Only SELECT queries are allowed'], 422);
    }

    $validateParam = [
      'param_name' => 'required|string',
      'param_default_value' => 'nullable|string',
      'param_type' => ["required" , "string", "in:string,integer,boolean,date,float"],
      'is_required' => 'nullable|integer',
    ];
    $dataParam = [];
    foreach ($request->parameters as $key => $value) {

        $validator = Validator::make($value, $validateParam);

        if ($validator->fails()) {
            return response()->json(['error'=>$validator->errors()], 401);
        }

        $dataParam[] = $value;

    }

    if($validated['use_custom_query']){

        $dataPrepare = $this->findAttributeSQlBuilder($request, $validated['custom_query']);
        if(!empty($dataPrepare['error'])){

            return response()->json(['error' => $dataPrepare['error'], 'message' => $dataPrepare['error']], 422);
        }
        $validated['columns'] = [];
        foreach ((!empty($dataPrepare['columns'])?$dataPrepare['columns']:[]) as $key => $value) {
          $dataParam[] = [
                            'param_name' => $value,
                            'param_type' => 'string',
                            'is_required' => 0
                        ];
          $validated['columns'][] = $value;
        }
    }


    $dataSource->update([
      'name' => $validated['name'],
      'table_name' => $validated['table_name']??'',
      'use_custom_query' => $validated['use_custom_query'],
      'columns' => $validated['columns'],
      'custom_query' => $validated['custom_query'] ?? null
    ]);

    $dataSource->parameters()->delete();

    if(count($dataParam) > 0){
        $dataSource->parameters()->createMany($dataParam);
    }

    

    return response()->json($dataSource->load(['parameters']));
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
    $columns = Schema::getColumnListing((new DataSource())->getTable());

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
    $columns = Schema::getColumnListing((new DataSource())->getTable());

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
  * Display a list of tables in the database
  *
  * @param Request $request, DataSource $id
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function listTables(Request $request)
  {


    $headers = $request->header('x-tenant');

    if(!empty($headers)){
        tenancy()->initialize($headers);
    }

    $tables = DB::select("SHOW TABLES");
    // $databaseName = env('DB_DATABASE');

    // Laravel biasanya mengembalikan array dengan key yang berbeda tergantung pada driver
    $tableList = [];
    foreach ($tables as $table) {
      $tableList[] = array_values((array) $table)[0];
    }

    return response()->json($tableList);
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
    $headers = $request->header('x-tenant');

    if(!empty($headers)){
        tenancy()->initialize($headers);
    }

    try {
      $columns = DB::select("SHOW COLUMNS FROM `$table`");

      $columnList = [];
      foreach ($columns as $column) {
        $columnList[] = [
          'name' => $column->Field,
          'type' => $column->Type,
          'nullable' => $column->Null === 'YES',
          'default' => $column->Default,
          'key' => $column->Key,
        ];
      }

      return response()->json($columnList);
    } catch (\Exception $e) {
      return response()->json(['error' => 'Table not found'], 404);
    }
  }



  /**
  * Run a datasource command
  *
  * @param Request $request, String $id (name of datasource)
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function executeQuery(Request $request, $id)
  {
    $headers = $request->header('x-tenant');
    $dataSource = DataSource::with('parameters')->where('name', $id)->first();;
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }

    if(!empty($headers)){
        tenancy()->initialize($headers);
    }

    return $this->dataQueryService->executeForDataSource($request, $dataSource, 'data_source_q' . $id);
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

      $headers = $request->header('x-tenant');

      if(!empty($headers)){
          tenancy()->initialize($headers);
      }

      $re = '/select (.*?)from/';
      $str = str_replace("\n", "", strtolower($query));
      $return = [];

      preg_match($re, $str, $matches);
      if(count($matches) > 1){
        // $lineColum = str_replace("as ","as#",$matches[1]);
        $lineColum = preg_replace('/\s+/', ' ', $matches[1]);
        $dataColumnName =  explode(',', $lineColum);
        foreach ($dataColumnName as $key => $value) {

            if(substr($value, 0, 1) == ' ')$value = substr($value, 1);
            if(substr($value, -1) == ' ')$value = substr($value, 0, -1);

            $arraySValue = explode(' ', $value);
            $value = $arraySValue[count($arraySValue)-1];

            $coulmnAlias = $value;
            if (str_contains($value, ')')) {
              $columnFn = explode(')', $value);
              //its mean this column dosent have alias column
              if(count($columnFn)==1) continue;

              $coulmnAlias = $columnFn[count($columnFn)-1];

            }else if (str_contains($value, '.')) {
              $columnFn = explode('.', $value);

              $coulmnAlias = $columnFn[count($columnFn)-1];

            }

            $dataColumnName[$key] = $coulmnAlias;
        }

        if(in_array('*', $dataColumnName)){
            // find first table
            // dd($dataColumnName);
            $re = '/from (.*)/';
            $str = str_replace("\n", "", strtolower($query));
            preg_match($re, $str, $matches);
            $array = array_diff($dataColumnName, ["*"]);
            $dataColumnName = array_values($array);
            if(count($matches) > 1){
                $tquery = str_replace("`", "", strtolower($matches[1]));
                $tquery = preg_replace('/\s+/', ' ', $tquery);
                if(substr($tquery, 0, 1) == ' ')$tquery = substr($tquery, 1);
                if(substr($tquery, -1) == ' ')$tquery = substr($tquery, 0, -1);

                $tableName = explode(' ', $tquery); 

                $selectTable = $tableName[0];

                try {

                  $columns = DB::select("SHOW COLUMNS FROM `$selectTable`");
                  $columnList = [];
                  foreach ($columns as $column) {
                    $dataColumnName[] = $column->Field;
                  } 
                } catch (\Exception $e) {
                    $return['error'] = 'Table '.$selectTable.' not found';
                }

                $arr = array_diff_assoc($dataColumnName, array_unique($dataColumnName));
                if(count($arr) > 0){
                    $return['error'] = 'Duplicate column '.implode(', ', $arr);
                
                }
                
            }

        }
        $return['columns'] = $dataColumnName;

      }// if match

      tenancy()->end();
      return $return;

  }

}
