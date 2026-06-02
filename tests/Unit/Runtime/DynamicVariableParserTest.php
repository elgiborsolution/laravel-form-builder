<?php

namespace ESolution\DataSources\Tests\Unit\Runtime;

use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use PHPUnit\Framework\TestCase;

class DynamicVariableParserTest extends TestCase
{
    public function test_it_parses_registered_runtime_variables(): void
    {
        $parser = new DynamicVariableParser(new FakeRuntimeVariableRegistry());

        $this->assertSame(10, $parser->parse('{{ auth.company_id }}'));
        $this->assertSame('Alice', $parser->parse('{{ auth.name }}'));
        $this->assertSame('testing', $parser->parse('{{ app.env }}'));
    }

    public function test_it_parses_nested_runtime_variables(): void
    {
        $parser = new DynamicVariableParser(new FakeRuntimeVariableRegistry());

        $this->assertSame('Admin', $parser->parse('{{ auth.role.name }}'));
    }

    public function test_it_parses_multiple_runtime_variables_in_one_string(): void
    {
        $parser = new DynamicVariableParser(new FakeRuntimeVariableRegistry());

        $this->assertSame('INV-10-5', $parser->parse('INV-{{ auth.company_id }}-{{ auth.id }}'));
    }

    public function test_it_preserves_static_values(): void
    {
        $parser = new DynamicVariableParser(new FakeRuntimeVariableRegistry());

        $this->assertSame('plain-text', $parser->parse('plain-text'));
        $this->assertSame(123, $parser->parse(123));
    }

    public function test_it_throws_for_unknown_variables(): void
    {
        $parser = new DynamicVariableParser(new FakeRuntimeVariableRegistry());

        $this->expectException(InvalidRuntimeVariableException::class);
        $parser->parse('{{ auth.password }}');
    }
}
