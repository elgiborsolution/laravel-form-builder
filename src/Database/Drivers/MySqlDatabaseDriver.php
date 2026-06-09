<?php

namespace ESolution\DataSources\Database\Drivers;

use ESolution\DataSources\Contracts\DatabaseDriver;
use Illuminate\Database\ConnectionInterface;

class MySqlDatabaseDriver implements DatabaseDriver
{
    public function name(): string
    {
        return 'mysql';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteIdentifierPath($identifier, '`');
    }

    public function compilePaginatedQuery(string $query, int $offset, int $limit): string
    {
        return $query . ' LIMIT ' . $offset . ', ' . $limit;
    }

    public function compileExplainQuery(string $query): string
    {
        return 'EXPLAIN ' . $query;
    }

    public function normalizeLikeOperator(string $operator): string
    {
        return match (strtoupper(trim($operator))) {
            'ILIKE' => 'LIKE',
            'NOT ILIKE' => 'NOT LIKE',
            default => strtoupper(trim($operator)),
        };
    }

    public function listTables(ConnectionInterface $connection): array
    {
        $rows = $connection->select(
            'SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_type = ?
            ORDER BY table_name',
            ['BASE TABLE']
        );

        return array_values(array_map(
            static fn (object $row): string => (string) $row->table_name,
            $rows
        ));
    }

    public function listColumns(ConnectionInterface $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitTableReference($table);

        $rows = $connection->select(
            'SELECT c.column_name AS name,
                    c.column_type AS data_type,
                    c.is_nullable AS is_nullable,
                    c.column_default AS column_default,
                    c.column_key AS column_key,
                    c.extra AS extra,
                    c.ordinal_position AS ordinal_position
             FROM information_schema.columns c
             WHERE c.table_schema = COALESCE(?, DATABASE())
               AND c.table_name = ?
             ORDER BY c.ordinal_position',
            [$schema, $tableName]
        );

        return array_values(array_map(static function (object $row): array {
            $key = (string) ($row->column_key ?? '');

            return [
                'name' => (string) $row->name,
                'type' => (string) $row->data_type,
                'nullable' => strtoupper((string) $row->is_nullable) === 'YES',
                'default' => $row->column_default,
                'key' => $key,
                'primary' => $key === 'PRI',
                'foreign' => $key === 'MUL',
                'extra' => (string) ($row->extra ?? ''),
            ];
        }, $rows));
    }

    public function listIndexes(ConnectionInterface $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitTableReference($table);

        $rows = $connection->select(
            'SELECT index_name,
                    non_unique,
                    column_name,
                    seq_in_index
             FROM information_schema.statistics
             WHERE table_schema = COALESCE(?, DATABASE())
               AND table_name = ?
             ORDER BY index_name, seq_in_index',
            [$schema, $tableName]
        );

        return array_values(array_map(static function (object $row): array {
            return [
                'name' => (string) $row->index_name,
                'column' => (string) $row->column_name,
                'unique' => (int) $row->non_unique === 0,
                'primary' => strtoupper((string) $row->index_name) === 'PRIMARY',
                'sequence' => (int) $row->seq_in_index,
            ];
        }, $rows));
    }

    public function listForeignKeys(ConnectionInterface $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitTableReference($table);

        $rows = $connection->select(
            'SELECT constraint_name,
                    column_name,
                    referenced_table_name,
                    referenced_column_name
             FROM information_schema.key_column_usage
             WHERE table_schema = COALESCE(?, DATABASE())
               AND table_name = ?
               AND referenced_table_name IS NOT NULL
             ORDER BY constraint_name, ordinal_position',
            [$schema, $tableName]
        );

        return array_values(array_map(static function (object $row): array {
            return [
                'name' => (string) $row->constraint_name,
                'column' => (string) $row->column_name,
                'referenced_table' => (string) $row->referenced_table_name,
                'referenced_column' => (string) $row->referenced_column_name,
            ];
        }, $rows));
    }

    protected function splitTableReference(string $table): array
    {
        $segments = array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment, " \t\n\r\0\x0B`\""),
            explode('.', $table)),
            static fn (string $segment): bool => $segment !== ''
        ));

        if (count($segments) >= 2) {
            return [$segments[count($segments) - 2], $segments[count($segments) - 1]];
        }

        return [null, $segments[0] ?? trim($table, " \t\n\r\0\x0B`\"")];
    }

    protected function quoteIdentifierPath(string $identifier, string $quote): string
    {
        $segments = explode('.', trim($identifier));
        $quoted = [];

        foreach ($segments as $segment) {
            $segment = trim($segment, " \t\n\r\0\x0B`\"");

            if ($segment === '*') {
                $quoted[] = '*';
                continue;
            }

            $quoted[] = $quote . $segment . $quote;
        }

        return implode('.', $quoted);
    }
}
