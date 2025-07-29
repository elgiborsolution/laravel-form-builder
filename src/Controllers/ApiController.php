<?php
namespace ESolution\DataSources\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Route;
use ESolution\DataSources\Models\ApiConfig;

class ApiController extends Controller
{

  /**
  * Handle API request based on the API configuration.
  *
  * @param Request $request
  * @param int $id (optional)
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function handleRequest(Request $request, $id=0)
  {
        $routeName = Route::currentRouteName(); // Get the route name
        $headers = $request->header('x-tenant');

        $apiConfigs = Cache::remember('api-configs-'.$routeName, 60, function () use($routeName) {
                return ApiConfig::with('parentTable', 'childTables')->where('route_name', $routeName)->first();
        });


        if(empty($apiConfigs)){

              return response()->json(['status' => 400, 'error'=> 'API Builder tidak ditemukan', 'message'=>'API Builder tidak ditemukan'], 400);
        }
        
        // if it has tenant
        if(!empty($headers)){
            tenancy()->initialize($headers);
        }
        

        if($apiConfigs->method == 'POST'){

            return $this->store($request, $apiConfigs);
        }

        if($apiConfigs->method == 'PUT'){
            if($id == 0){

              return response()->json(['status' => 400, 'error'=> 'primary_key is required', 'message'=>'primary_key is required'], 400);
            }

            return $this->update($request, $apiConfigs, $id);
        }


        if($apiConfigs->method == 'DELETE'){
            if($id == 0){

              return response()->json(['status' => 400, 'error'=> 'primary_key is required', 'message'=>'primary_key is required'], 400);
            }

            return $this->destroy($request, $apiConfigs, $id);
        }

        return response()->json(['data' => []], 200);
  }


  /**
  * Store new data into the database.
  *
  * @param Request $request
  * @param object $apiConfigs API Configuration object
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function store(Request $request, $apiConfigs)
  {
      // Validate input data based on API configurations
      $checkValidateRule = $this->validateRule($apiConfigs->params ?? [], $apiConfigs->parentTable->table_name);

      // Validate parent-level parameters
      if (count($checkValidateRule['parentValidate']) > 0) {
          $validated = $request->validate($checkValidateRule['parentValidate']);
      }

      // Validate child-level parameters
      if (count($checkValidateRule['childValidate']) > 0) {
          foreach ($checkValidateRule['childValidate'] as $key => $valueValidateRule) {
              foreach ($request[$key] ?? [] as $keyParam => $valueParam) {
                  // Perform validation on each child record
                  $validator = Validator::make($valueParam, $valueValidateRule);
                  if ($validator->fails()) {
                      return response()->json([
                          'error' => $validator->errors(),
                          'message' => 'Invalid payload ' . $key . ' at row ' . strval(intval($keyParam) + 1)
                      ], 400);
                  }
              }
          }
      }

      // Retrieve tables that contain array parameters
      $tableMutipleValue = $this->getTablesWithArrayParams($apiConfigs->toArray());
      $multipleInsertTable = array_keys($tableMutipleValue);
      $parentTable = $apiConfigs->parentTable->table_name;

      // Ensure that parent table does not have multiple records
      if (in_array($parentTable, $multipleInsertTable) && count($multipleInsertTable) > 1) {
          return response()->json([
              'status' => 400,
              'error' => 'Invalid Api Builder',
              'message' => 'The input data for the parent table cannot be plural (cannot use an array parameter).'
          ], 400);
      }

      $prefix = DB::getTablePrefix();
      $childTables = $apiConfigs->childTables->toArray();

      // Flatten parameters for easier access
      $masterParam1Level = $this->flattenArray($apiConfigs->params);

      try {
          \DB::beginTransaction();
          $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);

          // Prepare parent table data for insertion
          $parentData = [];
          foreach ($apiConfigs->parentTable->data_params as $column => $paramPath) {
              $parentData[$column] = $this->getValueFromPath($request->all(), $paramPath);
          }

          // Insert data into the parent table and get the generated ID
          $id = DB::table($cleanParentTable)->insertGetId($parentData);

          $insertDataChild = [];

          // Process child table insertions
          foreach ($childTables as $key => $table) {
              $childData = [];
              $insert_type = 'singular';

              // Handle plural insertions (arrays of child records)
              if (in_array($table['table_name'], $multipleInsertTable)) {
                  $insert_type = 'plural';
                  $dataFilled = $this->generateDynamicCombinations($request->all(), $tableMutipleValue[$table['table_name']], $table['data_params']);

                  foreach ($dataFilled as $key => $value) {
                      // Set foreign key reference to parent ID
                      $dataFilled[$key][$table['foreign_key']] = $id;
                      foreach ($table['data_params'] as $keyMap => $valueMap) {
                          if (!in_array((explode('.', $valueMap)[0]), $tableMutipleValue[$table['table_name']])) {
                              $dataFilled[$key][$keyMap] = $this->getValueFromPath($request->all(), $valueMap);
                          }
                      }
                  }
                  $childData = $dataFilled;

              } else {
                  // Handle singular insertions (single child record)
                  $childData[$table['foreign_key']] = $id;
                  foreach ($table['data_params'] as $key => $value) {
                      $childData[$key] = $request->input($value);
                  }
              }

              // Prepare child table data for insertion
              if (count($childData) > 0) {
                  $insertDataChild[] = [
                      'table' => preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']),
                      'table_values' => $childData,
                      'insert_type' => $insert_type,
                  ];
              }
          }

          // Insert all child table data into the database
          foreach ($insertDataChild as $key => $value) {
              DB::table($value['table'])->insert($value['table_values']);
          }

          \DB::commit();
          return response()->json([
              "status" => 200,
              'message' => 'Data has been successfully created',
              'data' => []
          ], 201);

      } catch (\Exception $e) {
          \DB::rollback();
          \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
          return response()->json([
              "status" => 422,
              "data" => [],
              "error" => $e->getMessage()
          ], 422);
      }
  }




  /**
  * Update data into the database.
  *
  * @param Request $request
  * @param object $apiConfigs API Configuration object
  * @param string $id primary key
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function update(Request $request, $apiConfigs, $id)
  {
        $checkValidateRule = $this->validateRule($apiConfigs->params??[], $apiConfigs->parentTable->table_name, $id);

        if(count($checkValidateRule['parentValidate']) > 0){
          
          $validated = $request->validate($checkValidateRule['parentValidate']);
        }

        if(count($checkValidateRule['childValidate']) > 0){
          
            foreach ($checkValidateRule['childValidate'] as $key => $valueValidateRule) {

                foreach ($request[$key]??[] as $keyParam => $valueParam) {
                    $validator = Validator::make($valueParam,  $valueValidateRule);
                    if ($validator->fails()) {
                        return response()->json(['error' => $validator->errors(), 'message' => 'Invalid payload '.$key.' at row ' . strval(intval($keyParam) + 1)], 400);
                    }
                }

            }

        }

        $tableMutipleValue = $this->getTablesWithArrayParams($apiConfigs->toArray());
        $multipleInsertTable = array_keys($tableMutipleValue);
        $parentTable = $apiConfigs->parentTable->table_name;
        $primarykey = $apiConfigs->parentTable->primary_key;
        if(in_array($parentTable, $multipleInsertTable) && count($multipleInsertTable) > 1){
            return response()->json(['status' => 400, 'error' => 'Invalid Api Builder', 'message' => 'The input data for the parent table cannot be plural (cannot use an array parameter).'], 400);
        }

        $prefix = DB::getTablePrefix();
        $childTables = $apiConfigs->childTables->toArray();

        $masterParam1Level = $this->flattenArray($apiConfigs->params);

        try {
            \DB::beginTransaction();
            $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);

            // insert parent table
            $parentData  = [];
            foreach ($apiConfigs->parentTable->data_params as $column => $paramPath) {
                $parentData[$column] = $this->getValueFromPath($request->all(), $paramPath);
            }

            DB::table($cleanParentTable)
                ->where($primarykey, $id)
                ->update($parentData);

            $insertDataChild = [];
            // Insert ke child tables berdasarkan mapping
            foreach ($childTables as $key => $table) {
                $childData = [];
                $insert_type = 'singular';
                if(in_array($table['table_name'], $multipleInsertTable)){
                  $insert_type = 'plural';
                  $dataFilled = $this->generateDynamicCombinations($request->all(), $tableMutipleValue[$table['table_name']], $table['data_params']);
                  foreach ($dataFilled as $key => $value) {
                      $dataFilled[$key][$table['foreign_key']] = $id;
                      foreach ($table['data_params'] as $keyMap => $valueMap) {
                        if(!in_array((explode('.', $valueMap)[0]), $tableMutipleValue[$table['table_name']])){
                          $dataFilled[$key][$keyMap] = $this->getValueFromPath($request->all(), $valueMap);
                        } 
                      }
                  }
                  $childData = $dataFilled;

                }else{
                  $childData[$table['foreign_key']] = $id;
                  foreach ($table['data_params'] as $key => $value) {
                    $childData[$key] = $request->input($value);
                  }

                }

                if(count($childData) > 0){

                   $insertDataChild[] = [
                        'table' => preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']),
                        'foreign_key' => $table['foreign_key'],
                        'table_values' => $childData,
                        'insert_type' => $insert_type,
                   ];
                }

            }

            foreach ($insertDataChild as $key => $value) {
              DB::table($value['table'])->where($value['foreign_key'], $id)->delete();
              DB::table($value['table'])->insert($value['table_values']);
            }


            \DB::commit();
            return response()->json(["status" => 200, 'message' => 'Data has been successfully updated', 'data' => []], 201);
        } catch (\Exception $e) {
            \DB::rollback();
            \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
    }



  /**
  * Delete data into the database.
  *
  * @param Request $request
  * @param object $apiConfigs API Configuration object
  * @param string $id primary key
  *
  * @return \Illuminate\Http\JsonResponse
  */
  public function destroy(Request $request, $apiConfigs, $id)
  {
        $checkValidateRule = $this->validateRule($apiConfigs->params??[], $apiConfigs->parentTable->table_name, $id);

        if(count($checkValidateRule['parentValidate']) > 0){
          
          $validated = $request->validate($checkValidateRule['parentValidate']);
        }

        if(count($checkValidateRule['childValidate']) > 0){
          
            foreach ($checkValidateRule['childValidate'] as $key => $valueValidateRule) {

                foreach ($request[$key]??[] as $keyParam => $valueParam) {
                    $validator = Validator::make($valueParam,  $valueValidateRule);
                    if ($validator->fails()) {
                        return response()->json(['error' => $validator->errors(), 'message' => 'Invalid payload '.$key.' at row ' . strval(intval($keyParam) + 1)], 400);
                    }
                }

            }

        }

        $parentTable = $apiConfigs->parentTable->table_name;
        $primarykey = $apiConfigs->parentTable->primary_key;

        $prefix = DB::getTablePrefix();
        $childTables = $apiConfigs->childTables->toArray();

        $masterParam1Level = $this->flattenArray($apiConfigs->params);

        try {
            \DB::beginTransaction();
            $cleanParentTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $parentTable);

            DB::table($cleanParentTable)
                ->where($primarykey, $id)
                ->delete();

            foreach ($childTables as $key => $table) {
                $tableChild = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table['table_name']);
                DB::table($tableChild)->where($table['foreign_key'], $id)->delete();

            }

