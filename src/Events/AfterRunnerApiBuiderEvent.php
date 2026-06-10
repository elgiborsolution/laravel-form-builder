<?php

namespace ESolution\DataSources\Events;

use ESolution\DataSources\Models\ApiConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AfterRunnerApiBuiderEvent
{
    public function __construct(
        public readonly ApiConfig $apiConfig,
        public readonly Request $request,
        public readonly JsonResponse $response,
        public readonly mixed $resolvedId = null
    ) {
    }

    /**
     * Payload captured from the API request after runtime defaults/variables
     * have been applied.
     *
     * @var array<string, mixed>
     */
    public array $payload = [];
}
