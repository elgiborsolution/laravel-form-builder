<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Models\ApiTable;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class DataAPIBuilderParentOperationTest extends TestCase
{
    public function test_it_round_trips_parent_operation_inside_existing_data_params_storage(): void
    {
        $controller = $this->makeController();
        $lookupKeys = [
            ['column' => 'email', 'parameter' => 'customer.email'],
            ['column' => 'tenant_code', 'parameter' => 'tenant_code'],
        ];
        $storedDataParams = $controller->exposeEncodeParentOperationDataParams([
            'operation' => 'CREATE_OR_UPDATE',
            'lookup_keys' => $lookupKeys,
            'data_params' => ['name' => 'customer.name'],
        ], 'POST');

        $table = new ApiTable([
            'parent_id' => 0,
            'table_name' => 'customers',
            'primary_key' => 'id',
            'key_update_delete' => 'id',
            'data_params' => $storedDataParams,
        ]);
        $serialized = $controller->exposeSerializeApiTable($table);

        $this->assertArrayHasKey('__parent_operation', $storedDataParams);
        $this->assertSame(['name' => 'customer.name'], $table->data_params);
        $this->assertSame('CREATE_OR_UPDATE', $table->operation);
        $this->assertSame($lookupKeys, $table->lookup_keys);
        $this->assertSame('CREATE_OR_UPDATE', $table->toArray()['operation']);
        $this->assertSame($lookupKeys, $table->toArray()['lookup_keys']);
        $this->assertSame(['name' => 'customer.name'], $table->toArray()['data_params']);
        $this->assertSame(['name' => 'customer.name'], $serialized['data_params']);
        $this->assertSame('CREATE_OR_UPDATE', $serialized['operation']);
        $this->assertSame($lookupKeys, $serialized['lookup_keys']);

        $reloadedTable = new ApiTable();
        $reloadedTable->setRawAttributes([
            'parent_id' => 0,
            'table_name' => 'customers',
            'primary_key' => 'id',
            'key_update_delete' => 'id',
            'data_params' => json_encode($storedDataParams),
        ], true);
        $this->assertSame('CREATE_OR_UPDATE', $reloadedTable->operation);
        $this->assertSame($lookupKeys, $reloadedTable->lookup_keys);
        $this->assertSame(['name' => 'customer.name'], $reloadedTable->data_params);
    }

    public function test_non_post_configs_do_not_persist_parent_operation_metadata(): void
    {
        $storedDataParams = $this->makeController()->exposeEncodeParentOperationDataParams([
            'operation' => 'CREATE_OR_UPDATE',
            'lookup_keys' => [['column' => 'email', 'parameter' => 'email']],
            'data_params' => ['name' => 'name'],
        ], 'PUT');

        $this->assertSame(['name' => 'name'], $storedDataParams);
    }

    public function test_it_validates_declared_nested_scalar_lookup_paths_and_rejects_array_paths(): void
    {
        $controller = $this->makeController();
        $params = [
            ['name' => 'customer', 'type' => 'object', 'params' => [
                ['name' => 'email', 'type' => 'string'],
                ['name' => 'tags', 'type' => 'array string'],
            ]],
        ];
        $parent = [
            'table_name' => 'customers',
            'operation' => 'CREATE_OR_UPDATE',
            'lookup_keys' => [['column' => 'email', 'parameter' => 'customer.email']],
        ];

        $this->assertNull($controller->exposeValidateParentOperationConfig($parent, $params, 'POST'));
        $invalidArrayPath = $controller->exposeValidateParentOperationConfig([
            'table_name' => 'customers',
            'operation' => 'CREATE_OR_UPDATE',
            'lookup_keys' => [['column' => 'email', 'parameter' => 'customer.tags']],
        ], $params, 'POST');
        $this->assertSame(422, $invalidArrayPath->getStatusCode());

        $duplicateKeys = $controller->exposeValidateParentOperationConfig([
            'table_name' => 'customers',
            'operation' => 'CREATE_OR_UPDATE',
            'lookup_keys' => [
                ['column' => 'email', 'parameter' => 'customer.email'],
                ['column' => 'email', 'parameter' => 'customer.email'],
            ],
        ], $params, 'POST');
        $this->assertSame(422, $duplicateKeys->getStatusCode());
    }

    public function test_it_rejects_create_or_update_for_non_post_configs(): void
    {
        $response = $this->makeController()->exposeValidateParentOperationConfig([
            'operation' => 'CREATE_OR_UPDATE',
            'lookup_keys' => [['column' => 'email', 'parameter' => 'email']],
        ], [['name' => 'email', 'type' => 'string']], 'PUT');

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_it_rejects_parent_mapping_columns_reserved_by_lookup_keys(): void
    {
        $controller = $this->makeController();
        $parent = [
            'table_name' => 'customers',
            'operation' => 'CREATE_OR_UPDATE',
            'lookup_keys' => [['column' => 'email', 'parameter' => 'email']],
            'data_params' => ['name' => 'name'],
        ];
        $params = [['name' => 'email', 'type' => 'string'], ['name' => 'name', 'type' => 'string']];
        $this->assertNull($controller->exposeValidateParentOperationConfig($parent, $params, 'POST'));

        foreach (['email', ['value' => 'email', 'array_handling' => 'RAW_VALUE']] as $mapping) {
            $parent['data_params']['email'] = $mapping;
            $response = $controller->exposeValidateParentOperationConfig($parent, $params, 'POST');
            $this->assertSame(422, $response->getStatusCode());
            $this->assertStringContainsString("'email'", $response->getData(true)['message']);
            $this->assertStringContainsString('Mapping Data', $response->getData(true)['message']);
        }

        unset($parent['data_params']['email']);
        $this->assertNull($controller->exposeValidateParentOperationConfig($parent, $params, 'POST'));
        $parent['operation'] = 'CREATE';
        $parent['data_params']['email'] = 'email';
        $this->assertNull($controller->exposeValidateParentOperationConfig($parent, $params, 'POST'));
        $this->assertNull($controller->exposeValidateParentOperationConfig($parent, $params, 'PUT'));
    }

    public function test_imported_operation_metadata_cannot_bypass_mapping_conflict_validation(): void
    {
        $controller = $this->makeController();
        $parent = [
            'table_name' => 'customers',
            'data_params' => [
                'name' => 'name',
                '__parent_operation' => [
                    'version' => 1, 'operation' => 'CREATE_OR_UPDATE',
                    'lookup_keys' => [['column' => 'email', 'parameter' => 'email']],
                ],
            ],
        ];
        $params = [['name' => 'email', 'type' => 'string']];
        $this->assertNull($controller->exposeValidateParentOperationConfig($parent, $params, 'POST'));
        $parent['data_params']['email'] = 'email';
        $response = $controller->exposeValidateParentOperationConfig($parent, $params, 'POST');
        $this->assertSame(422, $response->getStatusCode());
    }

    private function makeController(): TestableParentOperationDataAPIBuilderController
    {
        return new TestableParentOperationDataAPIBuilderController(
            $this->createMock(DynamicApiConfigResolver::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );
    }
}

class TestableParentOperationDataAPIBuilderController extends DataAPIBuilderController
{
    public function exposeEncodeParentOperationDataParams(array $parentTable, string $method): array
    {
        return $this->encodeParentOperationDataParams($parentTable, $method);
    }

    public function exposeValidateParentOperationConfig(array $parentTable, array $params, string $method): ?\Illuminate\Http\JsonResponse
    {
        return $this->validateParentOperationConfig($parentTable, $params, $method);
    }

    public function exposeSerializeApiTable(ApiTable $table): array
    {
        return $this->serializeApiTable($table);
    }
}
