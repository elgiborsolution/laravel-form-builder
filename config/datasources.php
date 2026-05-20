<?php

return [
    'cache' => [
        'dynamic_api_ttl' => 60,
    ],

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
