<?php

namespace ESolution\DataSources\Tests\Unit\Models;

use ESolution\DataSources\Models\DataSource;
use PHPUnit\Framework\TestCase;

class DataSourceValidateQueryTest extends TestCase
{
    public function test_it_allows_multiline_select_queries(): void
    {
        $query = <<<SQL
SELECT
    p.id,
    p.name
FROM products p
SQL;

        $this->assertTrue(DataSource::validateQuery($query));
    }

    public function test_it_rejects_non_select_queries(): void
    {
        $this->assertFalse(DataSource::validateQuery('UPDATE products SET name = "x"'));
    }
}
