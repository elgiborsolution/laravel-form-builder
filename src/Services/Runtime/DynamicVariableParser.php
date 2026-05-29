<?php

namespace ESolution\DataSources\Services\Runtime;

use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;
use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;

class DynamicVariableParser
{
    public function __construct(
        protected RuntimeVariableRegistryInterface $registry
    ) {
    }

    public function parse(mixed $value, mixed $default = null): mixed
    {
        if (is_array($value)) {
            $resolved = [];

            foreach ($value as $key => $item) {
                $resolved[$key] = $this->parse($item, $default);
            }

            return $resolved;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (! str_contains($value, '{{')) {
            return $value;
        }

        if (preg_match('/^\s*\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}\s*$/', $value, $matches) === 1) {
            return $this->resolveVariable($matches[1], $default);
        }

        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', function (array $matches) use ($default): string {
            $resolved = $this->resolveVariable($matches[1], $default);

            return $this->stringifyValue($resolved);
        }, $value) ?? $value;
    }

    public function parseArray(array $values, mixed $default = null): array
    {
        $resolved = [];

        foreach ($values as $key => $item) {
            $resolved[$key] = $this->parse($item, $default);
        }

        return $resolved;
    }

    protected function resolveVariable(string $key, mixed $default = null): mixed
    {
        $normalizedKey = trim($key);

        if (! $this->registry->has($normalizedKey)) {
            throw new InvalidRuntimeVariableException($normalizedKey);
        }

        $resolved = $this->registry->resolve($normalizedKey);

        return $resolved === null ? $default : $resolved;
    }

    protected function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
