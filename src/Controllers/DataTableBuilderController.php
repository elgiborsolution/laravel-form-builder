<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataTableBuilder;
use ESolution\DataSources\Support\Concerns\NormalizesJsonPayload;
use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class DataTableBuilderController extends Controller
{
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
    $dataTableBuilder = DataTableBuilder::get()->toArray();
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

            $dataTableBuilderInTenant = DatabaseConnection::table('data_table_builders')->get();
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
        'type' => ["required" , "string", "in:text,number,date,checkbox,dropdown,radio"],
        'label' => 'required|string',
        'name' => 'required|string',
        'operator' => ["required" , "string", "in:=,!=,>,<,>=,<=,like,LIKE,not like,NOT LIKE"],
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

      $validateParams = [
        'enable_no' => 'nullable|boolean',
        'pagination' => 'nullable|boolean',
        'data_source_id' => 'required|integer',
        'data_source_name' => 'required|string'
      ];
      // foreach ($request->params as $key => $value) {

          $validator = Validator::make($request->params, $validateParams);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload params'], 401);
          }
      // }

      return null;
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
      'filters' => $validated['filters'],
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
                                     'filters' => json_encode($validated['filters']),
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
          'filters' => $validated['filters'],
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
