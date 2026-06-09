<?php

namespace ESolution\DataSources\Contracts;

use Illuminate\Database\ConnectionInterface;

interface DatabaseDriver
{
    public function name(): string;

    public function quoteIdentifier(string $identifier): string;

    public function compilePaginatedQuery(string $query, int $offset, int $limit): string;

    public function compileExplainQuery(string $query): string;

    public function normalizeLikeOperator(string $operator): string;

    /**
     * @return array<int, string>
     */
    public function listTables(ConnectionInterface $connection): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listColumns(ConnectionInterface $connection, string $table): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listIndexes(ConnectionInterface $connection, string $table): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForeignKeys(ConnectionInterface $connection, string $table): array;
}
