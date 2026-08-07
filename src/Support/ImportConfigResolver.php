<?php

namespace ESolution\DataSources\Support;

use ESolution\DataSources\Models\ImportConfig;
use Illuminate\Support\Facades\Cache;

class ImportConfigResolver
{
    public function findByEndpoint(string $endpoint): ?ImportConfig
    {
        $endpoint = $this->normalizeEndpoint($endpoint);

        if ($endpoint === '') {
            return null;
        }

        return Cache::remember(
            self::cacheKey($endpoint),
            now()->addSeconds((int) config('datasources.cache.dynamic_api_ttl', 60)),
            static fn (): ?ImportConfig => ImportConfig::query()
                ->with(['parentTable', 'childTables'])
                ->where('enabled', true)
                ->where('endpoint', $endpoint)
                ->first()
        );
    }

    public function forget(string $endpoint): void
    {
        Cache::forget(self::cacheKey($this->normalizeEndpoint($endpoint)));
    }

    public static function cacheKey(string $endpoint): string
    {
        return DatabaseConnection::cachePrefix('datasources.import.' . md5(trim($endpoint, '/')));
    }

    public function normalizeEndpoint(?string $endpoint): string
    {
        return trim((string) $endpoint, '/');
    }
}
