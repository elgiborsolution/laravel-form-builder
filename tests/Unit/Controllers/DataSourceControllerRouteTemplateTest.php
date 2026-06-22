<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataSourceController;
use ESolution\DataSources\Services\CustomQueryService;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class DataSourceControllerRouteTemplateTest extends TestCase
{
    public function test_it_detects_only_braced_route_parameter_segments(): void
    {
        $controller = $this->makeController();

        $this->assertSame([], $controller->exposeExtractRouteTemplateParameters('d-invoice'));
        $this->assertSame(['product_id'], $controller->exposeExtractRouteTemplateParameters('product/{product_id}'));
        $this->assertSame(
            ['customer_id', 'detail_id'],
            $controller->exposeExtractRouteTemplateParameters('customer/{customer_id}/detail/{detail_id}')
        );

        [$matched, $params] = $controller->exposeMatchRouteTemplate('d-invoice', 'd-invoice');
        $this->assertTrue($matched);
        $this->assertSame([], $params);

        [$matched, $params] = $controller->exposeMatchRouteTemplate('product/{product_id}', 'product/123');
        $this->assertTrue($matched);
        $this->assertSame(['product_id' => '123'], $params);
    }

    public function test_it_rejects_route_only_templates(): void
    {
        $controller = $this->makeController();

        $this->assertSame(
            'Route URL must contain a fixed path prefix before route parameters.',
            $controller->exposeValidateRouteTemplate('{product_id}')
        );
        $this->assertSame(
            'Route URL must contain a fixed path prefix before route parameters.',
            $controller->exposeValidateRouteTemplate('{invoice_id}')
        );

        $this->assertNull($controller->exposeValidateRouteTemplate('product/{product_id}'));
        $this->assertNull($controller->exposeValidateRouteTemplate('customer/{customer_id}/detail/{detail_id}'));
    }

    public function test_it_resolves_the_canonical_runtime_path_without_the_legacy_prefix(): void
    {
        $controller = new TestableDataSourceControllerRuntime(
            $this->createMock(DataQueryService::class),
            $this->createMock(CustomQueryService::class),
            $this->createMock(Pipeline::class),
            $this->createMock(DatabaseMetadataProvider::class)
        );

        $response = $controller->executeRuntimeRequest(Request::create('/api/product/123', 'GET'), 'product/123');

        $this->assertNotNull($response);
        $this->assertSame(['product', '123'], $controller->capturedExecuteQueryArgs);
    }

    private function makeController(): TestableDataSourceController
    {
        return new TestableDataSourceController(
            $this->createMock(DataQueryService::class),
            $this->createMock(CustomQueryService::class),
            $this->createMock(Pipeline::class),
            $this->createMock(DatabaseMetadataProvider::class)
        );
    }
}

class TestableDataSourceController extends DataSourceController
{
    public function exposeMatchRouteTemplate(string $template, string $path): array
    {
        return $this->matchRouteTemplate($template, $path);
    }

    public function exposeValidateRouteTemplate(string $template): ?string
    {
        return $this->validateRouteTemplate($template);
    }

    public function exposeExtractRouteTemplateParameters(string $template): array
    {
        return $this->extractRouteTemplateParameters($template);
    }
}

class TestableDataSourceControllerRuntime extends DataSourceController
{
    public ?array $capturedExecuteQueryArgs = null;

    public function executeQuery(Request $request, $id, ?string $routePath = null)
    {
        $this->capturedExecuteQueryArgs = [(string) $id, $routePath];

        return new JsonResponse(['data' => []], 200);
    }

    protected function resolveDataSourceForExecution(string $identifier, ?string $routePath = null): ?array
    {
        return [
            new \ESolution\DataSources\Models\DataSource(),
            [],
            $identifier,
        ];
    }
}
