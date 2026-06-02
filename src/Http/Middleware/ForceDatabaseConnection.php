<?php

namespace ESolution\DataSources\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ForceDatabaseConnection
{
    /**
     * Temporarily switch the default database connection for the wrapped middleware.
     *
     * Usage:
     *   ESolution\DataSources\Http\Middleware\ForceDatabaseConnection:connection_name
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $connection
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ?string $connection = null)
    {
        $connection = is_string($connection) ? trim($connection) : '';

        if ($connection === '') {
            return $next($request);
        }

        $databaseManager = app('db');
        $previousDefaultConnection = DB::getDefaultConnection();

        try {
            config(['database.default' => $connection]);
            $databaseManager->setDefaultConnection($connection);
            DB::setDefaultConnection($connection);

            return $next($request);
        } finally {
            config(['database.default' => $previousDefaultConnection]);
            $databaseManager->setDefaultConnection($previousDefaultConnection);
            DB::setDefaultConnection($previousDefaultConnection);
        }
    }
}
