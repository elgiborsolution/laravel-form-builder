<?php

namespace ESolution\DataSources\Tests\Unit\Services;

use ESolution\DataSources\Events\AfterRunnerApiBuiderEvent;
use ESolution\DataSources\Models\ApiConfig;
use ESolution\DataSources\Models\ApiHook;
use ESolution\DataSources\Services\AfterHitApiDispatcher;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AfterHitApiDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeAfterHitListener::reset();
    }

    public function test_it_dispatches_the_listeners_after_a_successful_response(): void
    {
        $dispatcher = new AfterHitApiDispatcher(new Dispatcher(new Container()));

        $apiConfig = new ApiConfig();
        $apiConfig->route_name = 'customers.index';
        $apiConfig->setRelation('hook', new ApiHook([
            'action_type' => 'after_hit_api',
            'listener_class' => FakeAfterHitListener::class,
        ]));

        $request = Request::create('/api/customers', 'GET');
        $response = new JsonResponse(['message' => 'ok'], 200);
        $payload = [
            'name' => 'Alice',
            'email' => 'alice@example.test',
        ];

        $finalResult = [
            'id' => 99,
            'name' => 'Alice',
            'created_at' => '2026-06-10 10:00:00',
        ];

        $dispatcher->dispatchIfSuccessful(
            $apiConfig,
            $request,
            $response,
            99,
            $payload,
            $finalResult,
            'create'
        );

        $this->assertSame(1, FakeAfterHitListener::$handledCount);
        $this->assertInstanceOf(AfterRunnerApiBuiderEvent::class, FakeAfterHitListener::$lastEvent);
        $this->assertSame('customers.index', FakeAfterHitListener::$lastEvent?->apiConfig->route_name);
        $this->assertSame(99, FakeAfterHitListener::$lastEvent?->resolvedId);
        $this->assertSame($payload, FakeAfterHitListener::$lastEvent?->payload);
        $this->assertSame($finalResult, FakeAfterHitListener::$lastEvent?->result);
        $this->assertSame('create', FakeAfterHitListener::$lastEvent?->action);
    }

    public function test_it_skips_failed_responses(): void
    {
        $dispatcher = new AfterHitApiDispatcher(new Dispatcher(new Container()));

        $apiConfig = new ApiConfig();
        $apiConfig->route_name = 'customers.index';
        $apiConfig->setRelation('hook', new ApiHook([
            'action_type' => 'after_hit_api',
            'listener_class' => FakeAfterHitListener::class,
        ]));

        $request = Request::create('/api/customers', 'GET');
        $response = new JsonResponse(['message' => 'error'], 500);

        $dispatcher->dispatchIfSuccessful($apiConfig, $request, $response);

        $this->assertSame(0, FakeAfterHitListener::$handledCount);
        $this->assertNull(FakeAfterHitListener::$lastEvent);
    }

    public function test_it_keeps_deleted_id_and_empties_payload_for_delete_flow(): void
    {
        $dispatcher = new AfterHitApiDispatcher(new Dispatcher(new Container()));

        $apiConfig = new ApiConfig();
        $apiConfig->route_name = 'customers.destroy';
        $apiConfig->setRelation('hook', new ApiHook([
            'action_type' => 'after_hit_api',
            'listener_class' => FakeAfterHitListener::class,
        ]));

        $request = Request::create('/api/customers/15', 'DELETE');
        $response = new JsonResponse(['message' => 'ok'], 200);

        $beforeData = [
            'id' => 15,
            'name' => 'Old brand',
        ];

        $dispatcher->dispatchIfSuccessful(
            $apiConfig,
            $request,
            $response,
            15,
            [],
            ['id' => 15, 'deleted' => true],
            'delete',
            $beforeData
        );

        $this->assertSame(1, FakeAfterHitListener::$handledCount);
        $this->assertSame(15, FakeAfterHitListener::$lastEvent?->resolvedId);
        $this->assertSame([], FakeAfterHitListener::$lastEvent?->payload);
        $this->assertSame(['id' => 15, 'deleted' => true], FakeAfterHitListener::$lastEvent?->result);
        $this->assertSame($beforeData, FakeAfterHitListener::$lastEvent?->beforeData);
        $this->assertSame('delete', FakeAfterHitListener::$lastEvent?->action);
    }
}

class FakeAfterHitListener
{
    public static int $handledCount = 0;
    public static ?AfterRunnerApiBuiderEvent $lastEvent = null;

    public function handle(AfterRunnerApiBuiderEvent $event): void
    {
        self::$handledCount++;
        self::$lastEvent = $event;
    }

    public static function reset(): void
    {
        self::$handledCount = 0;
        self::$lastEvent = null;
    }
}
