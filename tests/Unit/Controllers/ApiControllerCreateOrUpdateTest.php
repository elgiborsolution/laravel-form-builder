<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\ApiController;
use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Models\ApiTable;
use ESolution\DataSources\Runtime\RuntimeVariableDefinition;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use ESolution\DataSources\Services\DataQueryService;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\MiddlewareConnectionResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class ApiControllerCreateOrUpdateTest extends TestCase
{
    private Capsule $capsule;
    private TestableCreateOrUpdateApiController $controller;
    private array $validationTargets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'sqlite');
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        $this->capsule->getConnection('sqlite')->getSchemaBuilder()->create('parents', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('tenant_code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('active')->nullable();
        });
        $this->capsule->getConnection('sqlite')->getSchemaBuilder()->create('children', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
            $table->string('sku');
        });
        $this->capsule->getConnection('sqlite')->getSchemaBuilder()->create('es_agama', function ($table): void {
            $table->increments('id');
            $table->string('code');
            $table->string('name');
        });
        $this->capsule->getConnection('sqlite')->getSchemaBuilder()->create('es_sub_agama', function ($table): void {
            $table->string('id')->primary();
            $table->unsignedInteger('agama_id');
            $table->string('code');
            $table->string('name');
            $table->foreign('agama_id')->references('id')->on('es_agama');
        });

        $registry = new class extends FakeRuntimeVariableRegistry {
            public function __construct()
            {
                parent::__construct();
                $sequence = 0;
                $this->definitions['uuid.random'] = RuntimeVariableDefinition::make(
                    'uuid.random',
                    'string',
                    static function () use (&$sequence): string {
                        return sprintf('00000000-0000-4000-8000-%012d', ++$sequence);
                    }
                );
            }
        };

        $connectionResolver = $this->createMock(ExecutionConnectionResolver::class);
        $connectionResolver->method('connection')->willReturn($this->capsule->getConnection('sqlite'));
        $connectionResolver->method('resolve')->willReturn('sqlite');
        $this->controller = new TestableCreateOrUpdateApiController(
            $this->createMock(DynamicApiConfigResolver::class),
            $this->createMock(DataQueryService::class),
            new Pipeline(),
            new DynamicVariableParser($registry),
            $this->createMock(MiddlewareConnectionResolver::class),
            $connectionResolver,
            $this->createMock(AfterHitApiDispatcher::class)
        );
        $this->controller->validationTargets = [];
    }

    public function test_it_updates_a_parent_matching_all_composite_keys_and_links_new_children(): void
    {
        $parentId = $this->capsule->getConnection('sqlite')->table('parents')->insertGetId([
            'email' => 'one@example.test', 'tenant_code' => 'north', 'name' => 'Old name', 'active' => 1,
        ]);
        $this->capsule->getConnection('sqlite')->table('children')->insert(['parent_id' => $parentId, 'sku' => 'existing']);

        $request = $this->request([
            'email' => 'one@example.test', 'tenant_code' => 'north', 'name' => 'Updated name', 'sku' => 'new',
        ]);
        $response = $this->controller->store($request, $this->config(uniqueEmail: true));

        $this->assertSame(201, $response->getStatusCode(), $response->getData(true)['error'] ?? '');
        $this->assertSame('Data has been successfully updated', $response->getData(true)['message']);
        $this->assertSame('Updated name', $this->capsule->getConnection('sqlite')->table('parents')->where('id', $parentId)->value('name'));
        $this->assertSame(2, $this->capsule->getConnection('sqlite')->table('children')->where('parent_id', $parentId)->count());
        $this->assertSame('update', $request->attributes->get('datasources.after_hit.action'));
        $this->assertSame([(int) $parentId, (int) $parentId], $this->controller->validationTargets);
        $this->assertStringContainsString('unique:parents,email,' . $parentId . ',id', $this->controller->validationRulesHistory[0]['email']);
        $this->assertStringContainsString('unique:parents,email,' . $parentId . ',id', $this->controller->validationRulesHistory[1]['email']);
    }

    public function test_it_creates_a_parent_when_no_composite_match_exists(): void
    {
        $this->capsule->getConnection('sqlite')->table('parents')->insert([
            'email' => 'one@example.test', 'tenant_code' => 'north', 'name' => 'Existing',
        ]);

        $request = $this->request([
            'email' => 'one@example.test', 'tenant_code' => 'south', 'name' => 'New parent', 'sku' => 'new',
        ]);
        $response = $this->controller->store($request, $this->config());

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Data has been successfully created', $response->getData(true)['message']);
        $newParent = $this->capsule->getConnection('sqlite')->table('parents')->where('tenant_code', 'south')->first();
        $this->assertNotNull($newParent);
        $this->assertSame((int) $newParent->id, (int) $this->capsule->getConnection('sqlite')->table('children')->value('parent_id'));
        $this->assertSame('create', $request->attributes->get('datasources.after_hit.action'));
        $this->assertSame([null, null], $this->controller->validationTargets);
        $this->assertStringContainsString('required|string', $this->controller->validationRulesHistory[0]['email']);
    }

    public function test_it_resolves_nested_lookup_paths_at_runtime(): void
    {
        $params = [
            ['name' => 'profile', 'type' => 'object', 'params' => [
                ['name' => 'email', 'type' => 'string'],
            ]],
            ['name' => 'tenant_code', 'type' => 'string'],
        ];
        $config = $this->config($params, [
            ['column' => 'email', 'parameter' => 'profile.email'],
            ['column' => 'tenant_code', 'parameter' => 'tenant_code'],
        ], []);

        $response = $this->controller->store($this->request([
            'profile' => ['email' => 'nested@example.test'], 'tenant_code' => 'north',
        ]), $config);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('nested@example.test', $this->capsule->getConnection('sqlite')->table('parents')->value('email'));
    }

    public function test_it_resolves_the_lookup_again_after_before_execute_hooks(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $originalId = $db->table('parents')->insertGetId([
            'email' => 'before@example.test', 'tenant_code' => 'north', 'name' => 'Original',
        ]);
        $this->controller->beforeExecute = static function (Request $request): void {
            $request->merge(['email' => 'after@example.test']);
        };
        $request = $this->request([
            'email' => 'before@example.test', 'tenant_code' => 'north', 'name' => 'Created after hook', 'sku' => 'new',
        ]);

        $response = $this->controller->store($request, $this->config());

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Original', $db->table('parents')->where('id', $originalId)->value('name'));
        $newParent = $db->table('parents')->where('email', 'after@example.test')->first();
        $this->assertNotNull($newParent);
        $this->assertSame((int) $newParent->id, (int) $db->table('children')->value('parent_id'));
        $this->assertSame([(int) $originalId, null], $this->controller->validationTargets);
        $this->assertSame('create', $request->attributes->get('datasources.after_hit.action'));
    }

    public function test_it_rejects_missing_null_empty_and_non_scalar_lookup_values_without_writes(): void
    {
        foreach ([
            ['tenant_code' => 'north'],
            ['email' => null, 'tenant_code' => 'north'],
            ['email' => '', 'tenant_code' => 'north'],
            ['email' => ['nested'], 'tenant_code' => 'north'],
        ] as $payload) {
            $response = $this->controller->store($this->request($payload), $this->config());
            $this->assertSame(422, $response->getStatusCode());
        }

        $this->assertSame(0, $this->capsule->getConnection('sqlite')->table('parents')->count());
        $this->assertSame(0, $this->capsule->getConnection('sqlite')->table('children')->count());
    }

    public function test_it_accepts_zero_and_false_as_lookup_values(): void
    {
        $config = $this->config([
            ['name' => 'code', 'type' => 'string'],
            ['name' => 'active', 'type' => 'boolean'],
        ], [['column' => 'email', 'parameter' => 'code'], ['column' => 'active', 'parameter' => 'active']]);

        $response = $this->controller->store($this->request(['code' => '0', 'active' => false]), $config);

        $this->assertSame(201, $response->getStatusCode(), $response->getData(true)['error'] ?? '');
        $this->assertSame('0', $this->capsule->getConnection('sqlite')->table('parents')->value('email'));
        $this->assertSame(0, (int) $this->capsule->getConnection('sqlite')->table('parents')->value('active'));
    }

    public function test_it_rejects_duplicate_matches_and_array_expansion_without_writes(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $db->table('parents')->insert([
            ['email' => 'dup@example.test', 'tenant_code' => 'north', 'name' => 'First'],
            ['email' => 'dup@example.test', 'tenant_code' => 'north', 'name' => 'Second'],
        ]);
        $duplicate = $this->controller->store($this->request([
            'email' => 'dup@example.test', 'tenant_code' => 'north', 'name' => 'Changed', 'sku' => 'new',
        ]), $this->config());
        $this->assertSame(422, $duplicate->getStatusCode());
        $this->assertSame(0, $db->table('children')->count());

        $arrayConfig = $this->config([
            ['name' => 'email', 'type' => 'string', 'required' => true, 'unique' => true],
            ['name' => 'tenant_code', 'type' => 'string'],
            ['name' => 'names', 'type' => 'array string'],
        ], null, ['name' => ['value' => 'names', 'array_handling' => 'LOOP_INSERT']]);
        $arrayResponse = $this->controller->store($this->request([
            'email' => 'array@example.test', 'tenant_code' => 'north', 'names' => ['one', 'two'],
        ]), $arrayConfig);
        $this->assertSame(422, $arrayResponse->getStatusCode());
        $this->assertSame(2, $db->table('parents')->count());
    }

    public function test_it_synchronizes_children_by_code_preserving_ids_despite_uuid_defaults(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $config = $this->agamaConfig();
        $first = $this->request([
            'code' => 'A', 'name' => 'Parent',
            'subagama' => [['code' => 'X', 'name' => 'First'], ['code' => 'Y', 'name' => 'Second']],
        ]);
        $response = $this->controller->store($first, $config);
        $this->assertSame(201, $response->getStatusCode(), $response->getData(true)['error'] ?? '');
        $parentId = $db->table('es_agama')->value('id');
        $ids = $db->table('es_sub_agama')->orderBy('code')->pluck('id', 'code')->all();
        $this->assertCount(2, array_unique($ids));
        $this->assertSame($first->input('subagama.0.id'), $ids['X']);
        $this->assertSame($first->input('subagama.1.id'), $ids['Y']);

        $repeat = $this->request([
            'code' => 'A', 'name' => 'Parent updated',
            'subagama' => [['code' => 'X', 'name' => 'First updated'], ['code' => 'Y', 'name' => 'Second updated']],
        ]);
        $response = $this->controller->store($repeat, $config);
        $this->assertSame(201, $response->getStatusCode(), $response->getData(true)['error'] ?? '');
        $this->assertNotSame($ids['X'], $repeat->input('subagama.0.id'));
        $this->assertNotSame($ids['Y'], $repeat->input('subagama.1.id'));
        $this->assertSame(1, $db->table('es_agama')->count());
        $this->assertSame(2, $db->table('es_sub_agama')->count());
        $this->assertSame($ids, $db->table('es_sub_agama')->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame('First updated', $db->table('es_sub_agama')->where('id', $ids['X'])->value('name'));
        $this->assertSame('Second updated', $db->table('es_sub_agama')->where('id', $ids['Y'])->value('name'));

        $changed = $this->request([
            'code' => 'A', 'name' => 'Parent updated again',
            'subagama' => [
                ['id' => 'incoming-different-id', 'code' => 'X', 'name' => 'First changed again'],
                ['code' => 'Z', 'name' => 'New child'],
            ],
        ]);
        $response = $this->controller->store($changed, $config);
        $this->assertSame(201, $response->getStatusCode(), $response->getData(true)['error'] ?? '');
        $this->assertSame(2, $db->table('es_sub_agama')->count());
        $this->assertSame($ids['X'], $db->table('es_sub_agama')->where('code', 'X')->value('id'));
        $this->assertSame('First changed again', $db->table('es_sub_agama')->where('id', $ids['X'])->value('name'));
        $this->assertFalse($db->table('es_sub_agama')->where('code', 'Y')->exists());
        $this->assertSame($changed->input('subagama.1.id'), $db->table('es_sub_agama')->where('code', 'Z')->value('id'));
        $this->assertSame([(int) $parentId], $db->table('es_sub_agama')->distinct()->pluck('agama_id')->all());
        $this->assertSame('update', $changed->attributes->get('datasources.after_hit.action'));
        $this->assertSame('Parent updated again', $changed->attributes->get('datasources.after_hit.result')['name']);
    }

    public function test_child_matching_and_delete_missing_are_scoped_to_the_current_parent(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $config = $this->agamaConfig();
        foreach (['A', 'B'] as $parentCode) {
            $response = $this->controller->store($this->request([
                'code' => $parentCode, 'name' => $parentCode,
                'subagama' => [['code' => 'X', 'name' => $parentCode . ' X'], ['code' => 'Y', 'name' => $parentCode . ' Y']],
            ]), $config);
            $this->assertSame(201, $response->getStatusCode());
        }
        $parentA = $db->table('es_agama')->where('code', 'A')->value('id');
        $parentB = $db->table('es_agama')->where('code', 'B')->value('id');
        $beforeB = $db->table('es_sub_agama')->where('agama_id', $parentB)->orderBy('code')->get()->all();
        $idA = $db->table('es_sub_agama')->where('agama_id', $parentA)->where('code', 'X')->value('id');

        $response = $this->controller->store($this->request([
            'code' => 'A', 'name' => 'A changed',
            'subagama' => [['code' => 'X', 'name' => 'A X changed'], ['code' => 'Z', 'name' => 'A Z']],
        ]), $config);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($idA, $db->table('es_sub_agama')->where('agama_id', $parentA)->where('code', 'X')->value('id'));
        $this->assertSame(['X', 'Z'], $db->table('es_sub_agama')->where('agama_id', $parentA)->orderBy('code')->pluck('code')->all());
        $this->assertEquals($beforeB, $db->table('es_sub_agama')->where('agama_id', $parentB)->orderBy('code')->get()->all());

        $response = $this->controller->store($this->request(['code' => 'A', 'name' => 'A empty', 'subagama' => []]), $config);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(0, $db->table('es_sub_agama')->where('agama_id', $parentA)->count());
        $this->assertEquals($beforeB, $db->table('es_sub_agama')->where('agama_id', $parentB)->orderBy('code')->get()->all());
    }

    public function test_it_keeps_omitted_children_for_keep_existing(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $config = $this->agamaConfig('KEEP_EXISTING');
        $this->controller->store($this->request([
            'code' => 'A', 'name' => 'Parent',
            'subagama' => [['code' => 'X', 'name' => 'First'], ['code' => 'Y', 'name' => 'Keep me']],
        ]), $config);
        $beforeY = $db->table('es_sub_agama')->where('code', 'Y')->first();
        $response = $this->controller->store($this->request([
            'code' => 'A', 'name' => 'Parent', 'subagama' => [['code' => 'X', 'name' => 'Updated']],
        ]), $config);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, $db->table('es_sub_agama')->count());
        $this->assertSame('Updated', $db->table('es_sub_agama')->where('code', 'X')->value('name'));
        $this->assertEquals($beforeY, $db->table('es_sub_agama')->where('code', 'Y')->first());
    }

    public function test_it_does_not_fall_back_to_another_childs_id_and_rolls_back_all_writes(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $config = $this->agamaConfig();
        foreach (['A', 'B'] as $parentCode) {
            $this->controller->store($this->request([
                'code' => $parentCode, 'name' => $parentCode,
                'subagama' => [['code' => 'X', 'name' => $parentCode . ' X'], ['code' => 'Y', 'name' => $parentCode . ' Y']],
            ]), $config);
        }
        $before = $db->table('es_sub_agama')->orderBy('id')->get()->all();
        $parentB = $db->table('es_agama')->where('code', 'B')->value('id');
        $idB = $db->table('es_sub_agama')->where('agama_id', $parentB)->where('code', 'Y')->value('id');
        $response = $this->controller->store($this->request([
            'code' => 'A', 'name' => 'Should roll back',
            'subagama' => [
                ['code' => 'X', 'name' => 'Should roll back'],
                ['id' => $idB, 'code' => 'Z', 'name' => 'Insert must fail on duplicate ID'],
            ],
        ]), $config);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('A', $db->table('es_agama')->where('code', 'A')->value('name'));
        $this->assertEquals($before, $db->table('es_sub_agama')->orderBy('id')->get()->all());
    }

    public function test_plain_post_create_keeps_its_child_insert_behavior(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $config = $this->agamaConfig();
        $config->parentTable->data_params = ['code' => 'code', 'name' => 'name'];
        foreach ([1, 2] as $iteration) {
            $response = $this->controller->store($this->request([
                'code' => 'A', 'name' => 'Parent', 'subagama' => [['code' => 'X', 'name' => 'Child']],
            ]), $config);
            $this->assertSame(201, $response->getStatusCode(), $response->getData(true)['error'] ?? '');
        }
        $this->assertSame(2, $db->table('es_agama')->count());
        $this->assertSame(2, $db->table('es_sub_agama')->count());
        $this->assertCount(2, $db->table('es_sub_agama')->distinct()->pluck('agama_id')->all());
    }

    public function test_put_and_patch_keep_their_existing_child_synchronization(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        foreach (['PUT', 'PATCH'] as $method) {
            $config = $this->agamaConfig();
            $this->controller->store($this->request([
                'code' => $method, 'name' => 'Parent',
                'subagama' => [['code' => 'X', 'name' => 'First'], ['code' => 'Y', 'name' => 'Second']],
            ]), $config);
            $parentId = $db->table('es_agama')->where('code', $method)->value('id');
            $childId = $db->table('es_sub_agama')->where('agama_id', $parentId)->where('code', 'X')->value('id');
            $config->method = $method;
            $response = $this->controller->update(Request::create('/api/agama/' . $parentId, $method, [
                'code' => $method, 'name' => 'Updated parent',
                'subagama' => [['id' => $childId, 'code' => 'X', 'name' => 'Updated child']],
            ]), $config, $parentId);
            $this->assertSame(201, $response->getStatusCode(), $response->getData(true)['error'] ?? '');
            $this->assertSame(1, $db->table('es_sub_agama')->where('agama_id', $parentId)->count());
            $this->assertSame('Updated child', $db->table('es_sub_agama')->where('id', $childId)->value('name'));
            $this->assertSame('Updated parent', $db->table('es_agama')->where('id', $parentId)->value('name'));
        }
    }

    public function test_child_synchronization_uses_hook_modified_mapped_values(): void
    {
        $db = $this->capsule->getConnection('sqlite');
        $config = $this->agamaConfig();
        $this->controller->store($this->request([
            'code' => 'A', 'name' => 'Parent', 'subagama' => [['code' => 'X', 'name' => 'Before hook']],
        ]), $config);
        $childId = $db->table('es_sub_agama')->value('id');
        $this->controller->beforeExecute = static function (Request $request): void {
            $rows = $request->input('subagama');
            $rows[0]['code'] = 'X';
            $rows[0]['name'] = 'Changed by hook';
            $request->merge(['subagama' => $rows]);
        };
        $response = $this->controller->store($this->request([
            'code' => 'A', 'name' => 'Parent', 'subagama' => [['code' => 'Y', 'name' => 'Incoming']],
        ]), $config);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $db->table('es_sub_agama')->count());
        $this->assertSame('Changed by hook', $db->table('es_sub_agama')->where('id', $childId)->value('name'));
        $this->assertSame('X', $db->table('es_sub_agama')->where('id', $childId)->value('code'));
    }

    private function agamaConfig(string $strategy = 'DELETE_MISSING'): ApiConfig
    {
        $config = new ApiConfig(['method' => 'POST', 'params' => [
            ['name' => 'code', 'type' => 'string'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'subagama', 'type' => 'array object', 'params' => [
                ['name' => 'id', 'type' => 'string', 'default' => '{{ uuid.random }}', 'default_mode' => 'runtime_variable'],
                ['name' => 'code', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string'],
            ]],
        ]]);
        $config->setRelation('parentTable', new ApiTable([
            'parent_id' => 0, 'table_name' => 'es_agama', 'primary_key' => 'id',
            'data_params' => [
                'name' => 'name',
                '__parent_operation' => [
                    'version' => 1, 'operation' => 'CREATE_OR_UPDATE',
                    'lookup_keys' => [['column' => 'code', 'parameter' => 'code']],
                ],
            ],
        ]));
        $config->setRelation('childTables', collect([[
            'table_name' => 'es_sub_agama', 'foreign_key' => 'agama_id', 'primary_key' => 'id',
            'child_update_key' => 'code', 'missing_child_strategy' => $strategy,
            'data_params' => ['id' => 'subagama.id', 'code' => 'subagama.code', 'name' => 'subagama.name'],
        ]]));
        return $config;
    }

    private function request(array $payload): Request
    {
        return Request::create('/api/parents', 'POST', $payload);
    }

    private function config(
        ?array $params = null,
        ?array $lookupKeys = null,
        array $parentMappings = ['name' => 'name'],
        bool $uniqueEmail = false
    ): ApiConfig
    {
        $params ??= [
            ['name' => 'email', 'type' => 'string', 'required' => true, 'unique' => $uniqueEmail],
            ['name' => 'tenant_code', 'type' => 'string'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'sku', 'type' => 'string'],
        ];
        $lookupKeys ??= [
            ['column' => 'email', 'parameter' => 'email'],
            ['column' => 'tenant_code', 'parameter' => 'tenant_code'],
        ];

        $config = new ApiConfig(['method' => 'POST', 'params' => $params]);
        $config->setRelation('parentTable', new ApiTable([
            'parent_id' => 0,
            'table_name' => 'parents',
            'primary_key' => 'id',
            'data_params' => [
                ...$parentMappings,
                '__parent_operation' => ['version' => 1, 'operation' => 'CREATE_OR_UPDATE', 'lookup_keys' => $lookupKeys],
            ],
        ]));
        $config->setRelation('childTables', collect([[
            'table_name' => 'children',
            'foreign_key' => 'parent_id',
            'data_params' => ['sku' => 'sku'],
        ]]));
        return $config;
    }
}

