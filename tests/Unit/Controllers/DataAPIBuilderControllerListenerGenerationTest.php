<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Models\ApiHook;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use PHPUnit\Framework\TestCase;

class DataAPIBuilderControllerListenerGenerationTest extends TestCase
{
    public function test_it_defaults_listener_generation_to_enabled_when_flag_is_missing(): void
    {
        $controller = $this->makeController();

        $this->assertTrue($controller->exposeNormalizeGenerateListener(null));
        $this->assertTrue($controller->exposeNormalizeGenerateListener(''));
        $this->assertTrue($controller->exposeNormalizeGenerateListener('true'));
    }

    public function test_it_can_disable_listener_generation_without_calling_artisan(): void
    {
        $controller = $this->makeController();

        $this->assertFalse($controller->exposeEnsureAfterHitListener('AfterRunFooListener', false));
    }

    public function test_it_prefers_listener_path_from_payload_and_falls_back_to_existing_hook(): void
    {
        $controller = $this->makeController();

        $apiConfig = new ApiConfig();
        $apiConfig->route_name = 'brands.store';
        $apiConfig->setRelation('hook', new ApiHook([
            'action_type' => 'after_hit_api',
            'listener_class' => 'App\\Listeners\\Api\\ExistingListener',
        ]));

        $this->assertSame(
            'Modules\\Sales\\Listeners\\SyncBrandListener',
            $controller->exposeResolveListenerClassFromPayload([
                'route_name' => 'brands.store',
                'listener_path' => 'Modules\\Sales\\Listeners\\SyncBrandListener',
            ], null, $apiConfig)
        );

        $this->assertSame(
            'App\\Listeners\\Api\\ExistingListener',
            $controller->exposeResolveListenerClassFromPayload([
                'route_name' => 'brands.store',
            ], null, $apiConfig)
        );

        $this->assertSame(
            'App\\Listeners\\AfterRunBrandsStoreListener',
            $controller->exposeResolveListenerClassFromPayload([
                'route_name' => 'brands.store',
            ])
        );
    }

    private function makeController(): TestableDataAPIBuilderController
    {
        return new TestableDataAPIBuilderController(
            $this->createMock(DynamicApiConfigResolver::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );
    }
}

class TestableDataAPIBuilderController extends DataAPIBuilderController
{
    public function exposeNormalizeGenerateListener(mixed $value): bool
    {
        return $this->normalizeGenerateListener($value);
    }

    public function exposeEnsureAfterHitListener(string $listenerName, bool $generateListener): bool
    {
        return $this->ensureAfterHitListener($listenerName, $generateListener);
    }

    public function exposeResolveListenerClassFromPayload(array $payload, ?string $defaultListenerClass = null, ?ApiConfig $existingConfig = null): string
    {
        return $this->resolveListenerClassFromPayload($payload, $defaultListenerClass, $existingConfig);
    }
}
