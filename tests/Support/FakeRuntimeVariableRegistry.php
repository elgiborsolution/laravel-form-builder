<?php

namespace ESolution\DataSources\Tests\Support;

use ESolution\DataSources\Contracts\RuntimeVariableRegistryInterface;
use ESolution\DataSources\Runtime\RuntimeVariableDefinition;
use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;

class FakeRuntimeVariableRegistry implements RuntimeVariableRegistryInterface
{
    /**
     * @var array<string, RuntimeVariableDefinition>
     */
    protected array $definitions = [];

    public function __construct()
    {
        $this->definitions = [
            'auth.id' => RuntimeVariableDefinition::make(
                'auth.id',
                'number',
                static fn () => 5,
                'Current authenticated user ID',
            ),
            'auth.name' => RuntimeVariableDefinition::make(
                'auth.name',
                'string',
                static fn () => 'Alice',
                'Current authenticated user name',
            ),
            'auth.email' => RuntimeVariableDefinition::make(
                'auth.email',
                'string',
                static fn () => 'alice@example.com',
                'Current authenticated user email',
            ),
            'auth.company_id' => RuntimeVariableDefinition::make(
                'auth.company_id',
                'number',
                static fn () => 10,
                'Current company ID',
            ),
            'auth.role.name' => RuntimeVariableDefinition::make(
                'auth.role.name',
                'string',
                static fn () => 'Admin',
                'Current authenticated user role name',
            ),
            'request.ip' => RuntimeVariableDefinition::make(
                'request.ip',
                'string',
                static fn () => '127.0.0.1',
                'Current request IP address',
            ),
            'request.userAgent' => RuntimeVariableDefinition::make(
                'request.userAgent',
                'string',
                static fn () => 'UnitTestAgent',
                'Current request user agent',
            ),
            'date.now' => RuntimeVariableDefinition::make(
                'date.now',
                'string',
                static fn () => '2026-07-01 08:25:10',
                'Current date and time',
            ),
            'app.env' => RuntimeVariableDefinition::make(
                'app.env',
                'string',
                static fn () => 'testing',
                'Current application environment',
            ),
        ];
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[trim($key)]);
    }

    public function get(string $key): ?RuntimeVariableDefinition
    {
        return $this->definitions[trim($key)] ?? null;
    }

    public function resolve(string $key): mixed
    {
        $definition = $this->get($key);

        if (! $definition) {
            throw new InvalidRuntimeVariableException($key);
        }

        return $definition->resolve();
    }
}
