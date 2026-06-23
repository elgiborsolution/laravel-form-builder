<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Models\ApiTable;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use PHPUnit\Framework\TestCase;

class DataAPIBuilderControllerLookupKeyTest extends TestCase
{
    public function test_it_falls_back_to_primary_key_when_lookup_key_is_missing_in_array_payload(): void
    {
        $controller = $this->makeController();

        $this->assertSame(
            'id',
            $controller->exposeResolveParentLookupKey([
                'primary_key' => 'id',
                'key_update_delete' => null,
            ])
        );
    }

    public function test_it_uses_the_custom_lookup_key_when_present_on_model(): void
    {
        $controller = $this->makeController();
        $table = new ApiTable([
            'primary_key' => 'id',
            'key_update_delete' => 'code',
            'data_params' => [],
        ]);

        $this->assertSame('code', $controller->exposeResolveParentLookupKey($table));
        $this->assertSame(
            [
                'table_name' => null,
                'primary_key' => 'id',
                'key_update_delete' => 'code',
                'child_update_key' => 'id',
                'missing_child_strategy' => 'KEEP_EXISTING',
                'foreign_key' => null,
                'data_params' => [],
                'use_soft_delete' => false,
            ],
            $controller->exposeSerializeApiTable($table)
        );
    }

    public function test_it_preserves_existing_child_config_when_incoming_payload_is_blank(): void
    {
        $controller = $this->makeController();
        $existingChild = new ApiTable([
            'primary_key' => 'id',
            'child_update_key' => 'id',
            'missing_child_strategy' => 'DELETE_MISSING',
            'foreign_key' => 'cart_kasir_id',
        ]);

        $this->assertSame(
            'id',
            $controller->exposeResolveChildPrimaryKey([
                'table_name' => 'es_cart_kasir_detail',
                'primary_key' => null,
                'child_update_key' => '',
            ], $existingChild)
        );

        $this->assertSame(
            'id',
            $controller->exposeResolveChildUpdateKey([
                'table_name' => 'es_cart_kasir_detail',
                'primary_key' => null,
                'child_update_key' => '',
            ], $existingChild, 'id')
        );

        $this->assertSame(
            'DELETE_MISSING',
            $controller->exposeResolveMissingChildStrategy([
                'missing_child_strategy' => '',
            ], $existingChild)
        );
    }

    private function makeController(): TestableLookupKeyDataAPIBuilderController
    {
        return new TestableLookupKeyDataAPIBuilderController(
            $this->createMock(DynamicApiConfigResolver::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );
    }
}

class TestableLookupKeyDataAPIBuilderController extends DataAPIBuilderController
{
    public function exposeResolveParentLookupKey(mixed $table, mixed $fallback = null): ?string
    {
        return $this->resolveParentLookupKey($table, $fallback);
    }

    public function exposeResolveChildPrimaryKey(array $childTable, ?ApiTable $existingChild = null): string
    {
        return $this->resolveChildPrimaryKey($childTable, $existingChild);
    }

    public function exposeResolveChildUpdateKey(array $childTable, ?ApiTable $existingChild = null, ?string $resolvedPrimaryKey = null): string
    {
        return $this->resolveChildUpdateKey($childTable, $existingChild, $resolvedPrimaryKey);
    }

    public function exposeResolveMissingChildStrategy(array $childTable, ?ApiTable $existingChild = null): string
    {
        return $this->resolveMissingChildStrategy($childTable, $existingChild);
    }

    public function exposeSerializeApiTable(ApiTable $table): array
    {
        return $this->serializeApiTable($table);
    }
}
