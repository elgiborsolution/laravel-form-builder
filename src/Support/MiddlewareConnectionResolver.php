<?php

namespace ESolution\DataSources\Support;

use ESolution\DataSources\Http\Middleware\ForceDatabaseConnection;
use Illuminate\Support\Str;

class MiddlewareConnectionResolver
{
    /**
     * Inject connection-scoped middleware before matched middleware entries.
     *
     * @param array<int, mixed> $middlewares
     * @return array<int, mixed>
     */
    public function inject(array $middlewares): array
    {
        $result = [];

        foreach ($middlewares as $middleware) {
            if (! is_string($middleware) || trim($middleware) === '') {
                continue;
            }

            $connection = $this->resolveConnection($middleware);

            if ($connection !== null) {
                $result[] = ForceDatabaseConnection::class . ':' . $connection;
            }

            $result[] = $middleware;
        }

        return $result;
    }

    /**
     * Resolve a configured database connection for a middleware signature.
     *
     * @param string $middleware
     * @return string|null
     */
    public function resolveConnection(string $middleware): ?string
    {
        $rules = (array) config('datasources.middleware_connections.rules', []);

        foreach ($rules as $pattern => $connection) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            if (! is_string($connection) || trim($connection) === '') {
                continue;
            }

            if ($this->matches($middleware, $pattern)) {
                return trim($connection);
            }
        }

        return null;
    }

    /**
     * Determine whether a middleware string matches the configured pattern.
     *
     * Supports exact middleware names, class names, and wildcard signatures such as
     * `auth:*`.
     */
    protected function matches(string $middleware, string $pattern): bool
    {
        $candidate = trim($middleware);
        $base = Str::before($candidate, ':');

        return Str::is($pattern, $candidate) || Str::is($pattern, $base);
    }
}
