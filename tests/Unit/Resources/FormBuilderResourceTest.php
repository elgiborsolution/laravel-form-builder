<?php

namespace ESolution\DataSources\Tests\Unit\Resources;

use ESolution\DataSources\Models\FormBuilder;
use ESolution\DataSources\Resources\FormBuilderResource;
use PHPUnit\Framework\TestCase;

class FormBuilderResourceTest extends TestCase
{
    public function test_it_transforms_summary_detail_and_schema_payloads(): void
    {
        $formBuilder = new FormBuilder([
            'id' => 1,
            'code' => 'CUSTOMER_FORM',
            'name' => 'Customer Form',
            'enabled' => true,
            'schema' => [
                'title' => 'Customer Form',
                'layout' => ['columns' => 2],
                'fields' => [],
            ],
        ]);

        $this->assertSame([
            'id' => 1,
            'code' => 'CUSTOMER_FORM',
            'name' => 'Customer Form',
            'enabled' => true,
        ], FormBuilderResource::summary($formBuilder));

        $this->assertSame([
            'id' => 1,
            'code' => 'CUSTOMER_FORM',
            'name' => 'Customer Form',
            'schema' => [
                'title' => 'Customer Form',
                'layout' => ['columns' => 2],
                'fields' => [],
            ],
        ], FormBuilderResource::detail($formBuilder));

        $this->assertSame([
            'title' => 'Customer Form',
            'layout' => ['columns' => 2],
            'fields' => [],
        ], FormBuilderResource::schema($formBuilder));
    }
}

