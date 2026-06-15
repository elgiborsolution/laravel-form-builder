<?php

namespace ESolution\DataSources\Support;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Support\Facades\Cache;

class DynamicApiConfigResolver
{
    public function resolve(string $dynamicPath, string $method): array
    {
        $method = strtoupper($method);
        $dynamicPath = $this->normalizeEndpoint($dynamicPath);
        $default = ['config' => null, 'id' => null, 'endpoint' => null, 'action' => null];

        if ($dynamicPath === '') {
            return $default;
        }

        $segments = explode('/', $dynamicPath);

        if (count($segments) >= 3 && strtolower((string) end($segments)) === 'restore') {
            array_pop($segments);
            $id = array_pop($segments);
            $endpoint = implode('/', $segments);
            $config = $this->findByEndpointAndMethod($endpoint, 'POST');

            if ($config !== null) {
                return [
                    'config' => $config,
                    'id' => $id,
                    'endpoint' => $endpoint,
                    'action' => 'restore',
                ];
            }
        }

        $config = $this->findByEndpointAndMethod($dynamicPath, $method);
        if ($config !== null) {
            return [
                'config' => $config,
                'id' => null,
                'endpoint' => $dynamicPath,
                'action' => null,
            ];
        }

        if (count($segments) < 2) {
            return [
                'config' => null,
                'id' => null,
                'endpoint' => $dynamicPath,
                'action' => null,
            ];
        }

        $id = array_pop($segments);
        $endpoint = implode('/', $segments);
        $config = $this->findByEndpointAndMethod($endpoint, $method);

        return [
            'config' => $config,
            'id' => $config !== null ? $id : null,
            'endpoint' => $endpoint,
            'action' => null,
        ];
    }

    public function findByEndpointAndMethod(string $endpoint, string $method): ?ApiConfig
    {
        $endpoint = $this->normalizeEndpoint($endpoint);
        $method = strtoupper($method);

        return Cache::remember(
            self::cacheKey($endpoint, $method),
            now()->addSeconds((int) config('datasources.cache.dynamic_api_ttl', 60)),
            static fn (): ?ApiConfig => ApiConfig::query()
                ->with(['parentTable', 'childTables'])
                ->where('enabled', true)
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->first()
        );
    }

    public function forget(string $endpoint, string $method): void
    {
        Cache::forget(self::cacheKey($endpoint, $method));
    }

    public static function cacheKey(string $endpoint, string $method): string
    {
        return DatabaseConnection::cachePrefix('datasources.dynamic_api.' . strtoupper($method) . '.' . md5(trim($endpoint, '/')));
    }

    public function normalizeEndpoint(?string $endpoint): string
    {
        return trim((string) $endpoint, '/');
    }

    public function isReservedEndpoint(string $endpoint): bool
    {
        $normalized = $this->normalizeEndpoint($endpoint);

        foreach ($this->reservedPaths() as $reservedPath) {
            $reservedPath = $this->normalizeEndpoint($reservedPath);

            if ($normalized === $reservedPath || str_starts_with($normalized, $reservedPath . '/')) {
                return true;
            }
        }

        return false;
    }

    protected function reservedPaths(): array
    {
        return array_merge(
            config('datasources.routes.management.reserved_paths', []),
            config('datasources.routes.tenant.reserved_paths', [])
        );
    }
}
