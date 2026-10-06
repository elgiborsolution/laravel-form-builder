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
            foreach (['columns', 'filters', 'actions', 'params'] as $field) $table->text($field);
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
}
