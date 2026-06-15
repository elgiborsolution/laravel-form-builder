<?php

namespace ESolution\DataSources\Tests\Unit\Support;

use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class SearchFilterTraitTest extends TestCase
{
    /**
     * @dataProvider searchProvider
     */
    public function test_it_filters_loaded_rows_by_search_term(array $rows, string $search, array $expected): void
    {
        $request = Request::create('/test', 'GET', ['search' => $search]);

        $this->assertSame(
            $expected,
            (new SearchFilterTestHarness())->filterRows($rows, $request, ['code', 'name', 'description'])
        );
    }

    public static function searchProvider(): array
    {
        $rows = [
            [
                'code' => 'DP001',
                'name' => 'Customer Picker',
                'description' => 'Pick customer data',
            ],
            [
                'code' => 'TB001',
                'name' => 'Order Builder',
                'description' => 'Build order table',
            ],
            [
                'code' => 'AP001',
                'name' => 'Route Config',
                'description' => 'Api route manager',
            ],
        ];

        return [
            'search empty' => [
                $rows,
                '',
                $rows,
            ],
            'search by code' => [
                $rows,
                'DP001',
                [$rows[0]],
            ],
            'search by name' => [
                $rows,
                'Order Builder',
                [$rows[1]],
            ],
            'search not found' => [
                $rows,
                'Missing',
                [],
            ],
        ];
    }
}

class SearchFilterTestHarness
{
    use AppliesSearchFilter;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param Request $request
     * @param array<int, string> $columns
     * @return array<int, array<string, mixed>>
     */
    public function filterRows(array $rows, Request $request, array $columns): array
    {
        return $this->filterSearchRows($rows, $request, $columns, null);
    }
}
