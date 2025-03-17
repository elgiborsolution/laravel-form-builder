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
        foreach ($data as $key => $value) {
          $data[$key]->columns = json_decode($value->columns);
        }
        return $data;
    }else{

        $data = $data->get();
        foreach ($data as $key => $value) {
          $data[$key]->columns = json_decode($value->columns);
        }
    }
    return response()->json(['data' => $data], 200);
  }

  public function store(Request $request)
  {
    $validated = $request->validate([
      'use_custom_query' => 'required|boolean',
      'name' => 'required|string|unique:data_sources,name',
      'table_name' =>  ['nullable', 'required_if:use_custom_query,0', "string"],
      'columns' => ['nullable', 'required_if:use_custom_query,0', "array"],
      'parameters' => ['nullable', "array"],
      'custom_query' => ['nullable', 'required_if:use_custom_query,1', "string"],
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
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }
    $dataSource->columns = json_decode($dataSource->columns);
    return response()->json($dataSource);
  }

  // UPDATE a data source
  public function update(Request $request, $id)
  {
    $dataSource = DataSource::findOrFail($id);
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }
    $validated = $request->validate([
      'name' => 'required|string|unique:data_sources,name,'. $dataSource->id ,
      'use_custom_query' => 'boolean',
      'table_name' =>  ['nullable', 'required_if:use_custom_query,0', "string"],
      'columns' => ['nullable', 'required_if:use_custom_query,0', "array"],
      'parameters' => ['nullable', "array"],
      'custom_query' => ['nullable', 'required_if:use_custom_query,1', "string"],
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
      'columns' => json_encode($validated['columns']??[]),
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
      return response()->json(['error' => 'Data source not found'], 422);
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
    if(!empty($request->params) && is_string($request->params)){
      $params = json_decode($request->params, true);
      $request->merge(['params' => $params]);
      // dd($params);
    }

    $validator = Validator::make($request->all(), ['params' => 'nullable|array']);

    if ($validator->fails())return response()->json(['error'=>$validator->errors(), 'message'=>$validator->errors()->first()], 422);

    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }



    $dataSource = DataSource::with('parameters')->where('name', $id)->first();;
    if (empty($dataSource)) {
      return response()->json(['error' => 'Data source not found'], 422);
    }
    $queryParams = [];
    $queryParamWithOperator = [];
    $paramsWithOperator = $request->params??[];
    foreach ($paramsWithOperator as $key => $value) {
      $paramsWithOperator[$value['param_name']] = $value;
    }
    if(!empty($headers)){
        tenancy()->initialize($headers);
    }


    foreach ($dataSource->parameters as $param) {
      //strict filter
      $paramName = $param->param_name;
      $paramValue = $request->get($paramName, $param->param_default_value);

      if ($param->is_required && $paramValue === null) {
        return response()->json(['error' => "Parameter '$paramName' is required", 'message' => "Parameter '$paramName' is required"], 422);
      }

      $paramValue = $this->findFormatValue($param->param_type, $paramValue);

      $queryParams[$paramName] = $paramValue;
      //end strict filter

      // dynamic filter
      if(count($paramsWithOperator) > 0){
        $operatorParam = !empty($paramsWithOperator[$paramName])?$paramsWithOperator[$paramName]:null;
        if(!empty($operatorParam)){

           $paramOpValue = $this->findFormatValue($param->param_type, $operatorParam['param_value'], strtolower($operatorParam['param_operation']) == 'like');

           $queryParamWithOperator[$paramName] = ['value' => $paramOpValue, 'operator' => $operatorParam['param_operation']];
        } 
      }
      // end dynamic filter
    }

    $queryCount = null;
    if ($dataSource->use_custom_query && $dataSource->custom_query) {
      $customQuery = $dataSource->custom_query;
      $query = "SELECT * FROM ({$customQuery}) tableCustom WHERE 1=1";
      $queryCount = "SELECT count(*) as aggregate FROM ({$customQuery}) tableCustom WHERE 1=1";
    } else {
      $columnArray = json_decode($dataSource->columns, true);
      $columns = implode(',', $columnArray);
      $query = "SELECT $columns FROM {$dataSource->table_name} WHERE 1=1";
      $queryCount = "SELECT count(*) as aggregate FROM {$dataSource->table_name} WHERE 1=1";
    }

    foreach ($queryParams as $key => $value) {
        // $query .= " AND $key = :$key";
        if(!empty($value) && $value != ''){

          $query .= " AND $key = '".$value."'";
          $queryCount .= " AND $key = '".$value."'";
        }
    }

    foreach ($queryParamWithOperator as $key => $value) {
          $query .= " AND $key ".$value['operator']." '".$value['value']."'";
          $queryCount .= " AND $key ".$value['operator']." '".$value['value']."'";
    }

    $cacheKey = 'data_source_q' . $id . '_query_' . md5($query . json_encode($queryParams).'-'.json_encode($queryParamWithOperator).($request->page??'0'));
    // $dataResult = DB::select($query, $queryParams);
    if(!empty($request->isDebug) && $request->isDebug){


          $dataResult = $this->makeQuery($queryCount, $query, $request, $dataSource);
          $result = $dataResult;

    }else{     

        $result = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($queryCount, $query, $request, $dataSource) {
          
            $dataResult = $this->makeQuery($queryCount, $query, $request, $dataSource);
            return $dataResult;
        }); 
    }
    if(!empty($result['error'])){

        return response()->json(['error'=>$result['error'], 'message'=>$result['error']], 400);
    }
    return response()->json($result);
  }

  public function makeQuery($queryCount, $query, $request, $dataSource){

      try {
          if (!empty($request->page)) {
              if(empty($queryCount)){

                $count = count(DB::select(DB::raw($query)));  
              }else{

                $data_count = DB::select(DB::raw($queryCount));
                $count = $data_count[0]->aggregate;  

              }
              // dd($query);

              $per_page = $request->per_page??10; //define how many items for a page
              $pages = ceil($count/$per_page);
              $page = ($request->page=="") ?"1" :$request->page;
              $start    = ($page - 1) * $per_page;  
              $query.= ' LIMIT ' . $start . ', ' . $per_page;

              $data = DB::select(DB::raw($query));
              $dataResult = $this->paginate($data , $count , $per_page , $request->page);

          }else{

            $data = DB::select(DB::raw($query));

            $dataResult = ['data'=>$data];

          }

            
          //if debugging / testing
          if(!empty($request->isDebug) && $request->isDebug){

                $dataExplain = DB::select(DB::raw('explain '.$query));
                if($dataSource->use_custom_query == 1){

                  $custom = collect(['data_index' => [], 'data_explain'=> $dataExplain, 'query_sql'=>$query]);
                  $dataResult = $custom->merge($dataResult);

                }else if($dataSource->use_custom_query == 0){
                    
                  $dataIndex = DB::select(DB::raw('show index from '.$dataSource->table_name));
                  $custom = collect(['data_index' => $dataIndex, 'data_explain'=> $dataExplain, 'query_sql'=>$query]);
                  $dataResult = $custom->merge($dataResult);
                }
          }

          return $dataResult;
      } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
      }
  }

  public function paginate($items, $total , $perPage = 5, $page = 1, $options = [])
   {
    // $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
    $items = $items instanceof Collection ? $items : Collection::make($items);
    return new LengthAwarePaginator($items,  $total, $perPage, $page, $options);
   }


  public function validateDetail($request)
  {

      $validateFilter = [
        'param_name' => 'required',
        'param_operation' => 'required',
        'param_value' => 'required'
      ];
      foreach ($request->params??[] as $key => $value) {

          $validator = Validator::make($value, $validateFilter);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload params at row '.strval(intval($key)+1)], 400);
          }
      }


      return null;
  }

  public function findFormatValue($type, $paramValue, $islike=false)
  {

      switch ($type) {
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
            return response()->json(['error' => "Invalid date format for '$paramName'"], 422);
          }
          break;
        case 'string':
        default:
          $paramValue = (string) $paramValue;
          break;
      }
      if($islike){
        $paramValue = '%'.$paramValue.'%';
      }
      return $paramValue;
  }


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
