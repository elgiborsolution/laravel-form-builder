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

        $id = null;
        $config = $this->findParameterizedByPathAndMethod($dynamicPath, $method, $id);

        if ($config !== null) {
            return [
                'config' => $config,
                'id' => $id,
                'endpoint' => $config->endpoint,
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

    protected function findParameterizedByPathAndMethod(string $path, string $method, mixed &$resolvedId = null): ?ApiConfig
    {
        $path = $this->normalizeEndpoint($path);
        $method = strtoupper($method);
        $matches = [];

        ApiConfig::query()
            ->with(['parentTable', 'childTables'])
            ->where('enabled', true)
            ->where('method', $method)
            ->get()
            ->each(function (ApiConfig $config) use ($path, &$matches): void {
                [$matched, $parameters] = $this->matchEndpointTemplate((string) $config->endpoint, $path);

                if ($matched) {
                    $matches[] = [$config, $parameters, $this->endpointSpecificity((string) $config->endpoint)];
                }
            });

        $bestMatch = $this->selectBestEndpointMatch($matches);

        if ($bestMatch === null) {
            return null;
        }

        $resolvedId = array_values($bestMatch[1])[0] ?? null;

        return $bestMatch[0];
    }

    protected function matchEndpointTemplate(string $template, string $path): array
    {
        $templateSegments = explode('/', $this->normalizeEndpoint($template));
        $pathSegments = explode('/', $this->normalizeEndpoint($path));

        if (count($templateSegments) !== count($pathSegments)) {
            return [false, []];
        }

        $parameters = [];

        foreach ($templateSegments as $index => $segment) {
            if (preg_match('/^\{([^}]+)\}$/', $segment, $matches) === 1) {
                $parameters[$matches[1]] = $pathSegments[$index];
                continue;
            }

            if ($segment !== $pathSegments[$index]) {
                return [false, []];
            }
        }

        return [true, $parameters];
    }

    protected function endpointSpecificity(string $endpoint): int
    {
        $score = 0;

        foreach (explode('/', $this->normalizeEndpoint($endpoint)) as $segment) {
            $score = ($score * 10) + (preg_match('/^\{[^}]+\}$/', $segment) === 1 ? 0 : 1);
        }

        return $score;
    }

    protected function selectBestEndpointMatch(array $matches): ?array
    {
        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $left, array $right): int => $right[2] <=> $left[2]);

        return $matches[0];
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
