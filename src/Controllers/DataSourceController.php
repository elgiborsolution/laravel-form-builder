<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Models\DataSourceParameter;
use ESolution\DataSources\Services\DataQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

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


  /**
  * Create new data source configuration
  *
  * @param Request $request
  *
  * @return \Illuminate\Http\JsonResponse
  */
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
    $dataSource->columns = json_decode($dataSource->columns);
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
