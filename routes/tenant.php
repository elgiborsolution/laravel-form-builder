<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use ESolution\DataSources\Controllers\DataSourceController;

Route::middleware([
    InitializeTenancyByRequestData::class,
])->group(function () {
    Route::group(['prefix' => 'api', 'as' => 'api.'], function () {
        Route::get('data-source-tenant/tables', [DataSourceController::class, 'listTables']);
        Route::get('data-source-tenant/tables/{table}/columns', [DataSourceController::class, 'listColumns']);

	Route::get('data-source-tenant/{id}/query', [DataSourceController::class, 'executeQuery']);

        Route::apiResource('data-source-tenant', DataSourceController::class); 
    });

});
