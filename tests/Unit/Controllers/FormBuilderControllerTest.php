<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\FormBuilderController;
use ESolution\DataSources\Models\FormBuilder;
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

    public function test_it_normalizes_export_selection_and_payloads(): void
    {
        $controller = new TestableFormBuilderController();
        $formBuilder = new FormBuilder([
            'id' => 7,
            'code' => 'FORM_CUSTOMER',
            'name' => 'Customer Form',
            'description' => 'Customer master form',
            'enabled' => 1,
            'schema' => [
                [
                    'name' => 'customer_name',
                    'type' => 'string',
                ],
            ],
            'created_at' => '2026-06-22 09:30:00',
            'updated_at' => '2026-06-22 09:45:00',
        ]);

        $this->assertSame([1, 2, 3], $controller->exposeNormalizeSelectedIds([1, '2', '3']));
        $this->assertSame(['FORM_CUSTOMER', 'FORM_PRODUCT'], $controller->exposeNormalizeSelectedCodes([' FORM_CUSTOMER ', 'FORM_PRODUCT']));

        $export = $controller->exposeBuildExportPayload([$formBuilder]);
        $this->assertSame(1, $export['version']);
        $this->assertArrayHasKey('exported_at', $export);
        $this->assertSame('FORM_CUSTOMER', $export['items'][0]['code']);
        $this->assertSame([
            [
                'name' => 'customer_name',
                'type' => 'string',
            ],
        ], $export['items'][0]['schema']);

        $docs = $controller->exposeBuildDocsPayload();
        $this->assertSame('/api/form-builder', $docs['base_path']);
        $this->assertSame('Export Selected', $docs['endpoints'][0]['name']);

        $collection = $controller->exposeBuildPostmanCollection();
        $this->assertSame('Laravel Form Builder - Form Builder Management', $collection['info']['name']);
        $this->assertSame('GET', $collection['item'][0]['request']['method']);
    }

    public function test_it_validates_import_schema_and_root_payloads(): void
    {
        $controller = new TestableFormBuilderController();

        $this->assertNull($controller->exposeNormalizeImportSchemaValue('not-json'));
        $this->assertSame([
            'title' => 'Customer Form',
        ], $controller->exposeNormalizeImportSchemaValue('{"title":"Customer Form"}'));

        $normalized = $controller->exposeNormalizeImportPayload([
            'version' => 1,
            'exported_at' => '2026-06-22 10:00:00',
            'items' => [
                [
                    'code' => 'FORM_CUSTOMER',
                    'name' => 'Customer Form',
                    'schema' => [
                        ['name' => 'customer_name', 'type' => 'string'],
                    ],
                ],
            ],
        ]);

        $this->assertIsArray($normalized);
        $this->assertSame(1, $normalized['version']);
        $this->assertSame('FORM_CUSTOMER', $normalized['items'][0]['code']);
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

    public function exposeNormalizeSelectedIds(mixed $value): array
    {
        return $this->normalizeSelectedIds($value);
    }

    public function exposeNormalizeSelectedCodes(mixed $value): array
    {
        return $this->normalizeSelectedCodes($value);
    }

    public function exposeBuildExportPayload(iterable $items): array
    {
        return $this->buildExportPayload($items);
    }

    public function exposeBuildDocsPayload(): array
    {
        return $this->buildDocsPayload();
    }

    public function exposeBuildPostmanCollection(): array
    {
        return $this->buildPostmanCollection();
    }

    public function exposeNormalizeImportSchemaValue(mixed $schema): array|object|null
    {
        return $this->normalizeImportSchemaValue($schema);
    }

    public function exposeNormalizeImportPayload(array $payload): ?array
    {
        return $this->normalizeImportPayload($payload);
    }
}
