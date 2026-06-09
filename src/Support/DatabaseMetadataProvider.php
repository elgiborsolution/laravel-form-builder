<?php

namespace ESolution\DataSources\Support;

use ESolution\DataSources\Contracts\DatabaseDriver;

class DatabaseMetadataProvider
{
    public function __construct(
        protected ?DatabaseDriverResolver $driverResolver = null
    ) {
        $this->driverResolver ??= new DatabaseDriverResolver();
    }

    /**
     * @return array<int, string>
     */
    public function listTables(?string $connectionName = null): array
    {
        $connection = DatabaseConnection::connection($connectionName);

        return $this->driver($connectionName)->listTables($connection);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listColumns(string $table, ?string $connectionName = null): array
    {
        $connection = DatabaseConnection::connection($connectionName);

        return $this->driver($connectionName)->listColumns($connection, $table);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listIndexes(string $table, ?string $connectionName = null): array
    {
        $connection = DatabaseConnection::connection($connectionName);

        return $this->driver($connectionName)->listIndexes($connection, $table);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForeignKeys(string $table, ?string $connectionName = null): array
    {
        $connection = DatabaseConnection::connection($connectionName);

        return $this->driver($connectionName)->listForeignKeys($connection, $table);
    }

    public function driver(?string $connectionName = null): DatabaseDriver
    {
        return $this->driverResolver?->resolve($connectionName) ?? (new DatabaseDriverResolver())->resolve($connectionName);
    }
}
