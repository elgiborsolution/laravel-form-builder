<?php

namespace ESolution\DataSources\Tests\Unit\Support;

use ESolution\DataSources\Support\AppRuntimeVariableRegistryStub;
use PHPUnit\Framework\TestCase;

class AppRuntimeVariableRegistryStubTest extends TestCase
{
    public function test_it_contains_the_expected_default_registry_scaffold(): void
    {
        $stub = new AppRuntimeVariableRegistryStub();
        $contents = $stub->contents();

        $this->assertStringContainsString('namespace App\\Runtime;', $contents);
        $this->assertStringContainsString('class AppRuntimeVariableRegistry extends DefaultRuntimeVariableRegistry', $contents);
        $this->assertStringContainsString('parent::variables()', $contents);
        $this->assertStringContainsString('auth.role_id', $contents);
        $this->assertStringContainsString('auth.branch_id', $contents);
        $this->assertStringContainsString('User customization area', $contents);
    }
}
