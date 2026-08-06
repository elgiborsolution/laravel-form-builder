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

    public function test_static_route_is_more_specific_than_parameterized_route(): void
    {
        $resolver = new class extends DynamicApiConfigResolver {
            public function exposeMatch(string $template, string $path): array
            {
                return $this->matchEndpointTemplate($template, $path);
            }

            public function exposeSpecificity(string $endpoint): int
            {
                return $this->endpointSpecificity($endpoint);
            }

            public function exposeBest(array $matches): ?array
            {
                return $this->selectBestEndpointMatch($matches);
            }
        };

        $static = new ApiConfig(['endpoint' => 'purchase/list']);
        $parameterized = new ApiConfig(['endpoint' => 'purchase/{id}']);

        [$staticMatched] = $resolver->exposeMatch('purchase/list', 'purchase/list');
        [$parameterMatched, $parameters] = $resolver->exposeMatch('purchase/{id}', 'purchase/list');

        $this->assertTrue($staticMatched);
        $this->assertTrue($parameterMatched);
        $this->assertSame(['id' => 'list'], $parameters);
        $this->assertGreaterThan(
            $resolver->exposeSpecificity('purchase/{id}'),
            $resolver->exposeSpecificity('purchase/list')
        );
        $best = $resolver->exposeBest([
            [$parameterized, ['id' => 'list'], $resolver->exposeSpecificity('purchase/{id}')],
            [$static, [], $resolver->exposeSpecificity('purchase/list')],
        ]);
        $this->assertSame('purchase/list', $best[0]->endpoint);
    }
}
