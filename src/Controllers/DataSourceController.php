<?php
namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Models\DataSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class DataSourceController extends Controller
{
  public function index()
  {
    return response()->json(DataSource::with('parameters')->get());
  }

  public function store(Request $request)
  {
    $validated = $request->validate([
      'name' => 'required|string',
      'table_name' => 'required|string',
      'use_custom_query' => 'boolean',
      'columns' => 'required|array',
      'custom_query' => 'nullable|string'
    ]);

    if (!empty($validated['custom_query']) && !DataSource::validateQuery($validated['custom_query'])) {
      return response()->json(['error' => 'Only SELECT queries are allowed'], 400);
    }

    $dataSource = DataSource::create([
      'name' => $validated['name'],
      'table_name' => $validated['table_name'],
      'use_custom_query' => $validated['use_custom_query'],
      'columns' => json_encode($validated['columns']),
      'custom_query' => $validated['custom_query'] ?? null
    ]);

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
    $validated = $request->validate([
      'name' => 'required|string',
      'table_name' => 'required|string',
      'use_custom_query' => 'boolean',
      'columns' => 'required|array',
      'custom_query' => 'nullable|string'
    ]);

    if (!empty($validated['custom_query']) && !DataSource::validateQuery($validated['custom_query'])) {
      return response()->json(['error' => 'Only SELECT queries are allowed'], 400);
    }

    $dataSource = DataSource::findOrFail($id);
    $dataSource->update([
      'name' => $validated['name'],
      'table_name' => $validated['table_name'],
      'use_custom_query' => $validated['use_custom_query'],
      'columns' => json_encode($validated['columns']),
      'custom_query' => $validated['custom_query'] ?? null
    ]);

    return response()->json($dataSource->load(['parameters']));
  }

  // DELETE a data source
  public function destroy($id)
  {
    DataSource::destroy($id);
    return response()->json(['message' => 'Data source deleted']);
  }

  public function listTables()
  {
    $tables = DB::select("SHOW TABLES");
    $databaseName = env('DB_DATABASE');

    // Laravel biasanya mengembalikan array dengan key yang berbeda tergantung pada driver
    $tableList = [];
    foreach ($tables as $table) {
      $tableList[] = array_values((array) $table)[0];
    }

    return response()->json($tableList);
  }

  public function listColumns($table)
  {
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
    $dataSource = DataSource::with('parameters')->findOrFail($id);
    $queryParams = [];

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

    $result = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($query, $queryParams) {
      return DB::select($query, $queryParams);
    });

    return response()->json($result);
  }
}
