<?php

use Illuminate\Support\Facades\Route;
use ESolution\DataSources\Controllers\DataSourceController;

Route::apiResource('data-source', DataSourceController::class);
Route::get('data-source/{id}/query', [DataSourceController::class, 'executeQuery']);

Route::get('data-source/tables', [DataSourceController::class, 'listTables']);
Route::get('data-source/tables/{table}/columns', [DataSourceController::class, 'listColumns']);
