<?php

namespace ESolution\DataSources\Tests\Unit\Models;

use ESolution\DataSources\Models\FormBuilder;
use PHPUnit\Framework\TestCase;

class FormBuilderTest extends TestCase
{
    public function test_it_casts_enabled_and_schema_attributes(): void
    {
        $formBuilder = new FormBuilder([
            'enabled' => 1,
            'schema' => [
                'title' => 'Customer Form',
                'layout' => ['columns' => 2],
                'fields' => [],
            ],
        ]);

        $this->assertTrue($formBuilder->enabled);
        $this->assertSame('Customer Form', $formBuilder->schema['title']);
    }
}

