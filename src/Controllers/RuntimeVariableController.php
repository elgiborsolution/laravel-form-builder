<?php

namespace ESolution\DataSources\Controllers;

use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class RuntimeVariableController extends Controller
{
    public function __construct(
        protected RuntimeVariableRegistryInterface $registry
    ) {
    }

    public function index(): JsonResponse
    {
        $variables = array_values(array_filter(
            $this->registry->all(),
            static fn ($definition) => $definition->exposed === true
        ));

        usort($variables, static function ($left, $right): int {
            return strcmp($left->key, $right->key);
        });

        return response()->json([
            'data' => array_map(static function ($definition): array {
                return [
                    'key' => $definition->key,
                    'type' => $definition->type,
                    'description' => $definition->description,
                ];
            }, $variables),
        ]);
    }
}
