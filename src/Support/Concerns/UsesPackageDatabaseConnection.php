<?php

namespace ESolution\DataSources\Support\Concerns;

use ESolution\DataSources\Support\DatabaseConnection;

trait UsesPackageDatabaseConnection
{
    public function getConnectionName(): ?string
    {
        return DatabaseConnection::name();
    }
}
