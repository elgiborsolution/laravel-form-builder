<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Models\DataSourceParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;

class DataSourceController extends Controller
{
  public function index()
  {
    return response()->json(DataSource::with('parameters')->get());
  }

  public function store(Request $request)
  {
    $validated = $request->validate([
      'name' => 'required|string|unique:data_sources,name',
      'table_name' => 'required|string',
      'use_custom_query' => 'boolean',
      'columns' => 'required|array',
      'parameters' => 'required|array',
      'custom_query' => 'nullable|string'
    ]);

    if (!empty($validated['custom_query']) && !DataSource::validateQuery($validated['custom_query'])) {
      return response()->json(['error' => 'Only SELECT queries are allowed'], 400);
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

    $dataSource = DataSource::create([
      'name' => $validated['name'],
      'table_name' => $validated['table_name'],
      'use_custom_query' => $validated['use_custom_query'],
      'columns' => json_encode($validated['columns']),
      'custom_query' => $validated['custom_query'] ?? null
    ]);

    if(count($dataParam) > 0){
        $dataSource->parameters()->createMany($dataParam);
    }

    return response()->json($dataSource, 201);
  }

  // SHOW a single data source
  public function show($id)
  {
    $dataSource = DataSource::with(['parameters'])->findOrFail($id);
    return response()->json($dataSource);
  }

  // UPDATE a data source
  public function update(Request $request, $id)
  {
    $dataSource = DataSource::findOrFail($id);
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 400);
    }
    $validated = $request->validate([
      'name' => 'required|string|unique:data_sources,name,'. $dataSource->id ,
      'table_name' => 'required|string',
      'use_custom_query' => 'boolean',
      'columns' => 'required|array',
      'custom_query' => 'nullable|string'
    ]);

    if (!empty($validated['custom_query']) && !DataSource::validateQuery($validated['custom_query'])) {
      return response()->json(['error' => 'Only SELECT queries are allowed'], 400);
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

    $dataSource->update([
      'name' => $validated['name'],
      'table_name' => $validated['table_name'],
      'use_custom_query' => $validated['use_custom_query'],
      'columns' => json_encode($validated['columns']),
      'custom_query' => $validated['custom_query'] ?? null
    ]);

    $dataSource->parameters()->delete();

    if(count($dataParam) > 0){
        $dataSource->parameters()->createMany($dataParam);
    }

    

    return response()->json($dataSource->load(['parameters']));
  }

  // DELETE a data source
  public function destroy($id)
  {
    $dataSource = DataSource::findOrFail($id);
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 400);
    }
    $dataSource->parameters()->delete();

    DataSource::destroy($id);
    return response()->json(['message' => 'Data source deleted']);
  }

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


  public function executeQuery(Request $request, $id)
  {
    $headers = $request->header('x-tenant');
// dd($request->all());

    $dataSource = DataSource::with('parameters')->where('name', $id)->first();;
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 400);
    }
    $queryParams = [];

    if(!empty($headers)){
        tenancy()->initialize($headers);
    }

    foreach ($dataSource->parameters as $param) {
      $paramName = $param->param_name;
      $paramValue = $request->get($paramName, $param->param_default_value);

      if ($param->is_required && $paramValue === null) {
        return response()->json(['error' => "Parameter '$paramName' is required"], 400);
      }

      switch ($param->param_type) {
        case 'integer':
          $paramValue = (int) $paramValue;
          break;
        case 'boolean':
          $paramValue = filter_var($paramValue, FILTER_VALIDATE_BOOLEAN);
          break;
        case 'float':
          $paramValue = (float) $paramValue;
          break;
        case 'date':
          if (!strtotime($paramValue)) {
            return response()->json(['error' => "Invalid date format for '$paramName'"], 400);
          }
          break;
        case 'string':
        default:
          $paramValue = (string) $paramValue;
          break;
      }

      $queryParams[$paramName] = $paramValue;
    }

    if ($dataSource->use_custom_query && $dataSource->custom_query) {
      $query = $dataSource->custom_query;
    } else {
      $columnArray = json_decode($dataSource->columns, true);
      $columns = implode(',', $columnArray);
      $query = "SELECT $columns FROM {$dataSource->table_name} WHERE 1=1";

      foreach ($queryParams as $key => $value) {

        $query .= " AND $key = :$key";
      }
    }

    $cacheKey = 'data_source_' . $id . '_query_' . md5($query . json_encode($queryParams));
    $dataResult = DB::select($query, $queryParams);
    if (!empty($request->page)) {
        $dataResult = $this->arrayPaginator($dataResult, $request);
    }
    $result = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($dataResult) {
      return $dataResult;
    });

    return response()->json($result);
  }


  public function arrayPaginator($array, $request)
  {
      $page = $request->page;
      $perPage = 10;
      $offset = ($page * $perPage) - $perPage;

      return new LengthAwarePaginator(array_slice($array, $offset, $perPage, true), count($array), $perPage, $page,
          ['path' => $request->url(), 'query' => $request->query()]);
  }
}
