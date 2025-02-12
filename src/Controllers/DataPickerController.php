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
  public function index()
  {
    return response()->json(DataPicker::get());
  }


  public function validateDetail($request)
  {

      $validateFilter = [
        'type' => ["required" , "string", "in:text,number,date,checkbox,dropdown,radio"],
        'label' => 'required|string',
        'value' => 'nullable',
        'options' => 'nullable|array'
      ];
      foreach ($request->filters as $key => $value) {

          $validator = Validator::make($value, $validateParam);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload filters at row '.($key+1)], 401);
          }
      }

      $validateColumn = [
        'header' => 'required|string',
        'detail' => 'required|string'
      ];
      foreach ($request->headers as $key => $value) {

          $validator = Validator::make($value, $validateParam);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload headers at row '.($key+1)], 401);
          }
      }


      $validateParams = [
        'enable_no' => 'nullable|boolean',
        'pagination' => 'nullable|boolean',
        'data_picker_id' => 'required|integer'
        'data_picker_name' => 'required|string'
      ];
      foreach ($request->params as $key => $value) {

          $validator = Validator::make($value, $validateParam);

          if ($validator->fails()) {
              return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid params headers at row '.($key+1)], 401);
          }
      }

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
      'code' => $validated['name'],
      'name' => $validated['name'],
      'filters' => json_encode($validated['filters']),
      'columns' => json_encode($validated['columns']),
      'params' => json_encode($validated['params']),
    ]);


    return response()->json($dataPicker, 201);
  }

  // SHOW a single data picker
  public function show($id)
  {

    $headers = $request->header('x-tenant');

    $dataPicker = DataPicker::where('code', $id)->first();
    if (empty($dataPicker)) {
      return response()->json(['error' => 'Data picker not found'], 400);
    }
    $queryParams = [];

    if(!empty($headers)){
        tenancy()->initialize($headers);
        $dataPickerInTenant = DB::table('users')->where('code', $id)->first();
        if(!empty($dataPickerInTenant)) $dataPicker = $dataPickerInTenant;
    }

    return response()->json($dataPicker, 200);
  }

  // UPDATE a data picker
  public function update(Request $request, $id)
  {
    $dataPicker = DataPicker::findOrFail($id);
    if (empty($dataPicker)) {
      return response()->json(['error' => 'Data picker not found'], 400);
    }

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

    $dataPicker->update([
      'code' => $validated['code'],
      'name' => $validated['name'],
      'filters' => json_encode($validated['filters']),
      'columns' => json_encode($validated['columns']),
      'params' => json_encode($validated['params']),
    ]);


    $headers = $request->header('x-tenant');
    if(!empty($headers)){
        tenancy()->initialize($headers);
        $dataPickerInTenant = DB::table('data_pickers')->updateOrCreate(
                                 [ 'code' => $validated['code'] ],
                                 [
                                   'name' => $validated['name'],
                                   'filters' => json_encode($validated['filters']),
                                   'columns' => json_encode($validated['columns']),
                                   'params' => json_encode($validated['params'])
                                 ]
                              );
        if(!empty($dataPickerInTenant)) $dataPicker = $dataPickerInTenant;
    }

    

    return response()->json($dataPicker, 200);
  }

  // DELETE a data picker
  public function destroy($id)
  {
    $dataPicker = DataPicker::findOrFail($id);
    if (empty($dataPicker)) {
      return response()->json(['error' => 'Data picker not found'], 400);
    }
  
    DataPicker::destroy($id);
    return response()->json(['message' => 'Data picker deleted']);
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
