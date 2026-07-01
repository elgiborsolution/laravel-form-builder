<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Runtime\DefaultRuntimeVariableRegistry;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerLoopInsertRegressionTest extends TestCase
{
    /**
     * @dataProvider createLoopInsertCases
     */
    public function test_it_builds_one_child_row_per_current_item_for_create_flow(
        array $requestPayload,
        array $dataParams,
        array $expectedTenantIds
    ): void {
        $controller = $this->makeController();
        $request = Request::create('/api/users', 'POST', $requestPayload);

        $rows = $controller->exposeBuildMappedTableRows($dataParams, $request, []);

        $this->assertCount(count($expectedTenantIds), $rows);

        foreach ($expectedTenantIds as $index => $expectedTenantId) {
            $this->assertArrayHasKey('tenant_id', $rows[$index]);
            $this->assertNotNull($rows[$index]['tenant_id']);
            $this->assertSame($expectedTenantId, $rows[$index]['tenant_id']);
        }
    }

    public function createLoopInsertCases(): array
    {
        return [
            'array string' => [
                [
                    'tenants' => ['599b8264-a812-464e-ad53-1afb9fea092f'],
                ],
                [
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                ],
                ['599b8264-a812-464e-ad53-1afb9fea092f'],
            ],
            'array integer' => [
                [
                    'tenants' => [11, 12, 13],
                ],
                [
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                ],
                [11, 12, 13],
            ],
            'duplicate array string' => [
                [
                    'tenants' => ['tenant-a', 'tenant-a'],
                ],
                [
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                ],
                ['tenant-a', 'tenant-a'],
            ],
            'array object' => [
                [
                    'tenants' => [
                        ['id' => 'tenant-a'],
                        ['id' => 'tenant-b'],
                    ],
                ],
                [
                    'tenant_id' => [
                        'value' => 'tenants.id',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                ],
                ['tenant-a', 'tenant-b'],
            ],
        ];
    }

    public function test_it_persists_every_loop_insert_row_on_update_flow_without_null_tenant_ids(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/users/53', 'PUT', [
            'tenants' => ['tenant-a', 'tenant-b'],
        ]);

        $childRows = $controller->exposeBuildMappedTableRows([
            'tenant_id' => [
                'value' => 'tenants',
                'array_handling' => 'LOOP_INSERT',
            ],
        ], $request, []);

        $controller->exposePersistChildTableRows(
            new FakeLoopInsertConnection(),
            [
                'foreign_key' => 'user_id',
                'child_update_key' => 'tenant_id',
                'table_name' => 'user_tenants',
                'data_params' => [
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                ],
            ],
            'user_tenants',
            $childRows,
            [53],
            null,
            $request
        );

        $this->assertCount(2, $controller->insertedPayloads);
        $this->assertSame('tenant-a', $controller->insertedPayloads[0]['tenant_id']);
        $this->assertSame('tenant-b', $controller->insertedPayloads[1]['tenant_id']);
        $this->assertSame(53, $controller->insertedPayloads[0]['user_id']);
        $this->assertSame(53, $controller->insertedPayloads[1]['user_id']);
        $this->assertNotNull($controller->insertedPayloads[0]['tenant_id']);
        $this->assertNotNull($controller->insertedPayloads[1]['tenant_id']);
    }

    public function test_it_uses_the_normalized_column_name_for_list_based_mappings(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/users', 'POST', [
            'tenants' => ['tenant-a'],
        ]);

        $rows = $controller->exposeBuildMappedTableRows([
            [
                'column' => 'tenant_id',
                'value' => 'tenants',
                'array_handling' => 'LOOP_INSERT',
            ],
        ], $request, []);

        $this->assertCount(1, $rows);
        $this->assertSame('tenant-a', $rows[0]['tenant_id']);
        $this->assertArrayNotHasKey('0', $rows[0]);
    }

    public function test_it_re_evaluates_runtime_fields_for_each_primitive_loop_insert_row(): void
    {
        $controller = $this->makeControllerWithRuntimeRegistry();
        $request = Request::create('/api/users', 'POST', [
            'tenants' => ['tenant-a', 'tenant-b'],
        ]);

        $rows = $controller->exposeBuildMappedTableRows([
            'id' => ['value' => '{{ uuid.random }}'],
            'tenant_id' => [
                'value' => 'tenants',
                'array_handling' => 'LOOP_INSERT',
            ],
        ], $request, []);

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['id'], $rows[1]['id']);
        $this->assertSame('tenant-a', $rows[0]['tenant_id']);
        $this->assertSame('tenant-b', $rows[1]['tenant_id']);
    }

    public function test_it_persists_unique_runtime_primary_keys_for_each_loop_insert_child_row(): void
    {
        $controller = $this->makeControllerWithRuntimeRegistry();
        $request = Request::create('/api/users/53', 'PUT', [
            'tenants' => ['tenant-a', 'tenant-b', 'tenant-c'],
        ]);

        $childRows = $controller->exposeBuildMappedTableRows([
            'id' => ['value' => '{{ uuid.random }}'],
            'tenant_id' => [
                'value' => 'tenants',
                'array_handling' => 'LOOP_INSERT',
            ],
        ], $request, []);

        $connection = new FakeLoopInsertConnection();

        $controller->exposePersistChildTableRows(
            $connection,
            [
                'foreign_key' => 'user_id',
                'primary_key' => 'id',
                'child_update_key' => 'id',
                'missing_child_strategy' => 'KEEP_EXISTING',
                'table_name' => 'tenant_user',
                'data_params' => [
                    'id' => ['value' => '{{ uuid.random }}'],
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                ],
            ],
            'tenant_user',
            $childRows,
            [53],
            null,
            $request
        );

        $this->assertCount(3, $controller->insertedPayloads);
        $this->assertSame(3, count(array_unique(array_column($controller->insertedPayloads, 'id'))));
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-c'], array_column($controller->insertedPayloads, 'tenant_id'));
        $this->assertSame([53, 53, 53], array_column($controller->insertedPayloads, 'user_id'));
    }

    public function test_it_resolves_parent_runtime_variables_before_child_rows_are_built(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 08:25:10', 'Asia/Bangkok'));

        try {
            $controller = $this->makeControllerWithRuntimeRegistry();
            $request = Request::create('/api/customers', 'POST', [
                'tenants' => ['tenant-a', 'tenant-b', 'tenant-c'],
            ]);

            $parentRows = $controller->exposeBuildMappedTableRows([
                'id' => ['value' => '{{ uuid.random }}'],
                'created_at' => ['value' => '{{ date.now }}'],
            ], $request, []);

            $childRows = $controller->exposeBuildMappedTableRows([
                'id' => ['value' => '{{ uuid.random }}'],
                'tenant_id' => [
                    'value' => 'tenants',
                    'array_handling' => 'LOOP_INSERT',
                ],
            ], $request, []);

            $this->assertCount(1, $parentRows);
            $this->assertNotEmpty($parentRows[0]['id']);
            $this->assertMatchesRegularExpression(
                '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/',
                (string) $parentRows[0]['created_at']
            );

            $this->assertCount(3, $childRows);
            $this->assertCount(3, array_unique(array_column($childRows, 'id')));
            $this->assertNotContains($parentRows[0]['id'], array_column($childRows, 'id'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_keeps_existing_child_rows_and_only_inserts_new_items_for_primitive_loop_insert_updates(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/users/53', 'PUT', [
            'tenants' => ['tenant-b', 'tenant-c'],
        ]);

        $childRows = $controller->exposeBuildMappedTableRows([
            'id' => ['value' => 'tenant_row_id'],
            'tenant_id' => [
                'value' => 'tenants',
                'array_handling' => 'LOOP_INSERT',
            ],
        ], $request, []);

        $connection = new FakeLoopInsertConnection([
            [
                'id' => 'tenant-row-a',
                'user_id' => 53,
                'tenant_id' => 'tenant-a',
            ],
            [
                'id' => 'tenant-row-b',
                'user_id' => 53,
                'tenant_id' => 'tenant-b',
            ],
        ]);

        $controller->exposePersistChildTableRows(
            $connection,
            [
                'foreign_key' => 'user_id',
                'child_update_key' => 'id',
                'missing_child_strategy' => 'KEEP_EXISTING',
                'table_name' => 'tenant_user',
                'data_params' => [
                    'id' => ['value' => 'tenant_row_id'],
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                ],
            ],
            'tenant_user',
            $childRows,
            [53],
            null,
            $request
        );

        $this->assertSame(1, $connection->insertCount);
        $this->assertSame(0, $connection->deleteCount);
        $this->assertCount(3, $connection->rows);
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-c'], array_values(array_map(
            static fn (array $row) => $row['tenant_id'],
            $connection->rows
        )));
        $this->assertSame('tenant-c', $controller->insertedPayloads[0]['tenant_id']);
        $this->assertSame(53, $controller->insertedPayloads[0]['user_id']);
    }

    public function test_it_keeps_entity_mode_update_flow_for_array_object_children(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/users/53', 'PUT', [
            'items' => [
                [
                    'id' => 'tenant-row-b',
                    'tenant_id' => 'tenant-b-updated',
                ],
            ],
        ]);

        $childRows = $controller->exposeBuildMappedTableRows([
            'id' => ['value' => 'items.id'],
            'tenant_id' => ['value' => 'items.tenant_id'],
        ], $request, []);

        $connection = new FakeLoopInsertConnection([
            [
                'id' => 'tenant-row-b',
                'user_id' => 53,
                'tenant_id' => 'tenant-b',
            ],
            [
                'id' => 'tenant-row-c',
                'user_id' => 53,
                'tenant_id' => 'tenant-c',
            ],
        ]);

        $controller->exposePersistChildTableRows(
            $connection,
            [
                'foreign_key' => 'user_id',
                'child_update_key' => 'id',
                'missing_child_strategy' => 'KEEP_EXISTING',
                'table_name' => 'tenant_user',
                'data_params' => [
                    'id' => ['value' => 'items.id'],
                    'tenant_id' => ['value' => 'items.tenant_id'],
                ],
            ],
            'tenant_user',
            $childRows,
            [53],
            null,
            $request
        );

        $this->assertSame(0, $connection->deleteCount);
        $this->assertSame(1, $connection->updateCount);
        $this->assertSame('tenant-b-updated', $connection->rows[0]['tenant_id']);
        $this->assertSame('tenant-c', $connection->rows[1]['tenant_id']);
    }

    public function test_it_matches_existing_collection_rows_by_child_update_key_for_array_string_updates(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/users/53', 'PUT', [
            'tenants' => [
                'tenant-a',
                'tenant-b',
            ],
        ]);

        $childRows = $controller->exposeBuildMappedTableRows([
            'tenant_id' => [
                'value' => 'tenants',
                'array_handling' => 'LOOP_INSERT',
            ],
            'created_at' => ['value' => '2026-07-01 10:00:00'],
            'updated_at' => ['value' => '2026-07-01 10:00:00'],
        ], $request, []);

        $connection = new FakeLoopInsertConnection([
            [
                'id' => 101,
                'user_id' => 53,
                'tenant_id' => 'tenant-a',
                'created_at' => '2026-06-30 10:00:00',
                'updated_at' => '2026-06-30 10:00:00',
            ],
        ]);

        $controller->exposePersistChildTableRows(
            $connection,
            [
                'foreign_key' => 'user_id',
                'primary_key' => 'id',
                'child_update_key' => 'tenant_id',
                'missing_child_strategy' => 'KEEP_EXISTING',
                'table_name' => 'tenant_userw',
                'data_params' => [
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                    'created_at' => ['value' => '2026-07-01 10:00:00'],
                    'updated_at' => ['value' => '2026-07-01 10:00:00'],
                ],
            ],
            'tenant_userw',
            $childRows,
            [53],
            null,
            $request
        );

        $this->assertSame(1, $connection->insertCount);
        $this->assertSame(0, $connection->updateCount);
        $this->assertSame(0, $connection->deleteCount);
        $this->assertCount(2, $connection->rows);
        $this->assertSame('tenant-a', $connection->rows[0]['tenant_id']);
        $this->assertSame('tenant-b', $connection->rows[1]['tenant_id']);
        $this->assertSame(53, $connection->rows[1]['user_id']);
        $this->assertSame('tenant-b', $controller->insertedPayloads[0]['tenant_id']);
        $this->assertSame(53, $controller->insertedPayloads[0]['user_id']);
    }

    public function test_it_handles_the_exact_update_payload_with_three_tenants(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/user/99527099-3628-4be7-b6a2-2b902d314bf4', 'PUT', [
            'name' => 'jhon doe',
            'username' => 'jhondoe',
            'password' => 'Admin@12345',
            'password_confirmation' => 'Admin@12345',
            'roles_id' => '2',
            'is_active' => true,
            'all_tenants' => 'specific',
            'tenants' => [
                '39fd298c-a1cc-4e3a-a473-e9e89c98eedb',
                'e977c596-9c09-40ab-b483-10101ce7c73b',
                '43b1138a-b5ec-420f-ad83-11aa2a3535ab',
            ],
        ]);

        $childRows = $controller->exposeBuildMappedTableRows([
            'tenant_id' => [
                'value' => 'tenants',
                'array_handling' => 'LOOP_INSERT',
            ],
            'created_at' => ['value' => '2026-07-01 10:00:00'],
            'updated_at' => ['value' => '2026-07-01 10:00:00'],
        ], $request, []);

        $this->assertCount(3, $childRows);
        $this->assertSame('39fd298c-a1cc-4e3a-a473-e9e89c98eedb', $childRows[0]['tenant_id']);
        $this->assertSame('e977c596-9c09-40ab-b483-10101ce7c73b', $childRows[1]['tenant_id']);
        $this->assertSame('43b1138a-b5ec-420f-ad83-11aa2a3535ab', $childRows[2]['tenant_id']);

        $controller->exposePersistChildTableRows(
            new FakeLoopInsertConnection(),
            [
                'foreign_key' => 'user_id',
                'primary_key' => 'id',
                'child_update_key' => 'tenant_id',
                'missing_child_strategy' => 'KEEP_EXISTING',
                'table_name' => 'tenant_user',
                'data_params' => [
                    'tenant_id' => [
                        'value' => 'tenants',
                        'array_handling' => 'LOOP_INSERT',
                    ],
                    'created_at' => ['value' => '2026-07-01 10:00:00'],
                    'updated_at' => ['value' => '2026-07-01 10:00:00'],
                ],
            ],
            'tenant_user',
            $childRows,
            [53],
            null,
            $request
        );

        $this->assertSame(3, count($controller->insertedPayloads));
        $this->assertSame(53, $controller->insertedPayloads[0]['user_id']);
        $this->assertSame(53, $controller->insertedPayloads[1]['user_id']);
        $this->assertSame(53, $controller->insertedPayloads[2]['user_id']);
    }

    private function makeController(): TestableLoopInsertApiController
    {
        return new TestableLoopInsertApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(AfterHitApiDispatcher::class)
        );
    }

    private function makeControllerWithRuntimeRegistry(): TestableLoopInsertApiController
    {
        return new TestableLoopInsertApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class),
            new DynamicVariableParser(new DefaultRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class),
            $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(AfterHitApiDispatcher::class)
        );
    }
}

