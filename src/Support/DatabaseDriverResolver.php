<?php

namespace ESolution\DataSources\Support;

use ESolution\DataSources\Contracts\DatabaseDriver;
use ESolution\DataSources\Database\Drivers\MySqlDatabaseDriver;
use ESolution\DataSources\Database\Drivers\PostgresDatabaseDriver;
use InvalidArgumentException;

class DatabaseDriverResolver
{
    public function resolve(?string $connectionName = null): DatabaseDriver
    {
        $driver = DatabaseConnection::connection($connectionName)->getDriverName();

        return match ($driver) {
            'mysql' => new MySqlDatabaseDriver(),
            'pgsql' => new PostgresDatabaseDriver(),
            default => throw new InvalidArgumentException('Unsupported database driver [' . $driver . ']. Only mysql and pgsql are supported.'),
        };
    }
}
