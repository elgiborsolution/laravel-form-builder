<?php

namespace ESolution\DataSources\Tests\Unit\Runtime;

use ESolution\DataSources\Runtime\DefaultRuntimeVariableRegistry;
use PHPUnit\Framework\TestCase;

class DefaultRuntimeVariableRegistryTest extends TestCase
{
    public function test_it_registers_default_variables(): void
    {
        $registry = new DefaultRuntimeVariableRegistry();

        $this->assertTrue($registry->has('auth.id'));
        $this->assertTrue($registry->has('auth.role.name'));
        $this->assertTrue($registry->has('request.ip'));
        $this->assertTrue($registry->has('date.now'));
        $this->assertTrue($registry->has('app.env'));

        $definition = $registry->get('auth.id');

        $this->assertNotNull($definition);
        $this->assertSame('number', $definition->type);
        $this->assertSame('Current authenticated user ID', $definition->description);
    }

    public function test_it_resolves_the_current_date_time_variable(): void
    {
        $registry = new DefaultRuntimeVariableRegistry();

        $this->assertMatchesRegularExpression(
            '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/',
            (string) $registry->resolve('date.now')
        );
    }

    public function test_it_can_be_extended_and_resolve_custom_variables(): void
    {
        $registry = new class () extends DefaultRuntimeVariableRegistry {
            protected function variables(): array
            {
                return [
                    'tenant.id' => [
                        'type' => 'number',
                        'description' => 'Current tenant ID',
                        'resolver' => static fn () => 99,
                    ],
                ];
            }
        };

        $this->assertTrue($registry->has('tenant.id'));
        $this->assertSame(99, $registry->resolve('tenant.id'));
    }
}