class TestableCreateOrUpdateApiController extends ApiController
{
    public array $validationTargets = [];
    public array $validationRulesHistory = [];
    public $beforeExecute = null;

    protected function runBeforeExecuteHooks(Request $request, ApiConfig $apiConfig): void
    {
        if (is_callable($this->beforeExecute)) {
            ($this->beforeExecute)($request);
        }
    }

    protected function validateUpsertPayload(
        Request $request,
        array $params,
        ?string $connectionName,
        string $parentTable,
        mixed $matchedId,
        string $primaryKey
    ): void {
        $this->validationTargets[] = $matchedId;
        parent::validateUpsertPayload($request, $params, $connectionName, $parentTable, $matchedId, $primaryKey);
    }

    public function findValidateRule($rowParam, $tableParent = '', $primaryKey = 0, ?string $ignoreColumn = null): string
    {
        $rules = [! empty($rowParam['required']) ? 'required' : 'nullable'];
        $type = strtolower((string) ($rowParam['type'] ?? ''));
        if (in_array($type, ['string', 'integer', 'boolean', 'numeric', 'date', 'url'], true)) {
            $rules[] = $type;
        }
        if (! empty($rowParam['unique'])) {
            $unique = 'unique:' . $tableParent . ',' . $rowParam['name'];
            if ($primaryKey != 0) {
                $unique .= ',' . $primaryKey . ',' . ($ignoreColumn ?: 'id');
            }
            $rules[] = $unique;
        }
        if (is_string($rowParam['validation_rules'] ?? null) && trim($rowParam['validation_rules']) !== '') {
            $rules[] = trim($rowParam['validation_rules']);
        }
        return implode('|', $rules);
    }

    protected function validateWithDatasourceConnection(array $data, array $rules, ?string $connectionName = null): array
    {
        $this->validationRulesHistory[] = $rules;
        return $data;
    }
}
