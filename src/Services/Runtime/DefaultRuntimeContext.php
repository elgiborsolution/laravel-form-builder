<?php

namespace ESolution\DataSources\Services\Runtime;

use ESolution\DataSources\Contracts\RuntimeContextInterface;
use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;

class DefaultRuntimeContext implements RuntimeContextInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        $key = trim($key);

        if ($key === '') {
            return $default;
        }

        $registry = app(RuntimeVariableRegistryInterface::class);

        if (! $registry->has($key)) {
            return $default;
        }

        $value = $registry->resolve($key);

        return $value === null ? $default : $value;
    }
}
