<?php

namespace ESolution\DataSources\Runtime;

use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;
use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use Illuminate\Support\Collection;

class DefaultRuntimeVariableRegistry implements RuntimeVariableRegistryInterface
{
    /**
     * @var array<string, RuntimeVariableDefinition>
     */
    protected array $definitions = [];

    public function __construct()
    {
        $this->definitions = $this->buildDefinitions();
    }

    public function all(): array
    {
        return Collection::make($this->definitions)
            ->sortKeys()
            ->values()
            ->all();
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$this->normalizeKey($key)]);
    }

    public function get(string $key): ?RuntimeVariableDefinition
    {
        $key = $this->normalizeKey($key);

        return $this->definitions[$key] ?? null;
    }

    public function resolve(string $key): mixed
    {
        $definition = $this->get($key);

        if (! $definition) {
            throw new InvalidRuntimeVariableException($key);
        }

        return $definition->resolve();
    }

    /**
     * @return array<string, RuntimeVariableDefinition>
     */
    protected function buildDefinitions(): array
    {
        $definitions = [];

        foreach ($this->variables() as $key => $definition) {
            if ($definition instanceof RuntimeVariableDefinition) {
                $definitions[$this->normalizeKey($definition->key)] = $definition;
                continue;
            }

            if (! is_array($definition)) {
                continue;
            }

            $normalizedKey = $this->normalizeKey((string) $key);
            $definitions[$normalizedKey] = RuntimeVariableDefinition::make(
                $normalizedKey,
                (string) ($definition['type'] ?? 'string'),
                $definition['resolver'] ?? static fn () => null,
                $definition['description'] ?? null,
                (bool) ($definition['exposed'] ?? true),
            );
        }

        return $definitions;
    }

    /**
     * @return array<string, RuntimeVariableDefinition|array<string, mixed>>
     */
    protected function variables(): array
    {
        return [
            'auth.id' => [
                'type' => 'number',
                'description' => 'Current authenticated user ID',
                'resolver' => static fn () => auth()->user()?->id,
            ],
            'request.ip' => [
                'type' => 'string',
                'description' => 'Current request IP address',
                'resolver' => static fn () => request()?->ip(),
            ],
            'date.now' => [
                'type' => 'string',
                'description' => 'Current date and time',
                'resolver' => static fn () => \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s'),
            ],
            'app.env' => [
                'type' => 'string',
                'description' => 'Current application environment',
                'resolver' => static fn () => app()->environment(),
            ],
            'uuid.random' => [
                'type' => 'string',
                'description' => 'Generate a random UUID v4',
                'resolver' => static fn () => \Illuminate\Support\Str::uuid()->toString(),
            ],
        ];
    }

    protected function normalizeKey(string $key): string
    {
        return trim($key);
    }
}
