<?php

namespace ESolution\DataSources\Runtime;

use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;

class RuntimeVariableRegistryResolver
{
    public function resolveClass(): string
    {
        $configured = $this->configuredRegistry();

        if ($this->isValidRegistryClass($configured)) {
            return $configured;
        }

        $autoDiscovered = $this->autoDiscoveredRegistry();

        if ($this->isValidRegistryClass($autoDiscovered)) {
            return $autoDiscovered;
        }

        return DefaultRuntimeVariableRegistry::class;
    }

    public function resolveInstance(): RuntimeVariableRegistryInterface
    {
        $class = $this->resolveClass();

        return app()->make($class);
    }

    protected function configuredRegistry(): mixed
    {
        $configured = config('data-sources.runtime_variable_registry');

        if ($configured === null || $configured === '') {
            $configured = config('datasources.runtime_variable_registry');
        }

        return $configured;
    }

    protected function autoDiscoveredRegistry(): ?string
    {
        $candidate = '\\App\\Runtime\\AppRuntimeVariableRegistry';

        return class_exists($candidate) ? $candidate : null;
    }

    protected function isValidRegistryClass(mixed $class): bool
    {
        if (! is_string($class) || ! class_exists($class)) {
            return false;
        }

        return in_array(
            RuntimeVariableRegistryInterface::class,
            class_implements($class) ?: [],
            true
        );
    }
}
