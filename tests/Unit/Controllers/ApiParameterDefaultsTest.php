<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;
use ESolution\DataSources\Controllers\ApiController;
use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class ApiParameterDefaultsTest extends TestCase
{
    private Container $app;
    private mixed $previousApp;
    private mixed $previousFacadeApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Container::getInstance();
        $this->previousFacadeApp = Facade::getFacadeApplication();
        $this->app = new Container();
        $this->app->instance('config', new Repository());
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $this->app));
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApp);
        Container::setInstance($this->previousApp);
        parent::tearDown();
    }

    public function test_defaults_apply_only_to_missing_or_null_optional_values(): void
    {
        $controller = $this->runtimeController();
        foreach (['manual' => 7, 'runtime_variable' => '{{ auth.id }}'] as $mode => $default) {
            foreach ([[], ['value' => null], ['value' => 0], ['value' => false], ['value' => ''], ['value' => 12]] as $payload) {
                $request = Request::create('/', 'POST', $payload);
                $params = [['name' => 'value', 'type' => 'integer', 'required' => false, 'default' => $default, 'default_mode' => $mode]];
                $this->assertNull($controller->prepare($request, $params));
                $expected = !array_key_exists('value', $payload) || $payload['value'] === null
                    ? ($mode === 'manual' ? 7 : 5) : $payload['value'];
                $this->assertSame($expected, $request->input('value'));
            }
        }

        foreach ([0, false, '', []] as $default) {
            $request = Request::create('/', 'POST');
            $this->assertNull($controller->prepare($request, [['name' => 'value', 'type' => 'string', 'default' => $default]]));
            $this->assertSame($default, $request->input('value'));
        }
    }

    public function test_absent_defaults_and_required_parameters_do_not_receive_fallbacks(): void
    {
        $controller = $this->runtimeController();
        $params = [
            ['name' => 'untouched', 'type' => 'string'],
            ['name' => 'disabled', 'type' => 'string', 'default' => null],
            ['name' => 'required', 'type' => 'integer', 'required' => true, 'default' => 10],
        ];
        foreach ([[], ['untouched' => null, 'disabled' => null, 'required' => null]] as $payload) {
            $request = Request::create('/', 'POST', $payload);
            $this->assertNull($controller->prepare($request, $params));
            $this->assertSame($payload, $request->all());
            $this->assertTrue($this->app['validator']->make($request->all(), $controller->rules($params))->fails());
        }
    }

    public function test_defaults_preserve_nested_structure_without_creating_missing_parents(): void
    {
        $controller = $this->runtimeController();
        $children = [
            ['name' => 'count', 'type' => 'integer', 'default' => 2],
            ['name' => 'active', 'type' => 'boolean', 'default' => true],
            ['name' => 'label', 'type' => 'string', 'default' => 'fallback'],
            ['name' => 'profile', 'type' => 'object', 'params' => [['name' => 'owner', 'type' => 'integer', 'default' => '{{ auth.id }}']]],
        ];
        $params = [
            ['name' => 'items', 'type' => 'array object', 'params' => $children],
            ['name' => 'missing', 'type' => 'object', 'params' => $children],
            ['name' => 'null_parent', 'type' => 'object', 'params' => $children],
            ['name' => 'empty', 'type' => 'array', 'params' => $children],
        ];
        $request = Request::create('/', 'POST', [
            'items' => [
                ['count' => 0, 'active' => false, 'label' => '', 'profile' => []],
                ['count' => null, 'profile' => ['owner' => 9]],
            ],
            'null_parent' => null, 'empty' => [],
        ]);
        $this->assertNull($controller->prepare($request, $params));
        $this->assertSame([
            ['count' => 0, 'active' => false, 'label' => '', 'profile' => ['owner' => 5]],
            ['count' => 2, 'profile' => ['owner' => 9], 'active' => true, 'label' => 'fallback'],
        ], $request->input('items'));
        $this->assertArrayNotHasKey('missing', $request->all());
        $this->assertNull($request->input('null_parent'));
        $this->assertSame([], $request->input('empty'));
    }

    public function test_container_and_primitive_array_defaults_are_applied_before_child_defaults(): void
    {
        $controller = $this->runtimeController();
        foreach (['array', 'array object', 'array-object'] as $type) {
            $params = [
                ['name' => 'profile', 'type' => 'object', 'default' => [], 'params' => [
                    ['name' => 'owner', 'type' => 'integer', 'default' => '{{ auth.id }}'],
                ]],
                ['name' => 'items', 'type' => $type, 'default' => [['qty' => null], ['qty' => 0]], 'params' => [
                    ['name' => 'qty', 'type' => 'integer', 'default' => 2],
                ]],
                ['name' => 'ids', 'type' => 'array integer', 'default' => [0, '{{ auth.id }}']],
                ['name' => 'tags', 'type' => 'array string', 'default' => ['a', 'b']],
            ];
            $request = Request::create('/', 'POST', ['profile' => null, 'items' => null]);
            $this->assertNull($controller->prepare($request, $params));
            $this->assertSame(['owner' => 5], $request->input('profile'));
            $this->assertSame([['qty' => 2], ['qty' => 0]], $request->input('items'));
            $this->assertSame([0, 5], $request->input('ids'));
            $this->assertSame(['a', 'b'], $request->input('tags'));
        }
    }

    public function test_runtime_defaults_use_the_current_request_and_are_resolved_only_when_needed(): void
    {
        $registry = $this->createMock(RuntimeVariableRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->expects($this->exactly(3))->method('resolve')->willReturnCallback(
            fn (string $key) => $key === 'request.tags' ? $this->app['request']->input('source_tags') : $this->app['request']->header('X-Owner')
        );
        $controller = $this->runtimeController(new DynamicVariableParser($registry));
        $params = [['name' => 'owner', 'type' => 'string', 'default' => '{{ request.owner }}', 'default_mode' => 'runtime_variable']];
        foreach (['first', 'second'] as $owner) {
            $request = Request::create('/', 'POST', [], [], [], ['HTTP_X_OWNER' => $owner]);
            $this->app->instance('request', $request);
            $this->assertNull($controller->prepare($request, $params));
            $this->assertSame($owner, $request->input('owner'));
        }
        foreach ([0, false, ''] as $value) {
            $request = Request::create('/', 'POST', ['owner' => $value]);
            $this->assertNull($controller->prepare($request, $params));
            $this->assertSame($value, $request->input('owner'));
        }
        $request = Request::create('/', 'POST', ['source_tags' => ['x', 'y']]);
        $this->app->instance('request', $request);
        $this->assertNull($controller->prepare($request, [['name' => 'tags', 'type' => 'array string', 'default' => '{{ request.tags }}']]));
        $this->assertSame(['x', 'y'], $request->input('tags'));
        $this->assertSame('{{ request.owner }}', $params[0]['default']);
    }

    public function test_invalid_and_null_runtime_results_use_existing_validation_behavior(): void
    {
        $registry = $this->createMock(RuntimeVariableRegistryInterface::class);
        $registry->method('has')->willReturnCallback(fn ($key) => $key !== 'unknown.key');
        $registry->method('resolve')->willReturnCallback(fn ($key) => $key === 'request.null' ? null : 'invalid integer');
        $controller = $this->runtimeController(new DynamicVariableParser($registry));
        foreach (['invalid', 'null'] as $key) {
            $params = [['name' => 'value', 'type' => 'integer', 'default' => '{{ request.' . $key . ' }}']];
            $request = Request::create('/', 'POST');
            $this->assertNull($controller->prepare($request, $params));
            $this->assertSame($key === 'invalid', $this->app['validator']->make($request->all(), $controller->rules($params))->fails());
        }
        $response = $controller->prepare(Request::create('/', 'POST'), [['name' => 'value', 'type' => 'integer', 'default' => '{{ unknown.key }}']]);
        $this->assertSame(422, $response->getStatusCode());
        $params = [['name' => 'qty', 'type' => 'integer', 'default' => 0, 'validation_rules' => 'min:1']];
        $request = Request::create('/', 'POST');
        $controller->prepare($request, $params);
        $this->assertTrue($this->app['validator']->make($request->all(), $controller->rules($params))->fails());
    }

    public function test_configuration_validation_checks_membership_without_resolving_variables(): void
    {
        $registry = $this->createMock(RuntimeVariableRegistryInterface::class);
        $registry->method('has')->willReturnCallback(fn ($key) => $key === 'auth.id');
        $registry->expects($this->never())->method('resolve');
        $controller = $this->configController(new DynamicVariableParser($registry));
        $payload = ['params' => [['name' => 'profile', 'type' => 'object', 'params' => [
            ['name' => 'owner', 'type' => 'integer', 'default' => '{{ auth.id }}', 'default_mode' => 'runtime_variable'],
            ['name' => 'reference', 'type' => 'string', 'default' => 'INV-{{ auth.id }}', 'default_mode' => 'manual'],
        ]]], 'parent_table' => ['data_params' => ['owner' => '{{ auth.id }}']]];
        $this->assertNull($controller->validateVariables($payload));
        $this->assertNull($controller->validateNode($payload['params'][0]));
        $response = $controller->validateVariables(['params' => [['default' => '{{ unknown.key }}']]]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('params.0.default', $response->getData(true)['field']);
        foreach (['invalid_mode', 'runtime_variable'] as $mode) {
            $response = $controller->validateNode(['name' => 'owner', 'type' => 'integer', 'default' => 7, 'default_mode' => $mode]);
            $this->assertSame(400, $response->getStatusCode());
            $response = $controller->validateDefaults([['params' => [
                ['name' => 'owner', 'type' => 'integer', 'default' => 7, 'default_mode' => $mode],
            ]]]);
            $this->assertSame(400, $response->getStatusCode());
        }
    }

    public function test_storage_reload_and_export_import_preserve_recursive_defaults_and_modes(): void
    {
        $controller = $this->configController();
        $params = [['name' => 'items', 'type' => 'array object', 'default' => [['active' => null]], 'default_mode' => 'manual', 'params' => [
            ['name' => 'owner', 'type' => 'integer', 'default' => '{{ auth.id }}', 'default_mode' => 'runtime_variable'],
            ['name' => 'active', 'type' => 'boolean', 'default' => false, 'default_mode' => 'manual'],
            ['name' => 'label', 'type' => 'string', 'default' => '', 'default_mode' => 'manual'],
            ['name' => 'qty', 'type' => 'integer', 'default' => 0, 'default_mode' => 'manual'],
        ]]];
        $stored = new ApiConfig(['route_name' => 'items.store', 'endpoint' => 'items', 'method' => 'POST', 'params' => $params]);
        $reloaded = new ApiConfig();
        $reloaded->setRawAttributes($stored->getAttributes());
        foreach (['hook', 'beforeExecuteHook', 'parentTable', 'permission'] as $relation) $reloaded->setRelation($relation, null);
        $reloaded->setRelation('childTables', new Collection());
        $export = json_decode(json_encode($controller->exportConfig($reloaded)), true);
        $import = $controller->normalizeImport($export);
        $this->assertSame($params, $import['params']);
        $this->assertNull($controller->validateDefaults($import['params']));
        $this->assertNull($controller->validateVariables($import));
        $import['params'] = json_encode($import['params']);
        $this->assertSame($params, $controller->normalizeImport($import)['params']);
    }

    private function runtimeController(?DynamicVariableParser $parser = null): ParameterDefaultsRuntimeController
    {
        return new ParameterDefaultsRuntimeController(
            $this->createMock(DynamicApiConfigResolver::class), $this->createMock(DataQueryService::class),
            $this->createMock(Pipeline::class), $parser ?? new DynamicVariableParser(new FakeRuntimeVariableRegistry()),
            $this->createMock(MiddlewareConnectionResolver::class), $this->createMock(ExecutionConnectionResolver::class),
            $this->createMock(AfterHitApiDispatcher::class)
        );
    }

    private function configController(?DynamicVariableParser $parser = null): ParameterDefaultsConfigController
    {
        return new ParameterDefaultsConfigController(
            $this->createMock(DynamicApiConfigResolver::class), $parser ?? new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );
    }
}

class ParameterDefaultsRuntimeController extends ApiController
{
    public function prepare(Request $request, array $params): ?JsonResponse { return $this->prepareRuntimeRequest($request, $params); }
    public function rules(array $params): array { return $this->buildValidationRulesFromParams($params); }
}

class ParameterDefaultsConfigController extends DataAPIBuilderController
{
    public function validateVariables(mixed $payload): ?JsonResponse { return $this->validateRuntimeVariables($payload); }
    public function validateNode(array $param): ?JsonResponse { return $this->validateApiBuilderParamNode($param); }
    public function validateDefaults(array $params): ?JsonResponse { return $this->validateApiBuilderParamDefaults($params); }
    public function exportConfig(ApiConfig $config): array { return $this->serializeApiConfig($config); }
    public function normalizeImport(array $payload): array { return $this->normalizeLegacyApiConfigPayload($payload); }
}
