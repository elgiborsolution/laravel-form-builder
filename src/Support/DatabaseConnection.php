<?php

namespace ESolution\DataSources\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseConnection
{
    public static function name(): string
    {
        $connection = config('datasources.database_connection', env('LARAVEL_FORM_BUILDER_DB_CONNECTION', env('DB_CONNECTION')));
        
        return is_string($connection) && trim($connection) !== ''
            ? trim($connection)
            : (string) config('database.default', '');
    }

    public static function connection(): ConnectionInterface
    {
        return DB::connection(self::name());
    }

    public static function schema()
    {
        return Schema::connection(self::name());
    }

    public static function table(string $table)
    {
        return self::connection()->table($table);
    }

    public static function validationTable(string $table): string
    {
        return self::name() . '.' . $table;
    }

    public static function cachePrefix(string $key): string
    {
        $scope = self::name();

        try {
            $databaseName = self::connection()->getDatabaseName();

            if (is_string($databaseName) && trim($databaseName) !== '') {
                $scope .= '@' . trim($databaseName);
            }
        } catch (\Throwable $e) {
            // Fall back to the connection name when the connection is unavailable.
        }

        return $scope . ':' . $key;
    }
}
