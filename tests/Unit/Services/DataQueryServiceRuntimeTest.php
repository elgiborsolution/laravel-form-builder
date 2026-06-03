<?php

namespace ESolution\DataSources\Tests\Unit\Services;

use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DataQueryServiceRuntimeTest extends TestCase
{
    public function test_it_parses_runtime_values_inside_datasource_definition(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
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
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
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

    public function test_it_formats_select_columns_with_backticks_and_preserves_raw_expressions(): void
    {
        $service = new CapturingDataQueryService(
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );

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
}

class CapturingDataQueryService extends DataQueryService
{
    public array $capturedDefinition = [];

    public function execute(Request $request, array $definition): JsonResponse
    {
        $this->capturedDefinition = $definition;

        return new JsonResponse(['ok' => true], 200);
    }

    public function exposeBuildBaseQueries(array $definition): array
    {
        return $this->buildBaseQueries($definition);
    }
}
