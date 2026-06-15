<?php

namespace ESolution\DataSources\Tests\Unit\Services;

use ESolution\DataSources\Contracts\DatabaseDriver;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Support\DatabaseDriverResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DataQueryServiceRuntimeTest extends TestCase
{
    public function test_it_parses_runtime_values_inside_datasource_definition(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );

        $dataSource = new DataSource();
        $dataSource->use_custom_query = 1;
        $dataSource->custom_query = 'select * from invoices where company_id = {{ auth.company_id }}';
        $dataSource->table_name = 'invoices_{{ auth.company_id }}';
        $dataSource->columns = ['id', 'name'];
        $dataSource->setRelation('parameters', new Collection([
            (object) [
                'param_name' => 'company_id',
                'param_type' => 'integer',
                'is_required' => 1,
                'param_default_value' => '{{ auth.company_id }}',
                'operator' => '=',
            ],
        ]));

        $service->executeForDataSource(new Request(), $dataSource, 'datasource_q_test');

        $definition = $service->capturedDefinition;

        $this->assertSame('select * from invoices where company_id = 10', $definition['custom_query']);
        $this->assertSame('invoices_10', $definition['table_name']);
        $this->assertSame(10, $definition['parameters'][0]['default']);
    }

    public function test_it_keeps_static_values_intact(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );

        $dataSource = new DataSource();
        $dataSource->use_custom_query = 0;
        $dataSource->custom_query = null;
        $dataSource->table_name = 'invoices';
        $dataSource->columns = ['id', 'name'];
        $dataSource->setRelation('parameters', new Collection([]));

        $service->executeForDataSource(new Request(), $dataSource, 'datasource_q_test_static');

        $definition = $service->capturedDefinition;

        $this->assertSame('invoices', $definition['table_name']);
        $this->assertSame(['id', 'name'], $definition['columns']);
    }

    public function test_it_formats_select_columns_for_mysql_and_preserves_raw_expressions(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );
        $service->useConnectionName('mysql_connection');

        [$countQuery, $selectQuery] = $service->exposeBuildBaseQueries([
            'table_name' => 'tc_truck_type_positions',
            'columns' => [
                'id',
                'truck_type_id',
                '  position_code  ',
                '`position_name`',
                'COUNT(*) as aggregate',
                'CONCAT(first_name, " ", last_name)',
                'table_alias.column_name as alias_name',
                'table_alias.*',
            ],
        ]);

        $this->assertSame(
            'SELECT count(*) as aggregate FROM tc_truck_type_positions WHERE 1=1',
            $countQuery
        );
        $this->assertSame(
            'SELECT `id` , `truck_type_id` , `position_code` , `position_name` , COUNT(*) as aggregate , CONCAT(first_name, " ", last_name) , table_alias.column_name as alias_name , `table_alias`.* FROM tc_truck_type_positions WHERE 1=1',
            $selectQuery
        );
    }

    public function test_it_formats_select_columns_for_postgres_with_double_quotes(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('pgsql'))
        );
        $service->useConnectionName('pgsql_connection');

        [, $selectQuery] = $service->exposeBuildBaseQueries([
            'table_name' => 'public.invoices',
            'columns' => [
                'id',
                'customer_id',
                '"invoice_number"',
                'invoice_items.*',
            ],
        ]);

        $this->assertSame(
            'SELECT "id" , "customer_id" , "invoice_number" , "invoice_items".* FROM public.invoices WHERE 1=1',
            $selectQuery
        );
    }

    public function test_it_strips_trailing_semicolons_from_custom_queries_before_wrapping(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );

        [$countQuery, $selectQuery] = $service->exposeBuildBaseQueries([
            'use_custom_query' => true,
            'custom_query' => "SELECT id FROM products;\n",
        ]);

        $this->assertSame(
            'SELECT count(*) as aggregate FROM (SELECT id FROM products) AS tableCustom WHERE 1=1',
            $countQuery
        );
        $this->assertSame(
            'SELECT * FROM (SELECT id FROM products) AS tableCustom WHERE 1=1',
            $selectQuery
        );
    }

    public function test_it_appends_soft_delete_filter_for_select_table_when_deleted_at_exists(): void
    {
        $service = new SoftDeleteCapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql')),
            true
        );

        $dataSource = new DataSource();
        $dataSource->use_custom_query = 0;
        $dataSource->use_soft_delete = 1;
        $dataSource->table_name = 'products';
        $dataSource->columns = ['id', 'name'];
        $dataSource->setRelation('parameters', new Collection([]));

        $service->executeForDataSource(new Request(), $dataSource, 'datasource_q_soft_delete_enabled');

        $this->assertStringContainsString('WHERE 1=1 AND deleted_at IS NULL', $service->capturedQuery);
        $this->assertStringContainsString('WHERE 1=1 AND deleted_at IS NULL', $service->capturedQueryCount);
    }

    public function test_it_skips_soft_delete_filter_when_deleted_at_is_missing(): void
    {
        $service = new SoftDeleteCapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql')),
            false
        );

        $dataSource = new DataSource();
        $dataSource->use_custom_query = 0;
        $dataSource->use_soft_delete = 1;
        $dataSource->table_name = 'products';
        $dataSource->columns = ['id', 'name'];
        $dataSource->setRelation('parameters', new Collection([]));

        $service->executeForDataSource(new Request(), $dataSource, 'datasource_q_soft_delete_disabled');

        $this->assertStringNotContainsString('deleted_at IS NULL', $service->capturedQuery);
        $this->assertStringNotContainsString('deleted_at IS NULL', $service->capturedQueryCount);
    }

    public function test_it_maps_ilike_operator_to_driver_specific_sql(): void
    {
        $mysqlService = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );
        $mysqlService->useConnectionName('mysql_connection');

        $pgsqlService = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('pgsql'))
        );
        $pgsqlService->useConnectionName('pgsql_connection');

        $this->assertSame(
            " AND `name` LIKE '%john%'",
            $mysqlService->exposeBuildFilterClause('name', 'ILIKE', 'john')
        );
        $this->assertSame(
            " AND \"name\" ILIKE '%john%'",
            $pgsqlService->exposeBuildFilterClause('name', 'ILIKE', 'john')
        );
    }

    public function test_it_removes_conditional_block_when_custom_parameter_is_missing(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );

        $dataSource = new DataSource();
        $dataSource->use_custom_query = 1;
        $dataSource->custom_query = <<<SQL
