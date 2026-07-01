<?php

namespace ESolution\DataSources\Services;

use ESolution\DataSources\Events\AfterRunnerApiBuiderEvent;
use ESolution\DataSources\Models\ApiConfig;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AfterHitApiDispatcher
{
    /**
     * Keep runtime registrations stable within the same PHP process.
     *
     * @var array<string, array<string, bool>>
     */
    protected array $registeredListeners = [];

    public function __construct(
        protected EventDispatcher $events
    ) {
    }

    public function dispatchIfSuccessful(
        ApiConfig $apiConfig,
        Request $request,
        JsonResponse $response,
        mixed $resolvedId = null,
        array $payload = [],
        array $result = [],
        string $action = '',
        array $beforeData = []
    ): void {
        
        if (! $response->isSuccessful()) {
            return;
        }

        $listenerClass = $this->resolveListenerClass($apiConfig);

        if ($listenerClass === null || ! class_exists($listenerClass)) {
            return;
        }

        $eventClass = $this->resolveEventClass();
        $this->registerListener($eventClass, $listenerClass);

        $event = $this->makeEvent($eventClass, $apiConfig, $request, $response, $resolvedId);
        $this->attachContext($event, $payload, $result, $action, $beforeData);
        
        $this->events->dispatch($event);
    }

    protected function makeEvent(
        string $eventClass,
        ApiConfig $apiConfig,
        Request $request,
        JsonResponse $response,
        mixed $resolvedId = null
    ): object {
        return new $eventClass($apiConfig, $request, $response, $resolvedId);
    }

    /**
     * Attach the runtime after-hit context to the event without requiring a
     * constructor signature change in the app event class.
     *
     * @param object $event
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     * @param array<string, mixed> $beforeData
     */
    protected function attachContext(
        object $event,
        array $payload,
        array $result,
        string $action,
        array $beforeData
    ): void
    {
        if (property_exists($event, 'payload')) {
            $event->payload = $payload;
        }

        $event->result = $result;
        $event->action = $action;
        $event->beforeData = $beforeData;
    }

    protected function registerListener(string $eventClass, string $listenerClass): void
    {
        if (isset($this->registeredListeners[$eventClass][$listenerClass])) {
            return;
        }

        $this->events->listen($eventClass, $listenerClass);
        $this->registeredListeners[$eventClass][$listenerClass] = true;
    }

    protected function resolveEventClass(): string
    {
        $eventClass = \App\Events\AfterRunnerApiBuiderEvent::class;

        if (class_exists($eventClass)) {
            return $eventClass;
        }

        return AfterRunnerApiBuiderEvent::class;
    }

    protected function resolveListenerClass(ApiConfig $apiConfig): ?string
    {
        $hook = $apiConfig->hook;

        if ($hook !== null) {
            $actionType = strtolower(trim((string) ($hook->action_type ?? '')));

            if ($actionType !== '' && $actionType !== 'after_hit_api') {
                return null;
            }
        }

        $hookListener = trim((string) ($hook?->listener_class ?? ''));

        if ($hookListener !== '') {
            return $hookListener;
        }

        $routeName = trim((string) $apiConfig->route_name);

        if ($routeName === '') {
            return null;
        }

        return null;//'App\\Listeners\\' . $this->buildListenerName($routeName);
    }

    protected function buildListenerName(string $routeName): string
    {
        $cleanString = preg_replace('/[^A-Za-z0-9]/', ' ', $routeName);
        $cleanString = ucwords((string) $cleanString);

        return 'AfterRun' . str_replace(' ', '', $cleanString) . 'Listener';
    }
}