            \DB::commit();
            return response()->json(["status" => 200, 'message' => 'Data has been successfully deleted', 'data' => []], 201);
        } catch (\Exception $e) {
            \DB::rollback();
            \Log::error("STORE API BUILDER ERROR => " . $e->getMessage());
            return response()->json(["status" => 422, "data" => [], "error" => $e->getMessage()], 422);
        }
    }

/**
 * Validates API request parameters based on configuration.
 *
 * This function processes and categorizes validation rules for different types of input parameters.
 * It separates parameters into three categories:
 * - Parent validation rules
 * - Child validation rules for array-type parameters
 * - A list of parameters that are arrays
 *
 * @param array $params The API parameters configuration.
 * @param string $tableParent The name of the parent table (optional).
 *
 * @return array Returns an associative array containing:
 *   - 'parentValidate': Validation rules for single-level parameters.
 *   - 'childValidate': Validation rules for array-type parameters.
 *   - 'paramIsArray': A list of parameters identified as arrays.
 */
  public function validateRule($params=[], $tableParent = '', $primaryKey = 0)
  {

      $validateRule = []; // Stores validation rules for direct parameters
      $paramArray = []; // Stores parameters that are of type 'array'
      $paramObject = []; // Stores parameters that are of type 'object
      // Iterate through each parameter to categorize them
      foreach ($params as $key => $value) {
          if (empty($value['type']) || empty($value['name'])) continue;

          // Separate array and object parameters
          if ($value['type'] == 'array') {
              $paramArray[] = $value;
          } else if ($value['type'] == 'object') {
              $value['type'] = 'array'; // Convert 'object' to 'array' for uniform processing
              $paramObject[] = $value;
          }

          // Generate validation rules for non-array/object parameters
          $validateRow = $this->findValidateRule($value, $tableParent, $primaryKey);
          $validateRule[$value['name']] = $validateRow;
      }

      // Process object-type parameters by handling nested properties
      foreach ($paramObject as $key => $value) {
          foreach ($value['params'] ?? [] as $keyParams => $valueParams) {
              if (empty($valueParams['type']) || empty($valueParams['name']) || in_array($valueParams['type'], ['array', 'object'])) continue;

              // Generate validation rules for object properties
              $validateRow = $this->findValidateRule($valueParams, $tableParent, $primaryKey);
              $validateRule[$value['name'] . '.' . $valueParams['name']] = $validateRow;
          }
      }

      $childValidateRule = []; // Stores validation rules for child elements in arrays
      $paramIsArray = []; // Stores names of parameters that are arrays

      // Process array-type parameters
      foreach ($paramArray as $key => $value) {
          $paramIsArray[] = $value['name'];
          $currentValidate = [];

          foreach ($value['params'] ?? [] as $keyParams => $valueParams) {
              if (empty($valueParams['type']) || empty($valueParams['name']) || in_array($valueParams['type'], ['array', 'object'])) continue;

              // Generate validation rules for array elements
              $validateRow = $this->findValidateRule($valueParams, $tableParent, $primaryKey);
              $currentValidate[$valueParams['name']] = $validateRow;
          }

          $childValidateRule[$value['name']] = $currentValidate;
      }

      // Return structured validation rules
      return [
          'parentValidate' => $validateRule,
          'childValidate' => $childValidateRule,
          'paramIsArray' => $paramIsArray
      ];
  }