SELECT id, code, name, barcode
FROM es_products
WHERE 1 = 1
[[ AND id = :key ]]
SQL;
        $dataSource->table_name = 'es_products';
        $dataSource->columns = ['id', 'code', 'name', 'barcode'];
        $dataSource->custom_parameters = [
            [
                'name' => 'key',
                'type' => 'integer',
                'required' => false,
                'default' => null,
                'description' => '',
            ],
        ];
        $dataSource->setRelation('parameters', new Collection([]));

        $service->executeForDataSource(new Request(), $dataSource, 'datasource_q_conditional_missing');

        $this->assertStringContainsString('FROM (SELECT id, code, name, barcode', $service->capturedQuery);
        $this->assertStringNotContainsString('AND id =', $service->capturedQuery);
        $this->assertStringNotContainsString(':key', $service->capturedQuery);
    }

    public function test_it_keeps_conditional_block_when_custom_parameter_is_present(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );

        $dataSource = new DataSource();
        $dataSource->use_custom_query = 1;
        $dataSource->custom_query = <<<SQL
SELECT id, code, name, barcode
FROM es_products
WHERE 1 = 1
[[ AND id = :key ]]
SQL;
        $dataSource->table_name = 'es_products';
        $dataSource->columns = ['id', 'code', 'name', 'barcode'];
        $dataSource->custom_parameters = [
            [
                'name' => 'key',
                'type' => 'integer',
                'required' => false,
                'default' => null,
                'description' => '',
            ],
        ];
        $dataSource->setRelation('parameters', new Collection([]));

        $request = Request::create('/api/data-source/products', 'GET', ['key' => 10]);
        $service->executeForDataSource($request, $dataSource, 'datasource_q_conditional_present');

        $this->assertStringContainsString('AND id = 10', $service->capturedQuery);
        $this->assertStringNotContainsString(':key', $service->capturedQuery);
    }

    public function test_it_removes_entire_block_when_one_of_multiple_custom_parameters_is_missing(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            new ExecutionConnectionResolver(),
            new FakeDatabaseDriverResolver(new FakeDatabaseDriver('mysql'))
        );

        $dataSource = new DataSource();
        $dataSource->use_custom_query = 1;
        $dataSource->custom_query = <<<SQL
