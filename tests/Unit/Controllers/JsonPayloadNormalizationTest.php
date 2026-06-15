<?php

namespace ESolution\DataSources\Tests\Unit\Controllers;

use ESolution\DataSources\Controllers\DataPickerController;
use ESolution\DataSources\Controllers\DataTableBuilderController;
use PHPUnit\Framework\TestCase;

class JsonPayloadNormalizationTest extends TestCase
{
    /**
     * @dataProvider payloadProvider
     */
    public function test_it_normalizes_json_payloads_into_native_arrays(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, $this->makePickerController()->exposeNormalizeJsonPayload($input));
        $this->assertSame($expected, $this->makeTableBuilderController()->exposeNormalizeJsonPayload($input));
    }

    public static function payloadProvider(): array
    {
        return [
            'json string array' => [
                '[{"name":"id"}]',
                [
                    ['name' => 'id'],
                ],
            ],
            'associative json string' => [
                '{"enable_no":true,"pagination":false,"data_source_id":1}',
                [
                    'enable_no' => true,
                    'pagination' => false,
                    'data_source_id' => 1,
                ],
            ],
            'double encoded json string' => [
                '"[{\\"header\\":\\"name\\",\\"detail\\":\\"name\\"}]"',
                [
                    [
                        'header' => 'name',
                        'detail' => 'name',
                    ],
                ],
            ],
            'already array' => [
                ['header' => 'Name', 'detail' => 'name'],
                ['header' => 'Name', 'detail' => 'name'],
            ],
            'string biasa' => [
                'Customer',
                'Customer',
            ],
            'sql query' => [
                'SELECT * FROM customers',
                'SELECT * FROM customers',
            ],
            'invalid json' => [
                '[{invalid json}]',
                '[{invalid json}]',
            ],
        ];
    }

    private function makePickerController(): TestableDataPickerController
    {
        return new TestableDataPickerController();
    }

    private function makeTableBuilderController(): TestableDataTableBuilderController
    {
        return new TestableDataTableBuilderController();
    }
}

class TestableDataPickerController extends DataPickerController
{
    public function exposeNormalizeJsonPayload(mixed $value): mixed
    {
        return $this->normalizeJsonPayload($value);
    }
}

class TestableDataTableBuilderController extends DataTableBuilderController
{
    public function exposeNormalizeJsonPayload(mixed $value): mixed
    {
        return $this->normalizeJsonPayload($value);
    }
}
