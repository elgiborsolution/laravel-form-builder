<?php

use Illuminate\Support\Facades\Route;
use ESolution\DataSources\Controllers\DataSourceController;
use ESolution\DataSources\Controllers\DataPickerController;
use ESolution\DataSources\Controllers\DataTableBuilderController;


Route::group(['prefix' => 'api', 'as' => 'api.'], function () {
    Route::get('data-source/tables', [DataSourceController::class, 'listTables']);
    Route::get('data-source/tables/{table}/columns', [DataSourceController::class, 'listColumns']);

    Route::get('data-source/{id}/query', [DataSourceController::class, 'executeQuery']);

    Route::apiResource('data-source', DataSourceController::class);

    Route::apiResource('data-picker', DataPickerController::class);

    Route::apiResource('table-builder', DataTableBuilderController::class);
});
