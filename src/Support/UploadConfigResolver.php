<?php

namespace ESolution\DataSources\Support;

use ESolution\DataSources\Models\UploadConfig;
use Illuminate\Support\Facades\Cache;

class UploadConfigResolver
{
    public function resolve(string $dynamicPath): ?UploadConfig
    {
        $dynamicPath = $this->normalizeEndpoint($dynamicPath);

        if ($dynamicPath === '') {
            return null;
        }

        return Cache::remember(
            self::cacheKey($dynamicPath),
            now()->addSeconds((int) config('datasources.cache.dynamic_upload_ttl', 60)),
            static fn (): ?UploadConfig => UploadConfig::query()
                ->where('enabled', true)
                ->where('endpoint', $dynamicPath)
                ->first()
        );
    }

    public function forget(string $endpoint): void
    {
        Cache::forget(self::cacheKey($this->normalizeEndpoint($endpoint)));
    }

    public static function cacheKey(string $endpoint): string
    {
        return DatabaseConnection::cachePrefix('datasources.dynamic_upload.' . md5(trim($endpoint, '/')));
    }

    public function normalizeEndpoint(?string $endpoint): string
    {
        $endpoint = trim((string) $endpoint, '/');

        if ($endpoint === '') {
            return '';
        }

        if (! str_starts_with($endpoint, 'upload/')) {
            $endpoint = 'upload/' . $endpoint;
        }

        return $endpoint;
    }
}
