<?php

namespace ESolution\DataSources\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseConnection
{
    public static function name(): string
    {
        $connection = config('datasources.database_connection');
        if (! is_string($connection) || trim($connection) === '') {
            // The package connection is also the central metadata connection.
            // Prefer Stancl's explicit central connection when the package
            // override is absent, since the Laravel default can be tenant-scoped.
            $connection = config('tenancy.database.central_connection');
        }
        if (! is_string($connection) || trim($connection) === '') {
            $connection = env('LARAVEL_FORM_BUILDER_DB_CONNECTION', '');
        }
        if (! is_string($connection) || trim($connection) === '') {
            $connection = env('DB_CONNECTION', '');
        }
        if (! is_string($connection) || trim($connection) === '') {
            if (self::tenantContextIsInitialized()) {
                throw new \LogicException(
                    'The central Data Source metadata connection is not configured.'
                );
            }

            $connection = config('database.default', '');
        }

        return is_string($connection) ? trim($connection) : '';
    }

    public static function configuredName(): string
    {
        return self::name();
    }

    public static function connection(?string $connectionName = null): ConnectionInterface
    {
        $connection = is_string($connectionName) && trim($connectionName) !== ''
            ? trim($connectionName)
            : self::configuredName();

        return DB::connection($connection);
    }

    public static function schema(?string $connectionName = null)
    {
        $connection = is_string($connectionName) && trim($connectionName) !== ''
            ? trim($connectionName)
            : self::configuredName();

        return Schema::connection($connection);
    }

    public static function table(string $table, ?string $connectionName = null)
    {
        return self::connection($connectionName)->table($table);
    }

    public static function validationTable(string $table): string
    {
        $connection = trim(self::configuredName());

        return $connection !== '' ? $connection . '.' . $table : $table;
    }

    public static function cachePrefix(string $key): string
    {
        $scope = self::configuredName();

        try {
            $databaseName = self::connection($scope)->getDatabaseName();

            if (is_string($databaseName) && trim($databaseName) !== '') {
                $scope .= '@' . trim($databaseName);
            }
        } catch (\Throwable $e) {
            // Fall back to the connection name when the connection is unavailable.
        }

        return $scope . ':' . $key;
    }

    private static function tenantContextIsInitialized(): bool
    {
        if (! function_exists('tenancy') || ! function_exists('app')) {
            return false;
        }

        try {
            return app()->bound('Stancl\\Tenancy\\Tenancy')
                && (bool) tenancy()->initialized;
        } catch (\Throwable $exception) {
            return false;
        }
    }

}