class TestableLoopInsertApiController extends \ESolution\DataSources\Controllers\ApiController
{
    public array $insertedPayloads = [];

    public function exposeBuildMappedTableRows(array $dataParams, Request $request, array $flattenedParams, mixed $context = null): array
    {
        return $this->buildMappedTableRows($dataParams, $request, $flattenedParams, $context);
    }

    public function exposePersistChildTableRows(
        $connection,
        array $table,
        string $tableChild,
        array $childRows,
        array $parentIds,
        ?string $connectionName = null,
        ?Request $request = null
    ): void {
        $this->persistChildTableRows($connection, $table, $tableChild, $childRows, $parentIds, $connectionName, $request);
    }

    protected function insertTableRows(
        $connection,
        string $tableName,
        array $rows,
        ?string $primaryKey = null,
        bool $returnInsertedIds = true
    ): array
    {
        foreach ($rows as $row) {
            $this->insertedPayloads[] = $row;
        }

        if (is_object($connection) && property_exists($connection, 'insertCount')) {
            $connection->insertCount += count($rows);
        }

        return array_fill(0, count($rows), 1);
    }
}

class FakeLoopInsertConnection
{
    public array $rows = [];
    public int $insertCount = 0;
    public int $updateCount = 0;
    public int $deleteCount = 0;

