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
            'SELECT table_name AS table_name
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_type = ?
            ORDER BY table_name',
            ['BASE TABLE']
        );

        return array_values(array_map(
            fn (object $row): string => (string) $this->rowValue($row, ['table_name']),
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

        return array_values(array_map(function (object $row): array {
            $key = (string) ($this->rowValue($row, ['column_key']) ?? '');

            return [
                'name' => (string) $this->rowValue($row, ['name']),
                'type' => (string) $this->rowValue($row, ['data_type']),
                'nullable' => strtoupper((string) $this->rowValue($row, ['is_nullable'])) === 'YES',
                'default' => $this->rowValue($row, ['column_default']),
                'key' => $key,
                'primary' => $key === 'PRI',
                'foreign' => $key === 'MUL',
                'extra' => (string) ($this->rowValue($row, ['extra']) ?? ''),
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

        return array_values(array_map(function (object $row): array {
            return [
                'name' => (string) $this->rowValue($row, ['index_name']),
                'column' => (string) $this->rowValue($row, ['column_name']),
                'unique' => (int) $this->rowValue($row, ['non_unique']) === 0,
                'primary' => strtoupper((string) $this->rowValue($row, ['index_name'])) === 'PRIMARY',
                'sequence' => (int) $this->rowValue($row, ['seq_in_index']),
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

        return array_values(array_map(function (object $row): array {
            return [
                'name' => (string) $this->rowValue($row, ['constraint_name']),
                'column' => (string) $this->rowValue($row, ['column_name']),
                'referenced_table' => (string) $this->rowValue($row, ['referenced_table_name']),
                'referenced_column' => (string) $this->rowValue($row, ['referenced_column_name']),
            ];
        }, $rows));
    }

    /**
     * Read a row value while tolerating different PDO casing behaviors.
     *
     * @param object|array<string, mixed> $row
     * @param array<int, string> $keys
     */
    protected function rowValue(object|array $row, array $keys): mixed
    {
        $normalizedKeys = array_map('strtolower', $keys);

        if (is_array($row)) {
            foreach ($row as $key => $value) {
                if (in_array(strtolower((string) $key), $normalizedKeys, true)) {
                    return $value;
                }
            }

            return null;
        }

        $values = get_object_vars($row);

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $normalizedKeys, true)) {
                return $value;
            }
        }

        foreach ($keys as $key) {
            if (property_exists($row, $key)) {
                return $row->{$key};
            }
        }

        return null;
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
