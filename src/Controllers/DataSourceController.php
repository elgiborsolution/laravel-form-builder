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
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class DataSourceController extends Controller
{
  public function index(Request $request)
  {
    $data = DataSource::with('parameters');
    if(!empty($request->page)){
        $data = $data->paginate(10);
        return $data;
    }else{

        $data = $data->get();
    }
    return response()->json(['data' => $data], 200);
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
    $queryCount = null;
    if ($dataSource->use_custom_query && $dataSource->custom_query) {
      $query = $dataSource->custom_query;
    } else {
      $columnArray = json_decode($dataSource->columns, true);
      $columns = implode(',', $columnArray);
      $query = "SELECT $columns FROM {$dataSource->table_name} WHERE 1=1";
      $queryCount = "SELECT count(*) as aggregate FROM {$dataSource->table_name} WHERE 1=1";

      foreach ($queryParams as $key => $value) {

        // $query .= " AND $key = :$key";
        $query .= " AND $key = $value";
        $queryCount .= " AND $key = $value";
      }
    }

    $cacheKey = 'data_source_' . $id . '_query_' . md5($query . json_encode($queryParams).($request->page??'0'));
    // $dataResult = DB::select($query, $queryParams);

    $result = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($queryCount, $query, $request) {
      //if paginate
      if (!empty($request->page)) {
          if(empty($queryCount)){

            $count = count(DB::select(DB::raw($query)));  
          }else{

            $data_count = DB::select(DB::raw($queryCount));
            $count = $data_count[0]->aggregate;  
          }

          $per_page = 10; //define how many items for a page
          $pages = ceil($count/$per_page);
          $page = ($request->page=="") ?"1" :$request->page;
          $start    = ($page - 1) * $per_page;  
          $query.= ' LIMIT ' . $start . ', ' . $per_page;

          $size = 10;
          $data = DB::select(DB::raw($query));
          $dataResult = $this->paginate($data , $count , $per_page , $request->page);

      }else{

        $dataResult = DB::select(DB::raw($query));
      }
      return $dataResult;
    });

    return response()->json($result);
  }

  public function paginate($items, $total , $perPage = 5, $page = 1, $options = [])
   {
    // $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
    $items = $items instanceof Collection ? $items : Collection::make($items);
    return new LengthAwarePaginator($items,  $total, $perPage, $page, $options);
   }

}
