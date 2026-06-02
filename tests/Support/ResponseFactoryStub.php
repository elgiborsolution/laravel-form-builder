<?php

namespace ESolution\DataSources\Controllers;

use Illuminate\Http\JsonResponse;

if (! function_exists(__NAMESPACE__ . '\\response')) {
    function response(): object
    {
        return new class () {
            public function json(array $data, int $status = 200): JsonResponse
            {
                return new JsonResponse($data, $status);
            }
        };
    }
}
