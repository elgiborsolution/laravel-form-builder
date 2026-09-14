<?php

namespace ESolution\DataSources\Tests\Unit\Support;

use ESolution\DataSources\Support\ImportConfigResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use PHPUnit\Framework\TestCase;

class ImportBuilderRoutePatternTest extends TestCase
{
    public function test_import_suffix_routes_win_before_the_generic_dynamic_dispatcher(): void
    {
        $routes = new RouteCollection();
        $this->addImportRoutes($routes);
        $fallback = new Route(['GET', 'POST'], '{dynamicPath}', ['uses' => 'dynamic-dispatcher']);
        $fallback->where('dynamicPath', '.*');
        $routes->add($fallback);

        $this->assertRoute($routes, 'POST', '/r3r32/import', 'import.execute', 'r3r32');
        $this->assertRoute($routes, 'POST', '/tenant/tes/blabla/import', 'import.execute', 'tenant/tes/blabla');
        $this->assertRoute($routes, 'POST', '/tenant/tes/blabla/import/stage', 'import.stage', 'tenant/tes/blabla');
        $this->assertRoute(
            $routes,
            'GET',
            '/tenant/tes/blabla/import/temporary/60524833-51ec-41b4-86dd-ba81682f223e',
            'import.temporary',
            'tenant/tes/blabla'
        );

        $matched = $routes->match(Request::create('/tenant/tes/blabla', 'POST'));
        $this->assertSame('dynamic-dispatcher', $matched->getAction('uses'));
    }

    public function test_import_endpoint_normalization_preserves_safe_inner_segments(): void
    {
        $resolver = new ImportConfigResolver();

        $this->assertSame('tenant/tes/blabla', $resolver->normalizeEndpoint(' /tenant//tes/blabla/ '));
    }

    private function addImportRoutes(RouteCollection $routes): void
    {
        $definitions = [
            ['GET', '{endpoint}/import/template', 'import.template'],
            ['POST', '{endpoint}/import/test', 'import.test'],
            ['GET', '{endpoint}/import/temporary/{importUuid}', 'import.temporary'],
            ['POST', '{endpoint}/import/stage', 'import.stage'],
            ['POST', '{endpoint}/import', 'import.execute'],
        ];

        foreach ($definitions as [$method, $uri, $name]) {
            $route = new Route([$method], $uri, ['uses' => $name]);
            $route->where('endpoint', '.*');
            if ($name === 'import.temporary') {
                $route->where('importUuid', '[0-9a-fA-F-]{36}');
            }
            $routes->add($route);
        }
    }

    private function assertRoute(RouteCollection $routes, string $method, string $uri, string $uses, string $endpoint): void
    {
        $matched = $routes->match(Request::create($uri, $method));

        $this->assertSame($uses, $matched->getAction('uses'));
        $this->assertSame($endpoint, $matched->parameter('endpoint'));
    }
}
