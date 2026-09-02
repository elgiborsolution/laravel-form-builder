<?php

namespace ESolution\DataSources\Tests\Unit\Services;

use ESolution\DataSources\Services\Import\ImportRecordProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ImportRecordProcessorWorksheetRowsTest extends TestCase
{
    public function test_it_ignores_a_footer_after_a_long_empty_worksheet_range(): void
    {
        $processor = (new ReflectionClass(ImportRecordProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ImportRecordProcessor::class, 'filterWorksheetRowsForMappings');

        $result = $method->invoke($processor, [
            ['row' => 3, 'data' => ['Kode Barang' => 'YGP.0001', 'Nama Barang' => 'Product A', 'ORDER' => '10', 'Keterangan' => null]],
            ['row' => 4, 'data' => ['Kode Barang' => 'YGP.0002', 'Nama Barang' => 'Product B', 'ORDER' => '2', 'Keterangan' => null]],
            // A note in an unmapped column is not an import record.
            ['row' => 5004, 'data' => ['Kode Barang' => null, 'Nama Barang' => null, 'Keterangan' => 'Keterangan: fill in all rows above.']],
            ['row' => 5005, 'data' => ['Kode Barang' => null, 'Nama Barang' => null, 'Keterangan' => null]],
        ], [
            'part_number' => ['source' => 'Kode Barang', 'source_type' => 'worksheet_header', 'required' => true],
            'name' => ['source' => 'Nama Barang', 'source_type' => 'worksheet_header', 'required' => true],
            'qty' => ['source' => 'ORDER', 'source_type' => 'worksheet_header', 'required' => true],
            'created_by' => ['source' => '{{ auth.id }}', 'source_type' => 'runtime_variable'],
        ]);

        $this->assertSame([3, 4], array_column($result, 'row'));
    }

    public function test_it_keeps_a_partial_row_inside_an_active_data_region_for_validation(): void
    {
        $processor = (new ReflectionClass(ImportRecordProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ImportRecordProcessor::class, 'filterWorksheetRowsForMappings');

        $result = $method->invoke($processor, [
            ['row' => 3, 'data' => ['Kode Barang' => 'YGP.0001', 'Nama Barang' => 'Product A', 'ORDER' => '10']],
            ['row' => 4, 'data' => ['Kode Barang' => 'YGP.0002', 'Nama Barang' => null, 'ORDER' => '5']],
        ], [
            'part_number' => ['source' => 'Kode Barang', 'source_type' => 'worksheet_header', 'required' => true],
            'name' => ['source' => 'Nama Barang', 'source_type' => 'worksheet_header', 'required' => true],
            'qty' => ['source' => 'ORDER', 'source_type' => 'worksheet_header', 'required' => true],
        ]);

        $this->assertSame([3, 4], array_column($result, 'row'));
    }
}
