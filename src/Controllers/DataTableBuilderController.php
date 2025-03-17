<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataTableBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class DataTableBuilderController extends Controller
{
  public function index(Request $request)
  {
    $dataTableBuilder = DataTableBuilder::get()->toArray();
    foreach ($dataTableBuilder as $key => $value) {
      
      $dataTableBuilder[$key]['filters'] = json_decode($value['filters']);
      $dataTableBuilder[$key]['columns'] = json_decode($value['columns']);
      $dataTableBuilder[$key]['params'] = json_decode($value['params']);
      $dataTableBuilder[$key]['actions'] = json_decode($value['actions']);
      $dataTableBuilder[$key]['is_central'] = true;
    }

    $headers = $request->header('x-tenant');
    // if it has tenant
    if(!empty($headers)){
        tenancy()->initialize($headers);
        //if the tenant has table
        if(Schema::hasTable('data_table_builders')){

            $dataTableBuilderInTenant = DB::table('data_table_builders')->get();
            $dataTableBuilderInTenantMap = [];
            foreach ($dataTableBuilderInTenant as $key => $value) { 
              $dataArray = json_decode(json_encode($value, true), true);
              $dataArray['filters'] = json_decode($dataArray['filters']);
              $dataArray['columns'] = json_decode($dataArray['columns']);
              $dataArray['params'] = json_decode($dataArray['params']);
              $dataArray['actions'] = json_decode($dataArray['actions']);
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


  public function validateDetail($request)
  {

      $validateFilter = [
        'type' => ["required" , "string", "in:text,number,date,checkbox,dropdown,radio"],
        'label' => 'required|string',
        'name' => 'required|string',
        'operator' => ["required" , "string", "in:=,>,<,<=,>=,like,LIKE"],
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

  public function store(Request $request)
  {
    $validated = $request->validate([
      'code' => 'required|string|unique:data_table_builders,code',
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
      'filters' => json_encode($validated['filters']),
      'columns' => json_encode($validated['columns']),
      'params' => json_encode($validated['params']),
      'actions' => json_encode($validated['actions']),
    ]);


    return response()->json(["status" => 200, 'message' => 'Data table builder created', 'data'=>$dataTableBuilder], 201);
  }

  // SHOW a single Data table builder
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
        if(Schema::hasTable('data_table_builders')){
          $dataTableBuilderInTenant = DB::table('data_table_builders')->where('code', $id)->first();
          if(!empty($dataTableBuilderInTenant)) $dataTableBuilder = $dataTableBuilderInTenant;
        }
    }

    $dataTableBuilder->filters = json_decode($dataTableBuilder->filters);
    $dataTableBuilder->columns = json_decode($dataTableBuilder->columns);
    $dataTableBuilder->params = json_decode($dataTableBuilder->params);
    $dataTableBuilder->actions = json_decode($dataTableBuilder->actions);

    return response()->json(["status" => 200, 'data'=>$dataTableBuilder], 200);
  }

  // UPDATE a Data table builder
  public function update(Request $request, $id)
  {
    $dataTableBuilder = DataTableBuilder::where('code', $id)->first();
    if (empty($dataTableBuilder)) {
        return response()->json(['error' => 'Data table builder not found', 'message' => 'Data table builder not found'], 400);
    }

    $validated = $request->validate([
      'code' => 'required|string|unique:data_table_builders,code,'.$dataTableBuilder->id,
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
        if(Schema::hasTable('data_table_builders')){
          $dataTableBuilder = DB::table('data_table_builders')->updateOrInsert(
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
          'filters' => json_encode($validated['filters']),
          'columns' => json_encode($validated['columns']),
          'params' => json_encode($validated['params']),
          'actions' => json_encode($validated['actions'])
        ]);
    }

    
    return response()->json(["status" => 200, 'message' => 'Data table builder updated', 'data'=>$dataTableBuilder], 201);
  }

  // DELETE a Data table builder
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
        if(Schema::hasTable('data_table_builders')){
          $dataTableBuilderInTenant = DB::table('data_table_builders')->where('code', $id)->first();
          if(empty($dataTableBuilderInTenant)) return response()->json(['error' => 'You not allowed to delete data central'], 400);

          DB::table('data_table_builders')->where('code', $id)->delete();
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
