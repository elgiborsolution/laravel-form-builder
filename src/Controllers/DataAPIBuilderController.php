<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\ApiConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Models\ApiTable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;

class DataAPIBuilderController extends Controller
{
    /**
     * Display a list of API Builder configurations.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

      public function index(Request $request)
      {

        $dataApiBuilder = Cache::remember('list-api-configs', 60, function (){
                return  ApiConfig::with('parentTable', 'childTables')->get()->toArray();
        });

          return response()->json(['data' => $dataApiBuilder], 200);
      }



    /**
     * Validate the details of an API Builder request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    public function validateDetail($request)
    {

          $validateParam = [
            'name' => 'required|string',
            'type' => ["required" , "string", "in:string,object,array,integer,date,boolean,numeric,url"],
            'required' => 'nullable|boolean',
            'unique' => 'nullable|boolean',
            'params' =>  ['nullable', 'required_if:type,object,array', "array"]
          ];

          foreach ($request->params as $key => $value) {

              $validator = Validator::make($value, $validateParam);

              if ($validator->fails()) {
                  return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload params at row '.strval(intval($key)+1)], 400);
              }

              if (in_array($value['type'], ['array', 'object'])){
                foreach ($value['params'] as $key2 => $value2) {
                    $validatorChild = Validator::make($value2, $validateParam);
                    if ($validatorChild->fails()) {
                        return response()->json(['error'=>$validatorChild->errors(), 'message'=>'Invalid payload params at row '.strval(intval($key)+1).'->'.strval(intval($key2)+1)], 400);
                    }

                    if (in_array($value2['type'], ['array', 'object'])){

                       return response()->json(['error'=>'You cannot use both object and array as a type in an array parameter', 'message'=>'You cannot use both object and array as a type in an array parameter, at '.strval(intval($key)+1).'->'.strval(intval($key2)+1)], 400);
                    }
                }
             
              }
          }

          
          $validateParentTable = [
            'table_name' => 'required|string',
            'primary_key' => 'nullable|string'
          ];

          $validateChildTable = [
            'table_name' => 'required|string',
            'foreign_key' => 'required|string'
          ];
          

          if($request->method != 'DELETE'){
            $validateParentTable['data_params'] = 'required|array';
            $validateChildTable['data_params'] = 'required|array';
          }else{
            $validateParentTable['data_params'] = 'nullable|array';
            $validateChildTable['data_params'] = 'nullable|array';

          }
          
          $validator = Validator::make($request->parent_table??[], $validateParentTable);

          if ($validator->fails()) {
                  return response()->json(['error'=>$validator->errors(), 'message'=>'In param parent_table, '.$validator->errors()->first()], 400);
          }


          
          foreach ($request->child_tables??[] as $key => $value) {

              $validator = Validator::make($value, $validateChildTable);

              if ($validator->fails()) {
                  return response()->json(['error'=>$validator->errors(), 'message'=>'Invalid payload child_tables at row '.strval(intval($key)+1)], 400);
              }
          }


          return null;
      }

    /**
     * Store a new API Builder configuration.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    
  public function store(Request $request)
  {

     $eventClass = "App\\Events\\AfterRunnerApiBuiderEvent";

     if (!class_exists($eventClass)) {
         Artisan::call('make:event AfterRunnerApiBuiderEvent');
     } 

    $validated = $request->validate([
      'route_name' => 'required|string|unique:api_configs,route_name',
      'endpoint' => 'required|string',
      'method' => ["required" , "string", "in:POST,PUT,DELETE"],
      'params' => ['nullable', 'required_if:PUT,POST', "array"],
      'parent_table' => 'required|array',
      'child_tables' => 'nullable|array',
      'enabled' => 'nullable|boolean',
      'description' => 'nullable|string',
    ]);


    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }


        try {

            \DB::beginTransaction();
            $dataApiBuilder = ApiConfig::create([
              'route_name' => $validated['route_name'],
              'endpoint' => $validated['endpoint'],
              'method' => $validated['method'],
              'params' => ($validated['params']),
              'enabled' => (!empty($validated['enabled'])?$validated['enabled']:true)
            ]);

            $parentTable = new ApiTable([
                'parent_id' => 0,
                'table_name' => $validated['parent_table']['table_name'],
                'primary_key' => $validated['parent_table']['primary_key']??'id',
                'data_params' => ($validated['parent_table']['data_params']??[]),
            ]);

            $dataApiBuilder->parentTable()->save($parentTable);

            $dataChildTable = [];
            foreach ($validated['child_tables']??[] as $key => $value) {
              $dataChild = new ApiTable([
                              'parent_id' => $dataApiBuilder->parentTable->id,
                              'table_name' => $value['table_name'],
                              'foreign_key' => $value['foreign_key'],
                              'data_params' => ($value['data_params']??[]),
                          ]);

              $dataChildTable[] = $dataChild;
            }
          // dd($validated['child_tables']);
            if(count($dataChildTable) > 0){
              $dataApiBuilder->childTables()->saveMany($dataChildTable);

            }

            $listenerName = $this->getListenerName($validated['route_name'], 1);

             $listenerClass = "App\\Listeners\\{$listenerName}";

             if (!class_exists($listenerClass)) {
                 Artisan::call('make:listener '.$listenerName.' --event=AfterRunnerApiBuiderEvent');
             } 

            \DB::commit();
              Cache::forget('list-api-configs');
             return response()->json(["status" => 200, 'message' => 'Data api builder created', 'data'=>$dataApiBuilder], 201);
        } catch (\Exception $e) {

            \DB::rollback();

            \Log::error("STORE API BUILDER=> " . $e->getMessage());
            \Log::error("STORE API BUILDER => " . (tenant()->id??'tenant not found'));
            \Log::error("STORE API BUILDER => " . $e->getTraceAsString());

            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
  }


    /**
     * Retrieve a specific API Builder configuration.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
  {

    $headers = $request->header('x-tenant');
    $dataApiBuilder = ApiConfig::with('parentTable', 'childTables')->where('code', $id)->first();
    if (empty($dataApiBuilder)) {
        return response()->json(['error' => 'Data api builder not found', 'message' => 'Data api builder not found'], 400);
    }


    return response()->json(["status" => 200, 'data'=>$dataApiBuilder], 200);
  }

    /**
     * Update an existing API Builder configuration.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    
  public function update(Request $request, $id)
  {

     $eventClass = "App\\Events\\AfterRunnerApiBuiderEvent";

     if (!class_exists($eventClass)) {
         Artisan::call('make:event AfterRunnerApiBuiderEvent');
     } 
    $dataApiBuilder = ApiConfig::where('id', $id)->first();
    if (empty($dataApiBuilder)) {
        return response()->json(['error' => 'Data api builder not found', 'message' => 'Data api builder not found'], 400);
    }

    $validated = $request->validate([
      'route_name' => 'required|string|unique:api_configs,route_name,'.$dataApiBuilder->id,
      'endpoint' => 'required|string',
      'method' => ["required" , "string", "in:POST,PUT,DELETE"],
      'params' => ['nullable', 'required_if:PUT,POST', "array"],
      'parent_table' => 'required|array',
      'child_tables' => 'nullable|array',
      'enabled' => 'nullable|boolean',
      'description' => 'nullable|string',
    ]);

    $invalid = $this->validateDetail($request);

    if (!empty($invalid)) {
       return $invalid;
    }


        try {

            \DB::beginTransaction();
            $dataApiBuilder->update([
              'route_name' => $validated['route_name'],
              'endpoint' => $validated['endpoint'],
              'method' => $validated['method'],
              'params' => ($validated['params']),
              'enabled' => (!empty($validated['enabled'])?$validated['enabled']:true)
            ]);

            $parentTable = [
                'parent_id' => 0,
                'table_name' => $validated['parent_table']['table_name'],
                'primary_key' => $validated['parent_table']['primary_key']??'id',
                'data_params' => ($validated['parent_table']['data_params']??[]),
            ];

            $dataApiBuilder->parentTable()->update($parentTable);

            $dataChildTable = [];
            foreach ($validated['child_tables']??[] as $key => $value) {
              $dataChild = new ApiTable([
                              'parent_id' => $dataApiBuilder->parentTable->id,
                              'table_name' => $value['table_name'],
                              'foreign_key' => $value['foreign_key'],
                              'data_params' => ($value['data_params']??[]),
                          ]);

              $dataChildTable[] = $dataChild;
            }
          
            $dataApiBuilder->childTables()->delete();
            
            if(count($dataChildTable) > 0){
              $dataApiBuilder->childTables()->saveMany($dataChildTable);

            }
            
            $listenerName = $this->getListenerName($validated['route_name'], 1);

             $listenerClass = "App\\Listeners\\{$listenerName}";

             if (!class_exists($listenerClass)) {
                 Artisan::call('make:listener '.$listenerName.' --event=AfterRunnerApiBuiderEvent');
             } 


            \DB::commit();
            Cache::forget('list-api-configs');
            return response()->json(["status" => 200, 'message' => 'Data api builder updated', 'data'=>$dataApiBuilder], 200);
        } catch (\Exception $e) {

            \DB::rollback();

            \Log::error("UPDATE API BUILDER=> " . $e->getMessage());
            \Log::error("UPDATE API BUILDER => " . (tenant()->id??'tenant not found'));
            \Log::error("UPDATE API BUILDER => " . $e->getTraceAsString());

            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
  
  }

    /**
     * Delete an API Builder configuration.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */

      public function destroy(Request $request, $id)
      {
        $dataApiBuilder = ApiConfig::where('id', $id)->first();
        if (empty($dataApiBuilder)) {
          return response()->json(['error' => 'Data api builder not found'], 400);
        }
      


        $headers = $request->header('x-tenant');
        $dataApiBuilder->delete();

        Cache::forget('list-api-configs');
        return response()->json(['message' => 'Data api builder deleted']);
      }

      public function getListenerName($routeName)
      {

            $cleanString = preg_replace('/[^A-Za-z0-9]/', ' ', $routeName);
            $cleanString = ucwords($cleanString);
            $cleanString = 'AfterRun'. str_replace(" ","",$cleanString).'Listener';
            return $cleanString;
      }
}

