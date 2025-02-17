<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataPicker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;

class DataPickerController extends Controller
{
  public function index(Request $request)
  {
    $dataPicker = DataPicker::get()->toArray();
    foreach ($dataPicker as $key => $value) {
      
      $dataPicker[$key]['filters'] = json_decode($value['filters']);
      $dataPicker[$key]['columns'] = json_decode($value['columns']);
      $dataPicker[$key]['params'] = json_decode($value['params']);
      $dataPicker[$key]['is_central'] = true;
    }

    $headers = $request->header('x-tenant');
    if(!empty($headers)){
        tenancy()->initialize($headers);
        $dataPickerInTenant = DB::table('data_pickers')->get();
        $dataPickerInTenantMap = [];
        foreach ($dataPickerInTenant as $key => $value) { 
          $dataArray = json_decode(json_encode($value, true), true);
          $dataArray['filters'] = json_decode($dataArray['filters']);
          $dataArray['columns'] = json_decode($dataArray['columns']);
          $dataArray['params'] = json_decode($dataArray['params']);
          $dataArray['is_central'] = false;

          $dataPickerInTenantMap[$value->code] = $dataArray;
        }
       
        foreach ($dataPicker as $key => $value) {

          if(!empty($dataPickerInTenantMap[$value['code']])){

              // replace central data with data tenant
              $dataPicker[$key] = $dataPickerInTenantMap[$value['code']];
          }

        }
        
    }

        return response()->json(['data' => $dataPicker], 200);
  }


  public function validateDetail($request)
  {

      $validateFilter = [
        'type' => ["required" , "string", "in:text,number,date,checkbox,dropdown,radio"],
        'label' => 'required|string',
        'name' => 'required|string',
        'operator' => ["required" , "string", "in:=,>,<,<=,>=,like,or"],
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
      'code' => 'required|string|unique:data_pickers,code',
      'name' => 'required|string',
      'filters' => 'required|array',
      'columns' => 'required|array',
      'params' => 'required|array',
    ]);

    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }


    $dataPicker = DataPicker::create([
      'code' => $validated['code'],
      'name' => $validated['name'],
      'filters' => json_encode($validated['filters']),
      'columns' => json_encode($validated['columns']),
      'params' => json_encode($validated['params']),
    ]);


    return response()->json(["status" => 200, 'message' => 'data picker created', 'data'=>$dataPicker], 201);
  }

  // SHOW a single data picker
  public function show(Request $request, $id)
  {

    $headers = $request->header('x-tenant');
    $dataPicker = DataPicker::where('code', $id)->first();
    if (empty($dataPicker)) {
      return response()->json(['error' => 'Data picker not found'], 400);
    }
    $queryParams = [];

    if(!empty($headers)){
        tenancy()->initialize($headers);
        $dataPickerInTenant = DB::table('data_pickers')->where('code', $id)->first();
        if(!empty($dataPickerInTenant)) $dataPicker = $dataPickerInTenant;
    }

    $dataPicker->filters = json_decode($dataPicker->filters);
    $dataPicker->columns = json_decode($dataPicker->columns);
    $dataPicker->params = json_decode($dataPicker->params);

    return response()->json(["status" => 200, 'data'=>$dataPicker], 200);
  }

  // UPDATE a data picker
  public function update(Request $request, $id)
  {
    $dataPicker = DataPicker::where('code', $id)->first();
    if (empty($dataPicker)) {
      return response()->json(['error' => 'Data picker not found'], 400);
    }

    $validated = $request->validate([
      'code' => 'required|string|unique:data_pickers,code,'.$dataPicker->id,
      'name' => 'required|string',
      'filters' => 'required|array',
      'columns' => 'required|array',
      'params' => 'required|array',
    ]);
    
    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }



    $headers = $request->header('x-tenant');
    if(!empty($headers)){
        tenancy()->initialize($headers);
        $dataPicker = DB::table('data_pickers')->updateOrInsert(
                                 [ 'code' => $validated['code'] ],
                                 [
                                   'name' => $validated['name'],
                                   'filters' => json_encode($validated['filters']),
                                   'columns' => json_encode($validated['columns']),
                                   'params' => json_encode($validated['params'])
                                 ]
                              );
        $dataPicker = DataPicker::where('code', $validated['code'])->first();
    }else{

        $dataPicker->update([
          'code' => $validated['code'],
          'name' => $validated['name'],
          'filters' => json_encode($validated['filters']),
          'columns' => json_encode($validated['columns']),
          'params' => json_encode($validated['params']),
        ]);
    }

    
    return response()->json(["status" => 200, 'message' => 'data picker updated', 'data'=>$dataPicker], 201);
  }

  // DELETE a data picker
  public function destroy(Request $request, $id)
  {
    $dataPicker = DataPicker::where('code', $id)->first();
    if (empty($dataPicker)) {
      return response()->json(['error' => 'Data picker not found'], 400);
    }
  


    $headers = $request->header('x-tenant');
    if(!empty($headers)){
        tenancy()->initialize($headers);
        $dataPickerInTenant = DB::table('data_pickers')->where('code', $id)->first();
        if(empty($dataPickerInTenant)) return response()->json(['error' => 'You not allowed to delete data central'], 400);

        DB::table('data_pickers')->where('code', $id)->delete();
    
    }else{


        DataPicker::destroy($id);
    }

    return response()->json(['message' => 'Data picker deleted']);
  }

  public function arrayPaginator($array, $request)
  {
      $array = json_decode(json_encode($array, true), true);
      $page = $request->page;
      $perPage = 10;
      $offset = ($page * $perPage) - $perPage;

      return new LengthAwarePaginator(array_slice($array, $offset, $perPage, true), count($array), $perPage, $page,
          ['path' => $request->url(), 'query' => $request->query()]);
  }
}
