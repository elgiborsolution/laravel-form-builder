<?php

namespace ESolution\DataSources\Tests\Unit\Support;

use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use PHPUnit\Framework\TestCase;

class DynamicApiConfigResolverTest extends TestCase
{
    public function test_it_resolves_restore_routes_to_the_parent_endpoint_and_action(): void
    {
        $resolver = new class extends DynamicApiConfigResolver {
            public function findByEndpointAndMethod(string $endpoint, string $method): ?ApiConfig
            {
                if ($endpoint === 'products' && strtoupper($method) === 'POST') {
                    $config = new ApiConfig();
                    $config->id = 99;
                    $config->endpoint = 'products';
                    $config->method = 'POST';

                    return $config;
                }

                return null;
            }
        };

        $resolved = $resolver->resolve('products/15/restore', 'POST');

        $this->assertSame('restore', $resolved['action']);
        $this->assertSame('products', $resolved['endpoint']);
        $this->assertSame('15', (string) $resolved['id']);
        $this->assertInstanceOf(ApiConfig::class, $resolved['config']);
    }

    public function test_it_keeps_normal_routes_unchanged(): void
    {
        $resolver = new class extends DynamicApiConfigResolver {
            public function findByEndpointAndMethod(string $endpoint, string $method): ?ApiConfig
            {
                if ($endpoint === 'products' && strtoupper($method) === 'GET') {
                    $config = new ApiConfig();
                    $config->id = 42;
                    $config->endpoint = 'products';
                    $config->method = 'GET';

                    return $config;
                }

                return null;
            }
        };

        $resolved = $resolver->resolve('products', 'GET');

        $this->assertNull($resolved['action']);
        $this->assertSame('products', $resolved['endpoint']);
        $this->assertNull($resolved['id']);
        $this->assertInstanceOf(ApiConfig::class, $resolved['config']);
    }
}
