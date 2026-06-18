<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\FormBuilderController;
use PHPUnit\Framework\TestCase;

class FormBuilderControllerTest extends TestCase
{
    public function test_it_accepts_only_supported_sort_columns_and_directions(): void
    {
        $controller = new TestableFormBuilderController();

        $this->assertSame('name', $controller->exposeNormalizeSortColumn('name'));
        $this->assertSame('id', $controller->exposeNormalizeSortColumn('unknown'));
        $this->assertSame('asc', $controller->exposeNormalizeSortDirection('ASC'));
        $this->assertSame('desc', $controller->exposeNormalizeSortDirection('anything-else'));
    }
}

class TestableFormBuilderController extends FormBuilderController
{
    public function exposeNormalizeSortColumn(mixed $value): string
    {
        return $this->normalizeSortColumn($value);
    }

    public function exposeNormalizeSortDirection(mixed $value): string
    {
        return $this->normalizeSortDirection($value);
    }
}

