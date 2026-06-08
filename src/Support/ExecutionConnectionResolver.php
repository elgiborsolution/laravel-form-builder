<?php

namespace ESolution\DataSources\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecutionConnectionResolver
{
    public function resolve(?Request $request = null): string
    {
        if ($request instanceof Request) {
            $connection = $request->attributes->get('datasources.connection_name');

            if (is_string($connection) && trim($connection) !== '') {
                return trim($connection);
            }
        }

        return DatabaseConnection::configuredName();
    }

    public function connection(?Request $request = null): ConnectionInterface
    {
        return DB::connection($this->resolve($request));
    }
}
