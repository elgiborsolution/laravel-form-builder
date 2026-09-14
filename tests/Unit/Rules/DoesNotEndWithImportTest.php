<?php

namespace ESolution\DataSources\Tests\Unit\Rules;

use ESolution\DataSources\Rules\DoesNotEndWithImport;
use PHPUnit\Framework\TestCase;

class DoesNotEndWithImportTest extends TestCase
{
    public function test_data_source_create_rejects_a_path_ending_in_import(): void
    {
        $this->assertRejected('/customer/import');
    }

    public function test_data_source_update_rejects_a_normalized_path_ending_in_import(): void
    {
        $this->assertRejected('/tenant/test/IMPORT/');
    }

    public function test_api_builder_create_rejects_a_path_ending_in_import(): void
    {
        $this->assertRejected('customer/import');
    }

    public function test_api_builder_update_rejects_a_normalized_path_ending_in_import(): void
    {
        $this->assertRejected(' tenant/test/import/ ');
    }

    public function test_paths_with_import_outside_the_final_segment_are_allowed(): void
    {
        $this->assertAllowed('/customer/import-data');
        $this->assertAllowed('/import/customer');
        $this->assertAllowed('/tenant/test/import/list');
        $this->assertAllowed('/customer');
    }

    private function assertRejected(string $endpoint): void
    {
        $messages = $this->validate($endpoint);

        $this->assertSame([DoesNotEndWithImport::MESSAGE], $messages);
    }

    private function assertAllowed(string $endpoint): void
    {
        $this->assertSame([], $this->validate($endpoint));
    }

    private function validate(string $endpoint): array
    {
        $messages = [];

        (new DoesNotEndWithImport())->validate(
            'endpoint',
            $endpoint,
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        return $messages;
    }
}