    public function __construct(array $rows = [])
    {
        $this->rows = array_values($rows);
    }

    public function table(string $tableName): FakeLoopInsertQuery
    {
        return new FakeLoopInsertQuery($this);
    }
}

class FakeLoopInsertQuery
{
    private array $conditions = [];

    public function __construct(private FakeLoopInsertConnection $connection)
    {
    }

    public function where(mixed $column, mixed $value): self
    {
        $this->conditions[] = ['column' => $column, 'value' => $value];
        return $this;
    }

    public function whereNotIn(mixed $column, array $values): self
    {
        $this->conditions[] = ['column' => $column, 'not_in' => array_values($values)];
        return $this;
    }

    public function exists(): bool
    {
        return $this->findMatchingRowIndex() !== null;
    }

    public function update(array $values): int
    {
        $index = $this->findMatchingRowIndex();

        if ($index === null) {
            return 0;
        }

        $this->connection->rows[$index] = array_merge($this->connection->rows[$index], $values);
        $this->connection->updateCount++;

        return 1;
    }

    public function delete(): int
    {
        $matchedIndexes = $this->findMatchingRowIndexes();

        if ($matchedIndexes === []) {
            return 0;
        }

        $matchedIndexes = array_reverse($matchedIndexes);

        foreach ($matchedIndexes as $index) {
            array_splice($this->connection->rows, $index, 1);
            $this->connection->deleteCount++;
        }

        return count($matchedIndexes);
    }

    private function findMatchingRowIndex(): ?int
    {
        $indexes = $this->findMatchingRowIndexes();

        return $indexes[0] ?? null;
    }

    private function findMatchingRowIndexes(): array
    {
        $indexes = [];

        foreach ($this->connection->rows as $index => $row) {
            if ($this->rowMatchesConditions($row)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    private function rowMatchesConditions(array $row): bool
    {
        foreach ($this->conditions as $condition) {
            $column = $condition['column'];

            if (array_key_exists('not_in', $condition)) {
                if (in_array($row[$column] ?? null, $condition['not_in'], true)) {
                    return false;
                }

                continue;
            }

            if (($row[$column] ?? null) !== $condition['value']) {
                return false;
            }
        }

        return true;
    }
}
