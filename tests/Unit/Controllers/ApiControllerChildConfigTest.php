<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\ApiController;
use ESolution\DataSources\Models\ApiTable;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerChildConfigTest extends TestCase
{
    public function test_it_uses_child_update_key_when_present(): void
    {
        $controller = $this->makeController();

        $this->assertSame('code', $controller->exposeResolveChildUpdateKey([
            'table_name' => 'cart_detail',
            'primary_key' => 'id',
            'child_update_key' => 'code',
        ]));
    }

    public function test_it_falls_back_to_primary_key_when_child_update_key_is_missing(): void
    {
        $controller = $this->makeController();

        $this->assertSame('id', $controller->exposeResolveChildUpdateKey([
            'table_name' => 'cart_detail',
            'primary_key' => 'id',
            'child_update_key' => null,
        ]));
    }

    public function test_it_normalizes_missing_child_strategy(): void
    {
        $controller = $this->makeController();

        $this->assertSame('KEEP_EXISTING', $controller->exposeNormalizeMissingChildStrategy(null));
        $this->assertSame('DELETE_MISSING', $controller->exposeNormalizeMissingChildStrategy('delete_missing'));
    }

    public function test_it_treats_builder_missing_placeholder_as_missing_value(): void
    {
        $controller = $this->makeController();

        $this->assertTrue($controller->exposeIsMissingBuilderValue('__ESOLUTION_DATA_BUILDER_MISSING__'));
        $this->assertSame(
            [
                'product_name' => 'example1',
                'cart_kasir_id' => 164,
            ],
            $controller->exposeSanitizePersistedRow([
                'id' => '__ESOLUTION_DATA_BUILDER_MISSING__',
                'product_name' => 'example1',
                'cart_kasir_id' => 164,
            ], 'id')
        );
    }

    private function makeController(): TestableChildConfigApiController
    {
        return new TestableChildConfigApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            new Pipeline(),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(AfterHitApiDispatcher::class)
        );
    }
}

class TestableChildConfigApiController extends ApiController
{
    public function exposeResolveChildUpdateKey(array $childTable): string
    {
        return $this->resolveChildUpdateKey($childTable);
    }

    public function exposeNormalizeMissingChildStrategy(mixed $value): string
    {
        return $this->normalizeMissingChildStrategy($value);
    }

    public function exposeIsMissingBuilderValue(mixed $value): bool
    {
        return $this->isMissingBuilderValue($value);
    }

    public function exposeSanitizePersistedRow(array $row, ?string $lookupKey = null): array
    {
        return $this->sanitizePersistedRow($row, $lookupKey);
    }
}
