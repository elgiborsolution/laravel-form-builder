<?php

namespace ESolution\DataSources\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** Select query databases independently of the shared definition store. */
class DataSourceConnectionScope
{
    public function tenancyEnabled(): bool
    {
        return function_exists('tenancy')
            && app()->bound('Stancl\Tenancy\Tenancy')
            && (bool) config('datasources.routes.tenant.enabled', true);
    }

    public function scope(Request $request, ?string $default = null): string
    {
        $scope = $request->input('database_scope', $default ?? (
            trim((string) $request->header('X-Tenant')) !== '' ? 'tenant' : 'central'
        ));
        if (! in_array($scope, ['central', 'tenant'], true)) {
            throw ValidationException::withMessages(['database_scope' => 'Select central or tenant scope.']);
        }

        return $scope;
    }

    protected function tenancy(): mixed
    {
        return tenancy();
    }

    public function centralConnection(): string
    {
        return DatabaseConnection::configuredName() ?: (string) config(
            'tenancy.database.central_connection', config('database.default')
        );
    }

    public function run(Request $request, string $scope, Closure $callback): mixed
    {
        $previous = $request->attributes->get('datasources.connection_name');
        $initialized = false;
        try {
            $connection = $this->centralConnection();
            if ($scope === 'tenant') {
                if (! $this->tenancyEnabled()) {
                    throw ValidationException::withMessages(['database_scope' => 'Tenancy is not enabled.']);
                }

                $headerTenant = trim((string) $request->header('X-Tenant'));
                $selectedTenant = trim((string) $request->input('tenant_id', ''));
                $currentTenant = $this->tenancy()->tenant;
                $currentId = $currentTenant ? (string) $currentTenant->getTenantKey() : '';
                if ($currentId !== '' && $headerTenant !== '' && $currentId !== $headerTenant) {
                    throw ValidationException::withMessages(['tenant_id' => 'The requested tenant does not match the initialized tenant.']);
                }
                // A tenant login cannot select a different tenant through the builder.
                $boundTenant = $headerTenant !== '' ? $headerTenant : $currentId;
                if ($boundTenant !== '' && $selectedTenant !== '' && $boundTenant !== $selectedTenant) {
                    throw ValidationException::withMessages(['tenant_id' => 'The selected tenant does not match the current tenant.']);
                }
                $tenantId = $boundTenant ?: $selectedTenant;
                if ($tenantId === '') {
                    throw ValidationException::withMessages(['tenant_id' => 'Select a tenant before accessing the tenant database.']);
                }

                $tenant = $currentId === $tenantId ? $currentTenant : $this->tenancy()->find($tenantId);
                if ($tenant === null) {
                    throw ValidationException::withMessages(['tenant_id' => 'The selected tenant could not be resolved.']);
                }
                $this->authorizeTenantAccess($request, $tenant);

                if ($currentId !== $tenantId) {
                    try {
                        $initialized = true;
                        $this->tenancy()->initialize($tenantId);
                    } catch (\Throwable $exception) {
                        throw ValidationException::withMessages(['tenant_id' => 'The selected tenant could not be resolved.']);
                    }
                }
                $connection = (string) DB::getDefaultConnection();
                if (! $this->tenancy()->initialized || $connection === '' || $connection === $this->centralConnection()) {
                    throw ValidationException::withMessages(['tenant_id' => 'The tenant database connection could not be resolved.']);
                }
            }

            $request->attributes->set('datasources.connection_name', $connection);

            return $callback();
        } finally {
            $request->attributes->set('datasources.connection_name', $previous);
            if ($initialized) {
                $this->tenancy()->end();
            }
        }
    }

    protected function authorizeTenantAccess(Request $request, object $tenant): void
    {
        $user = $request->user();
        if ($user === null && $request->bearerToken()) {
            $user = $request->user('api');
        }
        if ($user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication is required to access a tenant database.');
        }
        // Management middleware remains the default access boundary. Honor host
        // tenant restrictions before initializing or inspecting the selected database.
        if (! app()->bound(Gate::class)) {
            return;
        }
        $gate = app(Gate::class)->forUser($user);
        try {
            if ($gate->has('datasources.select-tenant')) {
                $gate->authorize('datasources.select-tenant', $tenant);
            } elseif (($policy = $gate->getPolicyFor($tenant)) && method_exists($policy, 'view')) {
                $gate->authorize('view', $tenant);
            }
        } catch (AuthorizationException $exception) {
            // Preserve denial status even in hosts with custom API exception handlers.
            throw new HttpException($exception->status() ?: 403, $exception->getMessage(), $exception);
        }
    }
}
