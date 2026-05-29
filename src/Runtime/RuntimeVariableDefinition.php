<?php

namespace ESolution\DataSources\Runtime;

use Closure;

final class RuntimeVariableDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly Closure $resolver,
        public readonly ?string $description = null,
        public readonly bool $exposed = true
    ) {
    }

    public static function make(
        string $key,
        string $type,
        Closure $resolver,
        ?string $description = null,
        bool $exposed = true
    ): self {
        return new self($key, $type, $resolver, $description, $exposed);
    }

    public function resolve(): mixed
    {
        return ($this->resolver)();
    }
}
