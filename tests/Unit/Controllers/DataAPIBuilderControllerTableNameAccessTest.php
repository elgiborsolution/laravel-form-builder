<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataAPIBuilderController;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DynamicApiConfigResolver;
use ESolution\DataSources\Tests\Support\FakeRuntimeVariableRegistry;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class DataAPIBuilderControllerTableNameAccessTest extends TestCase
{
    public function test_it_reads_parent_table_name_from_nested_parent_table_payload(): void
    {
        $controller = $this->makeController();

        $this->assertSame(
            'es_absensi',
            $controller->exposeExtractParentTableName([
                'table_name' => 'wrong_top_level_value',
                'parent_table' => [
                    'table_name' => 'es_absensi',
                ],
            ], 'unit-test')
        );
    }

    public function test_it_throws_validation_exception_when_parent_table_name_is_missing(): void
    {
        $controller = $this->makeController();

        $this->expectException(ValidationException::class);

        $controller->exposeExtractParentTableName([
            'parent_table' => [],
        ], 'unit-test');
    }

    public function test_it_reads_child_table_name_from_child_table_row(): void
    {
        $controller = $this->makeController();

        $this->assertSame(
            'es_absensi_detail',
            $controller->exposeExtractChildTableName([
                'table_name' => 'es_absensi_detail',
                'foreign_key' => 'absensi_id',
            ], 'unit-test', 0)
        );
    }

    private function makeController(): TestableTableNameDataAPIBuilderController
    {
        return new TestableTableNameDataAPIBuilderController(
            $this->createMock(DynamicApiConfigResolver::class),
            new DynamicVariableParser(new FakeRuntimeVariableRegistry())
        );
    }
}

class TestableTableNameDataAPIBuilderController extends DataAPIBuilderController
{
    public function exposeExtractParentTableName(array $payload, string $context): string
    {
        return $this->extractParentTableName($payload, $context);
    }

    public function exposeExtractChildTableName(
        array $childTable,
        string $context,
        int|string|null $index = null,
        bool $allowBlankPlaceholder = false
    ): ?string {
        return $this->extractChildTableName($childTable, $context, $index, $allowBlankPlaceholder);
    }
}
