<?php

namespace ESolution\DataSources\Http\Middleware;

use Closure;
use ESolution\DataSources\Support\DatabaseConnection;
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
        $initializedTenantContext = false;

        if ($connection === '') {
            $connection = $this->resolveTenantConnection($request);
            $initializedTenantContext = $connection !== '';
        }

        if ($connection === '') {
            return $next($request);
        }

        $request->attributes->set('datasources.connection_resolved', true);
        $request->attributes->set('datasources.connection_name', $connection);

        try {
            return $next($request);
        } finally {
            if ($initializedTenantContext) {
                $this->endTenantContext();
            }
        }
    }

    protected function resolveTenantConnection(Request $request): string
    {
        $tenantId = trim((string) $request->header('x-tenant'));

        if ($tenantId === '') {
            return '';
        }

        if (! function_exists('tenancy')) {
            return '';
        }

        try {
            tenancy()->initialize($tenantId);
        } catch (\Throwable $e) {
            return '';
        }

        $connection = DB::getDefaultConnection();

        if (! is_string($connection) || trim($connection) === '') {
            $connection = DatabaseConnection::configuredName();
        }

        return trim($connection);
    }

    protected function endTenantContext(): void
    {
        if (! function_exists('tenancy')) {
            return;
        }

        try {
            tenancy()->end();
        } catch (\Throwable $e) {
            // Ignore teardown failures so the wrapped request still completes.
        }
    }
}
