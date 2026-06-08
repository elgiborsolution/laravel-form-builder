<?php

namespace ESolution\DataSources\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseConnection
{
    public static function name(): string
    {
        $connection = config('datasources.database_connection', env('LARAVEL_FORM_BUILDER_DB_CONNECTION', ''));

        return is_string($connection) && trim($connection) !== ''
            ? trim($connection)
            : '';
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
        return self::configuredName() . '.' . $table;
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

    public static function resolveExecutionConnectionName(?Request $request = null): string
    {
        if ($request instanceof Request) {
            $connection = $request->attributes->get('datasources.connection_name');

            if (is_string($connection) && trim($connection) !== '') {
                return trim($connection);
            }

            $tenantId = trim((string) $request->header('x-tenant'));

            if ($tenantId !== '' && function_exists('tenancy')) {
                try {
                    tenancy()->initialize($tenantId);
                    $resolved = DB::getDefaultConnection();

                    if (is_string($resolved) && trim($resolved) !== '') {
                        return trim($resolved);
                    }
                } catch (\Throwable $e) {
                    // Fall back to the package connection below.
                }
            }
        }

        return self::configuredName();
    }
}