/**
 * Generates Laravel validation rules for a given parameter.
 *
 * This function determines whether a parameter is required or nullable and
 * applies type-based validation. If the parameter has a uniqueness constraint,
 * it ensures that it is unique within the specified database table.
 *
 * @param array $rowParam The parameter definition containing validation rules.
 * @param string $tableParent The name of the parent table for uniqueness validation.
 *
 * @return string The generated validation rule string.
 */
public function findValidateRule($rowParam, $tableParent, $primaryKey = 0)
{
    $prefix = DB::getTablePrefix(); // Get the database table prefix
    $value = $rowParam;

    // Determine if the parameter is required or nullable
    $dataValidate = !empty($value['required']) && $value['required'] ? 'required' : 'nullable';

    // Append the data type to the validation rule
    $dataValidate .= '|' . $value['type'];

    // Check if the field needs to be unique in the database
    if (!empty($value['unique']) && $value['unique'] && $primaryKey == 0) {
        // Remove the table prefix from the table name
        $table = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $tableParent);
        // Add uniqueness validation rule
        $unique = '|unique:' . $table . ',' . $value['name'];
        $dataValidate .= $unique;
    }

    return $dataValidate; // Return the validation rule string
}

/**
 * Retrieve a list of tables that contain parameters of type "array".
 *
 * @param array $config Configuration array containing parent and child tables with their parameters.
 * @return array An associative array where keys are table names, and values are arrays of "array"-type parameter names.
 */
  public function getTablesWithArrayParams($config) {
      $tables = [];

      // Check the parent table for "array" type parameters
      if (!empty($config['parent_table']) && !empty($config['parent_table']['data_params'])) {
          foreach ($config['parent_table']['data_params'] as $column => $param) {
              // If the parameter is an array type, store it under the parent table
              if ($this->isArrayType($param, $config['params'])) {
                  $tables[$config['parent_table']['table_name']][] = explode('.', $param)[0];
              }
          }
      }

      // Check child tables for "array" type parameters
      if (!empty($config['child_tables'])) {
          foreach ($config['child_tables'] as $childTable) {
              if (!empty($childTable['data_params'])) {
                  foreach ($childTable['data_params'] as $column => $param) {
                      // If the parameter is an array type, store it under the child table
                      if ($this->isArrayType($param, $config['params'])) {
                          $tables[$childTable['table_name']][] = explode('.', $param)[0];
                      }
                  }
              }
          }
      }

      // Remove duplicate parameter names and re-index the arrays
      foreach ($tables as $key => $value) {
          $value = array_unique($value);
          $tables[$key] = array_values($value);
      }

      return $tables;
  }

    /**
   * Check if a given parameter is of type "array".
   *
   * @param string $param The parameter name (possibly in dot notation).
   * @param array $params List of parameter definitions, each containing 'name' and 'type'.
   * @return bool Returns true if the parameter is of type "array", otherwise false.
   */
  public function isArrayType($param, $params) {
      foreach ($params as $p) {
          // Extract the base name of the parameter and check if it exists in the params list as an "array" type
          if ($p['name'] === explode('.', $param)[0] && $p['type'] === 'array') {
              return true;
          }
      }
      return false;
  }

  /**
   * Retrieve a nested value from an associative array using a dot-separated path.
   *
   * @param array $data The associative array to search.
   * @param string $path The dot-separated key path (e.g., "user.profile.name").
   * @return mixed Returns the value at the specified path or null if not found.
   */
  public function getValueFromPath($data, $path)
  {
      // Split the path into an array of keys
      $keys = explode('.', $path);

      // Traverse the data array following the key path
      foreach ($keys as $key) {
          // If the key does not exist, return null
          if (!isset($data[$key])) return null;
          
          // Move deeper into the array
          $data = $data[$key];
      }

      // Return the found value
      return $data;
  }

    /**
   * Flattens a nested array structure into a single-level associative array
   * with dot-separated keys.
   *
   * @param array $array The nested array to flatten.
   * @param string $prefix The prefix for nested keys (used for recursion).
   * @return array A flattened associative array with dot-separated keys.
   */
  public function flattenArray($array, $prefix = '') {
      $result = [];

      foreach ($array as $item) {
          // Construct the key with the prefix
          $key = $prefix . $item['name'];

          // If the item has nested parameters (object or array), recursively flatten it
          if (isset($item['params']) && is_array($item['params'])) {
              $flattened = $this->flattenArray($item['params'], $key . '.');
              $result = array_merge($result, $flattened);
          }

          // Store the current item in the result with its type and required flag
          $result[$key] = [
              'type' => $item['type'],
              'required' => $item['required'] ?? false
          ];
      }

      return $result;
  }


    /**
   * Generates dynamic combinations from request data based on given keys and mapping parameters.
   *
   * @param array $request The request data containing arrays of values.
   * @param array $keys The keys in the request to generate combinations from.
   * @param array $mappingParam Mapping of the result structure to extract specific values.
   * @return array An array of dynamically generated combinations.
   */
  public function generateDynamicCombinations($request, $keys, $mappingParam)
  {
      $dataSets = [];

      // Extract relevant data from the request based on provided keys
      foreach ($keys as $key) {
          $dataSets[$key] = $request[$key] ?? [];
      }

      // Initialize result array with an empty set for recursive combination building
      $result = [[]];

      // Generate all possible combinations of the provided arrays
      foreach ($dataSets as $key => $dataSet) {
          $tempResult = [];
          foreach ($result as $partial) {
              foreach ($dataSet as $item) {
                  // Merge each existing combination with the new key-value pair
                  $tempResult[] = array_merge($partial, [$key => $item]);
              }
          }
          $result = $tempResult;
      }

      $resultData = [];

      // Map the generated combinations to the expected result format
      foreach ($result as $key => $value) {
          $dataRowResult = [];
          foreach ($mappingParam as $keyMap => $valueMap) {
              // Check if the mapping key exists in the provided keys before extracting the value
              if (in_array((explode('.', $valueMap)[0]), $keys)) {
                  $dataRowResult[$keyMap] = $this->getValueFromPath($value, $valueMap);
              }
          }
          $resultData[] = $dataRowResult;
      }

      return $resultData;
  }

}
