<?php

namespace ESolution\DataSources\Database\Drivers;

use ESolution\DataSources\Contracts\DatabaseDriver;
use Illuminate\Database\ConnectionInterface;

class PostgresDatabaseDriver implements DatabaseDriver
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteIdentifierPath($identifier, '"');
    }

    public function compilePaginatedQuery(string $query, int $offset, int $limit): string
    {
        return $query . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }

    public function compileExplainQuery(string $query): string
    {
        return 'EXPLAIN ' . $query;
    }

    public function normalizeLikeOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));

        return match ($operator) {
            'ILIKE', 'NOT ILIKE', 'LIKE', 'NOT LIKE' => $operator,
            default => $operator,
        };
    }

    public function listTables(ConnectionInterface $connection): array
    {
        $rows = $connection->select(
            'SELECT table_schema, table_name
             FROM information_schema.tables
             WHERE table_catalog = current_database()
               AND table_schema NOT IN (?, ?)
               AND table_type = ?
             ORDER BY table_schema, table_name',
            ['information_schema', 'pg_catalog', 'BASE TABLE']
        );

        return array_values(array_map(static function (object $row): string {
            $schema = (string) $row->table_schema;
            $table = (string) $row->table_name;

            return $schema === 'public' ? $table : $schema . '.' . $table;
        }, $rows));
    }

    public function listColumns(ConnectionInterface $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitTableReference($table);
        $schema ??= 'public';

        $rows = $connection->select(
            'SELECT c.column_name AS name,
                    c.data_type AS data_type,
                    c.is_nullable AS is_nullable,
                    c.column_default AS column_default,
                    c.udt_name AS udt_name,
                    c.ordinal_position AS ordinal_position
             FROM information_schema.columns c
             WHERE c.table_catalog = current_database()
               AND c.table_schema = ?
               AND c.table_name = ?
             ORDER BY c.ordinal_position',
            [$schema, $tableName]
        );

        $primaryColumns = $this->primaryKeyColumns($connection, $schema, $tableName);
        $foreignColumns = $this->foreignKeyColumnMap($connection, $schema, $tableName);
        $indexedColumns = $this->indexedColumnMap($connection, $schema, $tableName);

        return array_values(array_map(static function (object $row) use ($primaryColumns, $foreignColumns, $indexedColumns): array {
            $name = (string) $row->name;
            $key = '';

            if (isset($primaryColumns[$name])) {
                $key = 'PRI';
            } elseif (isset($foreignColumns[$name]) || isset($indexedColumns[$name])) {
                $key = 'MUL';
            }

            return [
                'name' => $name,
                'type' => (string) $row->data_type,
                'nullable' => strtoupper((string) $row->is_nullable) === 'YES',
                'default' => $row->column_default,
                'key' => $key,
                'primary' => isset($primaryColumns[$name]),
                'foreign' => isset($foreignColumns[$name]),
                'extra' => '',
            ];
        }, $rows));
    }

    public function listIndexes(ConnectionInterface $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitTableReference($table);
        $schema ??= 'public';

        $rows = $connection->select(
            'SELECT i.relname AS index_name,
                    a.attname AS column_name,
                    ix.indisunique AS is_unique,
                    ix.indisprimary AS is_primary,
                    key_columns.ordinality AS position
             FROM pg_class t
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_index ix ON ix.indrelid = t.oid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN unnest(ix.indkey) WITH ORDINALITY AS key_columns(attnum, ordinality) ON TRUE
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = key_columns.attnum
             WHERE n.nspname = ?
               AND t.relname = ?
             ORDER BY i.relname, key_columns.ordinality',
            [$schema, $tableName]
        );

        return array_values(array_map(static function (object $row): array {
            return [
                'name' => (string) $row->index_name,
                'column' => (string) $row->column_name,
                'unique' => (bool) $row->is_unique,
                'primary' => (bool) $row->is_primary,
                'sequence' => (int) $row->position,
            ];
        }, $rows));
    }

    public function listForeignKeys(ConnectionInterface $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitTableReference($table);
        $schema ??= 'public';

        $rows = $connection->select(
            'SELECT tc.constraint_name,
                    kcu.column_name,
                    ccu.table_schema AS referenced_table_schema,
                    ccu.table_name AS referenced_table_name,
                    ccu.column_name AS referenced_column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
             WHERE tc.table_catalog = current_database()
               AND tc.table_schema = ?
               AND tc.table_name = ?
               AND tc.constraint_type = ?
             ORDER BY tc.constraint_name, kcu.ordinal_position',
            [$schema, $tableName, 'FOREIGN KEY']
        );

        return array_values(array_map(static function (object $row): array {
            $referencedTable = (string) $row->referenced_table_name;
            $referencedSchema = (string) $row->referenced_table_schema;

            if ($referencedSchema !== '' && $referencedSchema !== 'public') {
                $referencedTable = $referencedSchema . '.' . $referencedTable;
            }

            return [
                'name' => (string) $row->constraint_name,
                'column' => (string) $row->column_name,
                'referenced_table' => $referencedTable,
                'referenced_column' => (string) $row->referenced_column_name,
            ];
        }, $rows));
    }

    /**
     * @return array<string, bool>
     */
    protected function primaryKeyColumns(ConnectionInterface $connection, string $schema, string $table): array
    {
        $rows = $connection->select(
            'SELECT kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             WHERE tc.table_catalog = current_database()
               AND tc.table_schema = ?
               AND tc.table_name = ?
               AND tc.constraint_type = ?',
            [$schema, $table, 'PRIMARY KEY']
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->column_name] = true;
        }

        return $columns;
    }

    /**
     * @return array<string, bool>
     */
    protected function foreignKeyColumnMap(ConnectionInterface $connection, string $schema, string $table): array
    {
        $columns = [];

        foreach ($this->listForeignKeys($connection, $schema . '.' . $table) as $foreignKey) {
            $columns[(string) $foreignKey['column']] = true;
        }

        return $columns;
    }

    /**
     * @return array<string, bool>
     */
    protected function indexedColumnMap(ConnectionInterface $connection, string $schema, string $table): array
    {
        $columns = [];

        foreach ($this->listIndexes($connection, $schema . '.' . $table) as $index) {
            $columns[(string) $index['column']] = true;
        }

        return $columns;
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
