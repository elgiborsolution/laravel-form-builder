<?php

return [
    'database_connection' => env('LARAVEL_FORM_BUILDER_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    'cache' => [
        'dynamic_api_ttl' => 60,
    ],

    'runtime_variable_registry' => null,

    'routes' => [
        'prefix' => env('DATASOURCES_ROUTE_PREFIX', 'api'),
        'version' => env('DATASOURCES_ROUTE_VERSION'),
        'name' => 'datasources.',

        'management' => [
            'middleware' => ['api'],
            'reserved_paths' => [
                'data-source',
                'data-picker',
                'table-builder',
                'api-config',
            ],
        ],

        'tenant' => [
            'enabled' => true,
            'middleware' => ['api'],
            'initialize_middleware' => 'Stancl\\Tenancy\\Middleware\\InitializeTenancyByRequestData',
            'reserved_paths' => [
                'data-source-tenant',
            ],
        ],

        'dynamic' => [
            'prefix' => null,
            'middleware' => ['api'],
        ],
    ],
];
