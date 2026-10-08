<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataTableBuilderController;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DataTableBuilderFilterTest extends TestCase
{
    private DataTableBuilderController $controller;
    private mixed $previousContainer;
    private mixed $previousFacade;
    private mixed $previousResolver;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository(['datasources' => ['database_connection' => 'central'], 'database' => ['default' => 'central']]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'central');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class {
            public function json(mixed $data, int $status = 200): \Illuminate\Http\JsonResponse
            {
                return new \Illuminate\Http\JsonResponse($data, $status);
            }
        });
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'), $app);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($capsule->getDatabaseManager()));
        $app->instance('validator', $validator);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Request::macro('validate', function (array $rules): array {
            return app('validator')->make($this->all(), $rules)->validate();
        });
        DB::connection('central')->getSchemaBuilder()->create('data_table_builders', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('default_tab')->nullable();
            $table->text('tabs')->nullable();
            foreach (['columns', 'filters', 'actions', 'params'] as $field) $table->text($field)->nullable();
            $table->timestamps();
        });
        $this->controller = new DataTableBuilderController();
    }

    protected function tearDown(): void
    {
        Request::flushMacros();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
    }

    public static function filterTypes(): array
    {
        $cases = [];
        foreach (['text', 'number', 'date', 'date_range', 'dropdown', 'multiselect', 'autocomplete'] as $type) {
            $filter = ['name' => $type === 'date_range' ? 'start_date' : 'filter_' . $type, 'type' => $type];
            if ($type === 'date_range') $filter += ['name_from' => 'start_date', 'name_to' => 'end_date'];
            if (in_array($type, ['dropdown', 'multiselect', 'autocomplete'], true)) {
                $filter += ['placeholder' => 'Choose an item', 'options_source' => 'custom', 'options' => [['label' => 'One', 'value' => 1]]];
            }
            $cases[$type] = [$filter];
        }
        return $cases;
    }

    private function payload(array $filter, string $code = 'filter-test'): array
    {
        return ['code' => $code, 'name' => 'Filter test', 'filters' => [$filter],
            'columns' => [['header' => 'ID', 'detail' => 'id']], 'actions' => [],
            'params' => ['source_type' => 'custom_api', 'api_config' => ['type' => 'internal', 'method' => 'GET', 'url' => '/api/items']]];
    }

    #[DataProvider('filterTypes')]
    public function test_create_update_and_get_preserve_filter_configuration(array $filter): void
    {
        $payload = $this->payload($filter);
        $created = $this->controller->store(Request::create('/table-builder', 'POST', $payload));
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame([$filter], $created->getData(true)['data']['filters']);
        $get = fn () => $this->controller->show(Request::create('/table-builder/filter-test'), 'filter-test')->getData(true)['data']['filters'];
        $this->assertSame([$filter], $get());
        if ($filter['type'] === 'date_range') {
            $filter['name_from'] = 'custom_start';
            $filter['name_to'] = 'custom_end';
        } else {
            $filter['placeholder'] = 'Updated hint';
        }
        $updated = $this->controller->update(Request::create('/table-builder/filter-test', 'PUT', $this->payload($filter)), 'filter-test');
        $this->assertSame(201, $updated->getStatusCode()); // Preserve the existing update response contract.
        $this->assertSame([$filter], $updated->getData(true)['data']['filters']);
        $this->assertSame([$filter], $get());
    }

    #[DataProvider('filterTypes')]
    public function test_array_and_json_imports_preserve_filter_configuration(array $filter): void
    {
        foreach ([false, true] as $encoded) {
            $code = $encoded ? 'encoded-filter' : 'array-filter';
            $payload = $this->payload($filter, $code);
            if ($encoded) {
                foreach (['columns', 'filters', 'actions', 'params'] as $field) $payload[$field] = json_encode($payload[$field]);
            }
            $response = $this->controller->import(Request::create('/table-builder/import', 'POST', ['rows' => $encoded ? json_encode([$payload]) : [$payload]]));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(1, $response->getData(true)['imported']);
            $this->assertSame(0, $response->getData(true)['failed']);
            $shown = $this->controller->show(Request::create('/table-builder/' . $code), $code);
            $this->assertSame([$filter], $shown->getData(true)['data']['filters']);
        }
    }

    public function test_legacy_filter_types_remain_valid_and_unknown_types_are_rejected(): void
    {
        foreach (['textarea', 'email', 'currency', 'datetime', 'select', 'radio', 'checkbox', 'switch', 'data-picker'] as $type) {
            $this->assertNull($this->controller->validateDetail(Request::create('/', 'POST', $this->payload(['name' => 'legacy', 'type' => $type]))));
        }
        $invalid = $this->controller->validateDetail(Request::create('/', 'POST', $this->payload(['name' => 'bad', 'type' => 'unknown'])));
        $this->assertArrayHasKey('type', $invalid->getData(true)['error']);
    }

    public function test_date_range_names_must_be_strings_but_remain_optional_for_legacy_defaults(): void
    {
        $filter = ['name' => 'start_date', 'type' => 'date_range'];
        $this->assertNull($this->controller->validateDetail(Request::create('/', 'POST', $this->payload($filter))));
        foreach (['name_from', 'name_to'] as $field) {
            $invalid = $this->controller->validateDetail(Request::create('/', 'POST', $this->payload($filter + [$field => ['invalid']])));
            $this->assertArrayHasKey($field, $invalid->getData(true)['error']);
        }
    }

    public static function optionalPresentation(): array
    {
        return [
            'omitted' => [[], []],
            'null' => [['header' => null], ['label' => null]],
            'empty' => [['header' => ''], ['label' => '']],
            'custom' => [['header' => 'Custom display name'], ['label' => 'Custom action label']],
        ];
    }

    #[DataProvider('optionalPresentation')]
    public function test_optional_headers_and_action_labels_round_trip_through_create_update_and_import(array $header, array $label): void
    {
        $payload = $this->payload(['name' => 'query', 'type' => 'text']);
        $payload['columns'] = [$header + ['detail' => 'id']];
        $payload['actions'] = [
            $label + ['type' => 'link', 'url' => '/items', 'icon' => 'fa fa-eye'],
            $label + ['type' => 'emit', 'event' => 'onSelect', 'icon' => 'fa fa-check'],
        ];
        $response = $this->controller->store(Request::create('/', 'POST', $payload));
        $this->assertSame(201, $response->getStatusCode());
        foreach (['columns', 'actions'] as $field) $this->assertSame($payload[$field], $response->getData(true)['data'][$field]);
        $payload['name'] = 'Updated table';
        $response = $this->controller->update(Request::create('/', 'PUT', $payload), $payload['code']);
        $this->assertSame(201, $response->getStatusCode());
        $shown = $this->controller->show(Request::create('/'), $payload['code'])->getData(true)['data'];
        foreach (['columns', 'actions'] as $field) $this->assertSame($payload[$field], $shown[$field]);
        foreach ([false, true] as $encoded) {
            $row = $payload;
            $row['code'] = $encoded ? 'optional-json' : 'optional-array';
            if ($encoded) {
                foreach (['columns', 'actions', 'filters', 'params'] as $field) $row[$field] = json_encode($row[$field]);
            }
            $result = $this->controller->import(Request::create('/', 'POST', ['rows' => [$row]]))->getData(true);
            $this->assertSame(1, $result['imported']);
            $this->assertSame(0, $result['failed']);
            $shown = $this->controller->show(Request::create('/'), $row['code'])->getData(true)['data'];
            foreach (['columns', 'actions'] as $field) $this->assertSame($payload[$field], $shown[$field]);
        }
    }

    public static function missingColumnKeys(): array
    {
        return ['omitted' => [[]], 'null' => [['detail' => null]], 'empty' => [['detail' => '']], 'whitespace' => [['detail' => '   ']]];
    }

    #[DataProvider('missingColumnKeys')]
    public function test_missing_column_keys_fail_create_update_and_import(array $column): void
    {
        $payload = $this->payload(['name' => 'query', 'type' => 'text']);
        $created = $this->controller->store(Request::create('/', 'POST', $payload));
        $this->assertSame(201, $created->getStatusCode());
        $payload['columns'] = [$column];
        $payload['code'] = 'invalid-create';
        $response = $this->controller->store(Request::create('/', 'POST', $payload));
        $this->assertArrayHasKey('detail', $response->getData(true)['error']);
        $payload['code'] = 'filter-test';
        $response = $this->controller->update(Request::create('/', 'PUT', $payload), 'filter-test');
        $this->assertArrayHasKey('detail', $response->getData(true)['error']);
        $payload['code'] = 'invalid-import';
        $result = $this->controller->import(Request::create('/', 'POST', ['rows' => [$payload]]))->getData(true);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, DB::connection('central')->table('data_table_builders')->count());
        $shown = $this->controller->show(Request::create('/'), 'filter-test')->getData(true)['data'];
        $this->assertSame([['header' => 'ID', 'detail' => 'id']], $shown['columns']);
    }

    public function test_optional_action_labels_do_not_relax_other_required_action_fields(): void
    {
        foreach ([['type' => 'link'], ['type' => 'emit'], ['url' => '/items'], ['type' => 'unknown']] as $action) {
            $payload = $this->payload(['name' => 'query', 'type' => 'text']);
            $payload['actions'] = [$action];
            $this->assertNotNull($this->controller->validateDetail(Request::create('/', 'POST', $payload)));
        }
    }

    private function tabsPayload(string $code, array $tabs, ?string $defaultTab = null): array
    {
        return [
            'type' => 'tabs',
            'code' => $code,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'default_tab' => $defaultTab ?? ($tabs[0]['key'] ?? ''),
            'tabs' => $tabs,
        ];
    }

    public function test_tabs_share_table_storage_and_get_resolves_nested_references_without_copying_configs(): void
    {
        $table = $this->payload(['name' => 'status', 'type' => 'text'], 'orders');
        $table['params'] = ['source_type' => 'data_source', 'data_source_code' => 'orders_source'];
        $this->assertSame(201, $this->controller->store(Request::create('/', 'POST', $table))->getStatusCode());

        $child = $this->tabsPayload('order-tabs', [
            ['key' => 'pending', 'label' => 'Pending', 'type' => 'table', 'table_builder_code' => 'orders', 'default_params' => ['status' => 'pending'], 'count' => ['source_type' => 'data_source', 'path' => 'meta.total']],
            ['key' => 'done', 'label' => 'Done', 'type' => 'table', 'table_builder_code' => 'orders', 'default_params' => ['status' => 'done'], 'count' => ['source_type' => 'data_source', 'path' => 'meta.total']],
        ], 'pending');
        $createdChild = $this->controller->store(Request::create('/', 'POST', $child));
        $this->assertSame(201, $createdChild->getStatusCode());

        $parent = $this->tabsPayload('transaction-tabs', [
            ['key' => 'sales', 'label' => 'Sales', 'type' => 'tabs', 'table_builder_code' => 'order-tabs'],
        ], 'sales');
        $createdParent = $this->controller->store(Request::create('/', 'POST', $parent));
        $this->assertSame(201, $createdParent->getStatusCode());

        $stored = DB::connection('central')->table('data_table_builders')->where('code', 'transaction-tabs')->first();
        $storedTabs = json_decode($stored->tabs, true);
        $this->assertSame([['key' => 'sales', 'label' => 'Sales', 'type' => 'tabs', 'table_builder_code' => 'order-tabs']], $storedTabs);

        $resolved = $this->controller->show(Request::create('/table-builder/transaction-tabs'), 'transaction-tabs')->getData(true)['data'];
        $this->assertSame('tabs', $resolved['type']);
        $this->assertSame('pending', $resolved['tabs'][0]['tabs'][0]['key']);
        $this->assertSame('orders_source', $resolved['tabs'][0]['tabs'][0]['count']['data_source_code']);
        $this->assertSame(['status' => 'pending'], $resolved['tabs'][0]['tabs'][0]['count']['default_params']);
        $this->assertSame(['status' => 'done'], $resolved['tabs'][0]['tabs'][1]['default_params']);
        $this->assertSame('table', $this->controller->show(Request::create('/table-builder/orders'), 'orders')->getData(true)['data']['type']);
        $listed = $this->controller->index(Request::create('/'))->getData(true)['data'];
        $listedParent = array_values(array_filter($listed, fn (array $record): bool => $record['code'] === 'transaction-tabs'))[0];
        $this->assertSame('pending', $listedParent['tabs'][0]['tabs'][0]['key']);
        $this->assertSame(3, DB::connection('central')->table('data_table_builders')->count());
    }

    public function test_tabs_reject_self_and_indirect_cycles(): void
    {
        $table = $this->payload(['name' => 'status', 'type' => 'text'], 'cycle-table');
        $table['params'] = ['source_type' => 'data_source', 'data_source_code' => 'orders_source'];
        $this->controller->store(Request::create('/', 'POST', $table));

        $self = $this->tabsPayload('self-tabs', [
            ['key' => 'self', 'label' => 'Self', 'type' => 'tabs', 'table_builder_code' => 'self-tabs'],
        ]);
        $response = $this->controller->store(Request::create('/', 'POST', $self));
        $this->assertSame(422, $response->getStatusCode());

        $first = $this->tabsPayload('first-tabs', [
            ['key' => 'table', 'label' => 'Table', 'type' => 'table', 'table_builder_code' => 'cycle-table'],
        ]);
        $second = $this->tabsPayload('second-tabs', [
            ['key' => 'first', 'label' => 'First', 'type' => 'tabs', 'table_builder_code' => 'first-tabs'],
        ]);
        $this->assertSame(201, $this->controller->store(Request::create('/', 'POST', $first))->getStatusCode());
        $this->assertSame(201, $this->controller->store(Request::create('/', 'POST', $second))->getStatusCode());

        $cycle = $this->tabsPayload('first-tabs', [
            ['key' => 'second', 'label' => 'Second', 'type' => 'tabs', 'table_builder_code' => 'second-tabs'],
        ]);
        $response = $this->controller->update(Request::create('/', 'PUT', $cycle), 'first-tabs');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('cycle', strtolower($response->getData(true)['message']));
    }

    public function test_legacy_records_without_type_are_returned_as_tables(): void
    {
        DB::connection('central')->table('data_table_builders')->insert([
            'code' => 'legacy-table', 'name' => 'Legacy', 'columns' => json_encode([]),
            'filters' => json_encode([]), 'actions' => json_encode([]),
            'params' => json_encode(['source_type' => 'custom_api', 'api_config' => ['type' => 'internal', 'method' => 'GET', 'url' => '/api/legacy']]),
            'type' => null, 'default_tab' => null, 'tabs' => null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $record = $this->controller->show(Request::create('/table-builder/legacy-table'), 'legacy-table')->getData(true)['data'];
        $this->assertSame('table', $record['type']);
        $this->assertArrayHasKey('columns', $record);
        $this->assertArrayNotHasKey('tabs', $record);
    }

    public function test_import_accepts_tabs_rows_without_regular_table_fields(): void
    {
        $table = $this->payload(['name' => 'status', 'type' => 'text'], 'imported-orders');
        $table['params'] = ['source_type' => 'custom_api', 'api_config' => ['type' => 'internal', 'method' => 'GET', 'url' => '/api/orders']];
        $tabs = $this->tabsPayload('imported-order-tabs', [
            ['key' => 'pending', 'label' => 'Pending', 'type' => 'table', 'table_builder_code' => 'imported-orders', 'default_params' => ['status' => 'pending']],
        ]);
        $result = $this->controller->import(Request::create('/', 'POST', ['rows' => [$tabs, $table]]))->getData(true);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['failed']);
        $shown = $this->controller->show(Request::create('/'), 'imported-order-tabs')->getData(true)['data'];
        $this->assertSame('custom_api', $shown['tabs'][0]['source_type']);
        $this->assertSame(['status' => 'pending'], $shown['tabs'][0]['default_params']);
    }

    public function test_delete_blocks_referenced_records_and_deletes_unused_tab_builders(): void
    {
        $table = $this->payload(['name' => 'status', 'type' => 'text'], 'delete-table');
        $table['params'] = ['source_type' => 'data_source', 'data_source_code' => 'orders_source'];
        $this->controller->store(Request::create('/', 'POST', $table));
        $tabs = $this->tabsPayload('delete-tabs', [
            ['key' => 'orders', 'label' => 'Orders', 'type' => 'table', 'table_builder_code' => 'delete-table'],
        ]);
        $this->controller->store(Request::create('/', 'POST', $tabs));

        $blocked = $this->controller->destroy(Request::create('/', 'DELETE'), 'delete-table');
        $this->assertSame(409, $blocked->getStatusCode());
        $deleted = $this->controller->destroy(Request::create('/', 'DELETE'), 'delete-tabs');
        $this->assertSame(200, $deleted->getStatusCode());
        $deletedTable = $this->controller->destroy(Request::create('/', 'DELETE'), 'delete-table');
        $this->assertSame(200, $deletedTable->getStatusCode());
    }

    public function test_automatic_count_uses_root_total_and_synchronizes_on_create_update_and_get(): void
    {
        $table = $this->payload(['name' => 'status', 'type' => 'text'], 'paged-orders');
        $table['params'] = ['source_type' => 'data_source', 'data_source_code' => 'orders_source', 'pagination' => true];
        $this->controller->store(Request::create('/', 'POST', $table));
        $tabs = $this->tabsPayload('automatic-tabs', [[
            'key' => 'pending', 'label' => 'Pending', 'type' => 'table', 'table_builder_code' => 'paged-orders',
            'default_params' => ['status' => 'pending'], 'count' => ['mode' => 'automatic', 'source_type' => 'data_source',
                'data_source_code' => 'stale_source', 'default_params' => ['status' => 'stale'], 'path' => 'meta.total'],
        ]]);
        $created = $this->controller->store(Request::create('/', 'POST', $tabs));
        $this->assertSame(201, $created->getStatusCode());
        $count = $created->getData(true)['data']['tabs'][0]['count'];
        $this->assertSame('automatic', $count['mode']);
        $this->assertSame('total', $count['path']);
        $this->assertSame('orders_source', $count['data_source_code']);
        $this->assertSame(['status' => 'pending'], $count['default_params']);

        $tabs['tabs'][0]['default_params'] = ['status' => 'done'];
        $updated = $this->controller->update(Request::create('/', 'PUT', $tabs), 'automatic-tabs');
        $this->assertSame(201, $updated->getStatusCode());
        $this->assertSame(['status' => 'done'], $updated->getData(true)['data']['tabs'][0]['count']['default_params']);
        $table['params']['data_source_code'] = 'changed_source';
        $this->controller->update(Request::create('/', 'PUT', $table), 'paged-orders');
        $show = fn () => $this->controller->show(Request::create('/'), 'automatic-tabs')->getData(true)['data']['tabs'][0]['count'];
        $this->assertSame('changed_source', $show()['data_source_code']);
        $this->assertSame('total', $show()['path']);
        $table['params']['pagination'] = false;
        $this->controller->update(Request::create('/', 'PUT', $table), 'paged-orders');
        $this->assertSame('manual', $show()['mode']);
        $invalid = $this->controller->validateDetail(Request::create('/', 'POST', $tabs));
        $this->assertSame(422, $invalid->getStatusCode());
    }

    public function test_manual_count_sources_and_parameters_round_trip_independently_through_crud_export_and_import(): void
    {
        foreach (['data_source', 'custom_api'] as $tableSource) {
            $table = $this->payload(['name' => 'status', 'type' => 'text'], 'table-' . $tableSource);
            if ($tableSource === 'data_source') $table['params'] = ['source_type' => 'data_source', 'data_source_code' => 'orders_source', 'pagination' => true];
            $this->controller->store(Request::create('/', 'POST', $table));
            $counts = [
                ['mode' => 'manual', 'source_type' => 'data_source', 'data_source_code' => 'summary_source', 'default_params' => ['year' => '002026', 'flag' => false], 'path' => 'data.0.aggregate'],
            ];
            foreach (['internal', 'external'] as $apiType) {
                $counts[] = ['mode' => 'manual', 'source_type' => 'custom_api', 'api_config' => [
                    'type' => $apiType, 'method' => 'POST', 'url' => $apiType === 'internal' ? '/summary' : 'https://example.com/summary',
                    'headers' => [['key' => 'X-Example', 'value' => 'example']], 'body_params' => [['key' => 'include', 'value' => 'all']],
                ], 'default_params' => ['arbitrary_name' => 'manual'], 'path' => 'data.aggregate'];
            }
            foreach ($counts as $index => $count) {
                $code = 'manual-' . $tableSource . '-' . $index;
                $tabs = $this->tabsPayload($code, [[
                    'key' => 'orders', 'label' => 'Orders', 'type' => 'table', 'table_builder_code' => $table['code'],
                    'default_params' => ['status' => 'tab-value'], 'count' => $count,
                ]]);
                $created = $this->controller->store(Request::create('/', 'POST', $tabs));
                $this->assertSame(201, $created->getStatusCode(), json_encode($created->getData(true)));
                $tabs['tabs'][0]['default_params'] = ['status' => 'changed-tab-value'];
                $updated = $this->controller->update(Request::create('/', 'PUT', $tabs), $code);
                $this->assertSame(201, $updated->getStatusCode());
                $shown = $this->controller->show(Request::create('/'), $code)->getData(true)['data'];
                $this->assertEquals($count, $shown['tabs'][0]['count']);
                $this->assertSame(['status' => 'changed-tab-value'], $shown['tabs'][0]['default_params']);
                ob_start();
                try {
                    $this->controller->export(Request::create('/', 'POST', ['ids' => [$shown['id']]]))->sendContent();
                    $exported = json_decode(ob_get_contents(), true);
                } finally {
                    ob_end_clean();
                }
                $this->assertEquals($count, $exported[0]['tabs'][0]['count']);
                $exported[0]['code'] = $code . '-imported';
                $import = $this->controller->import(Request::create('/', 'POST', ['rows' => json_encode($exported)]))->getData(true);
                $this->assertSame(1, $import['imported'], json_encode($import));
                $this->assertSame(0, $import['failed']);
                $imported = $this->controller->show(Request::create('/'), $exported[0]['code'])->getData(true)['data'];
                $this->assertEquals($count, $imported['tabs'][0]['count']);
            }
        }
    }

    public function test_manual_counts_require_a_source_path_and_scalar_parameters(): void
    {
        $table = $this->payload(['name' => 'status', 'type' => 'text'], 'orders');
        $this->controller->store(Request::create('/', 'POST', $table));
        $base = ['mode' => 'manual', 'source_type' => 'data_source', 'data_source_code' => 'summary_source', 'path' => 'total'];
        foreach ([['data_source_code' => ''], ['path' => ''], ['default_params' => ['nested' => ['bad']]], ['mode' => 'unknown'], ['source_type' => 'unknown']] as $bad) {
            $tabs = $this->tabsPayload('invalid-count', [[
                'key' => 'orders', 'label' => 'Orders', 'type' => 'table', 'table_builder_code' => 'orders', 'count' => array_replace($base, $bad),
            ]]);
            $invalid = $this->controller->validateDetail(Request::create('/', 'POST', $tabs));
            $this->assertSame(422, $invalid->getStatusCode());
        }
    }
}
