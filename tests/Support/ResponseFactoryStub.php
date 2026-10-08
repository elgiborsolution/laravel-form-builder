<?php

namespace ESolution\DataSources\Controllers;

use Illuminate\Http\JsonResponse;

if (! function_exists(__NAMESPACE__ . '\\response')) {
    function response(): object
    {
        return new class () {
            public function json(mixed $data, int $status = 200): JsonResponse
            {
                return new JsonResponse($data, $status);
            }

            public function streamDownload(callable $callback, string $name, array $headers = []): \Symfony\Component\HttpFoundation\StreamedResponse
            {
                return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, 200, $headers);
            }
        };
    }
}
