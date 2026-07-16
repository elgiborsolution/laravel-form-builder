<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataSourceController;
use ESolution\DataSources\Models\DataSource;
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

    public function test_it_rejects_central_data_sources_from_tenant_requests_before_execution(): void
    {
        $controller = new TestableDataSourceControllerRuntime(
            $this->createMock(DataQueryService::class),
            $this->createMock(CustomQueryService::class),
            $this->createMock(Pipeline::class),
            $this->createMock(DatabaseMetadataProvider::class)
        );

        $response = $controller->executeRuntimeRequest(
            Request::create('/api/tesas', 'GET', [], [], [], [
                'HTTP_X_TENANT' => 'jayasuksesrejeki',
            ]),
            'tesas'
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($controller->capturedExecuteQueryArgs);
        $this->assertFalse($controller->executeQueryCalled);
    }

    public function test_it_treats_empty_x_tenant_values_as_central_requests(): void
    {
        $controller = $this->makeController();

        $this->assertSame(
            'central',
            $controller->exposeResolveRequestDatabaseScope(Request::create('/api/tesas', 'GET', [], [], [], [
                'HTTP_X_TENANT' => '   ',
            ]))
        );
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

    public function exposeResolveRequestDatabaseScope(Request $request): string
    {
        return $this->resolveRequestDatabaseScope($request);
    }
}

class TestableDataSourceControllerRuntime extends DataSourceController
{
    public ?array $capturedExecuteQueryArgs = null;
    public bool $executeQueryCalled = false;

    public function executeQuery(Request $request, $id, ?string $routePath = null)
    {
        $this->executeQueryCalled = true;
        $this->capturedExecuteQueryArgs = [(string) $id, $routePath];

        return new JsonResponse(['data' => []], 200);
    }

    protected function resolveDataSourceForExecution(string $identifier, ?string $routePath = null): ?array
    {
        $dataSource = new DataSource();
        $dataSource->name = $identifier;
        $dataSource->database_scope = 'central';

        return [
            $dataSource,
            [],
            $identifier,
        ];
    }
}
