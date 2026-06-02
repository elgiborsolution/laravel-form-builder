<?php

namespace ESolution\DataSources\Contracts;

use ESolution\DataSources\Runtime\RuntimeVariableDefinition;

interface RuntimeVariableRegistryInterface
{
    /**
     * @return array<int, RuntimeVariableDefinition>
     */
    public function all(): array;

    public function has(string $key): bool;

    public function get(string $key): ?RuntimeVariableDefinition;

    public function resolve(string $key): mixed;
}
