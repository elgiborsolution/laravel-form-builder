<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Package Database Connection
    |--------------------------------------------------------------------------
    |
    | Default connection used by the package itself for its own models,
    | queries, validation checks, and runtime API execution.
    |
    */
    'database_connection' => env('LARAVEL_FORM_BUILDER_DB_CONNECTION', env('DB_CONNECTION')),

    /*
    |--------------------------------------------------------------------------
    | Middleware Connection Overrides
    |--------------------------------------------------------------------------
    |
    | Map middleware signatures to a database connection name that should be
    | forced before that middleware executes.
    |
    | Examples:
    |   'auth:*'   => env('LARAVEL_FORM_BUILDER_AUTH_DB_CONNECTION'),
    |   'passport' => env('LARAVEL_FORM_BUILDER_PASSPORT_DB_CONNECTION'),
    |   'sanctum'  => env('LARAVEL_FORM_BUILDER_SANCTUM_DB_CONNECTION'),
    |   'jwt.auth' => env('LARAVEL_FORM_BUILDER_JWT_DB_CONNECTION'),
    |
    */
    'middleware_connections' => [
        'rules' => [
            'auth:*' => env('LARAVEL_FORM_BUILDER_AUTH_DB_CONNECTION'),
            'passport' => env('LARAVEL_FORM_BUILDER_PASSPORT_DB_CONNECTION'),
            'sanctum' => env('LARAVEL_FORM_BUILDER_SANCTUM_DB_CONNECTION'),
            'jwt.auth' => env('LARAVEL_FORM_BUILDER_JWT_DB_CONNECTION'),
        ],
    ],

    'cache' => [
        'dynamic_api_ttl' => 60,
    ],

    'runtime_variable_registry' => null,

    'default_api_middlewares' => ['auth:api'],

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