SELECT id, code, name, barcode
FROM es_products
WHERE 1 = 1
[[ AND customer_id = :customer_id AND status = :status ]]
SQL;
        $dataSource->table_name = 'es_products';
        $dataSource->columns = ['id', 'code', 'name', 'barcode'];
        $dataSource->custom_parameters = [
            [
                'name' => 'customer_id',
                'type' => 'integer',
                'required' => false,
                'default' => null,
                'description' => '',
            ],
            [
                'name' => 'status',
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => '',
            ],
        ];
        $dataSource->setRelation('parameters', new Collection([]));

        $request = Request::create('/api/data-source/products', 'GET', ['customer_id' => 1]);
        $service->executeForDataSource($request, $dataSource, 'datasource_q_conditional_multiple');

        $this->assertStringNotContainsString('customer_id = 1', $service->capturedQuery);
        $this->assertStringNotContainsString('status', $service->capturedQuery);
    }
}

class CapturingDataQueryService extends DataQueryService
{
    public array $capturedDefinition = [];
    public string $capturedQuery = '';
    public string $capturedQueryCount = '';

    public function execute(Request $request, array $definition): JsonResponse
    {
        $this->capturedDefinition = $definition;

        return new JsonResponse(['ok' => true], 200);
    }

    protected function runQueryDirectly(mixed $queryCount, mixed $query, Request $request, array $definition): array|Collection|\Illuminate\Pagination\LengthAwarePaginator
    {
        $this->capturedQuery = (string) $query;
        $this->capturedQueryCount = (string) $queryCount;

        return ['data' => []];
    }

    public function exposeBuildBaseQueries(array $definition): array
    {
        return $this->buildBaseQueries($definition);
    }

    public function exposeNormalizeCustomQuery(string $query): string
    {
        return $this->normalizeCustomQuery($query);
    }

    public function useConnectionName(string $connectionName): void
    {
        $this->executionConnectionName = $connectionName;
    }

    public function exposeBuildFilterClause(string $field, string $operator, mixed $value): string
    {
        return $this->buildFilterClause($field, $operator, $value);
    }

    protected function quoteSqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}

class SoftDeleteCapturingDataQueryService extends DataQueryService
{
    public string $capturedQuery = '';
    public string $capturedQueryCount = '';

    public function __construct(
        DynamicVariableParser $runtimeVariableParser,
        ExecutionConnectionResolver $executionConnectionResolver,
        ?DatabaseDriverResolver $databaseDriverResolver = null,
        private readonly bool $tableHasDeletedAt = true
    ) {
        parent::__construct($runtimeVariableParser, $executionConnectionResolver, $databaseDriverResolver);
    }

    protected function runQueryDirectly(mixed $queryCount, mixed $query, Request $request, array $definition): array|Collection|\Illuminate\Pagination\LengthAwarePaginator
    {
        $this->capturedQuery = (string) $query;
        $this->capturedQueryCount = (string) $queryCount;

        return ['data' => []];
    }

    protected function tableHasColumn(string $tableName, string $columnName): bool
    {
        return $this->tableHasDeletedAt && strtolower($columnName) === 'deleted_at';
    }
}

class FakeDatabaseDriverResolver extends DatabaseDriverResolver
{
    public function __construct(
        private readonly DatabaseDriver $driver
    ) {
    }

    public function resolve(?string $connectionName = null): DatabaseDriver
    {
        return $this->driver;
    }
}

class FakeDatabaseDriver implements DatabaseDriver
{
    public function __construct(
        private readonly string $name
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function quoteIdentifier(string $identifier): string
    {
        $quote = $this->name === 'pgsql' ? '"' : '`';

        return $quote . trim($identifier, " \t\n\r\0\x0B`\"") . $quote;
    }

    public function compilePaginatedQuery(string $query, int $offset, int $limit): string
    {
        return $this->name === 'pgsql'
            ? $query . ' LIMIT ' . $limit . ' OFFSET ' . $offset
            : $query . ' LIMIT ' . $offset . ', ' . $limit;
    }

    public function compileExplainQuery(string $query): string
    {
        return 'EXPLAIN ' . $query;
    }

    public function normalizeLikeOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));

        if ($this->name === 'mysql') {
            return match ($operator) {
                'ILIKE' => 'LIKE',
                'NOT ILIKE' => 'NOT LIKE',
                default => $operator,
            };
        }

        return $operator;
    }

    public function listTables(ConnectionInterface $connection): array
    {
        return [];
    }

    public function listColumns(ConnectionInterface $connection, string $table): array
    {
        return [];
    }

    public function listIndexes(ConnectionInterface $connection, string $table): array
    {
        return [];
    }

    public function listForeignKeys(ConnectionInterface $connection, string $table): array
    {
        return [];
    }
}
