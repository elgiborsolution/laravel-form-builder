<?php

namespace ESolution\DataSources\Services;

use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DatabaseConnection;

class CustomQueryService
{
    public function __construct(
        protected DynamicVariableParser $runtimeVariableParser
    ) {
    }

    /**
     * Validate a custom query and return a structured response.
     *
     * @param string $query
     * @param string|null $connectionName
     * @return array{valid:bool,message?:string}
     */
    public function validate(string $query, ?string $connectionName = null): array
    {
        try {
            $this->inspect($query, $connectionName);

            return ['valid' => true];
        } catch (\Throwable $exception) {
            return [
                'valid' => false,
                'message' => $this->normalizeExceptionMessage($exception),
            ];
        }
    }

    /**
     * Validate a custom query and return its extracted columns.
     *
     * @param string $query
     * @param string|null $connectionName
     * @return array{valid:bool,columns:array<int, string>,message?:string}
     */
    public function extractColumns(string $query, ?string $connectionName = null): array
    {
        try {
            $inspection = $this->inspect($query, $connectionName);

            return [
                'valid' => true,
                'columns' => $inspection['columns'],
            ];
        } catch (\Throwable $exception) {
            return [
                'valid' => false,
                'columns' => [],
                'message' => $this->normalizeExceptionMessage($exception),
            ];
        }
    }

    /**
     * Validate a custom query and return both the status and the extracted columns.
     *
     * @param string $query
     * @param string|null $connectionName
     * @return array{valid:bool,columns:array<int, string>,message?:string}
     */
    public function validateAndExtract(string $query, ?string $connectionName = null): array
    {
        try {
            $inspection = $this->inspect($query, $connectionName);

            return [
                'valid' => true,
                'columns' => $inspection['columns'],
            ];
        } catch (\Throwable $exception) {
            return [
                'valid' => false,
                'columns' => [],
                'message' => $this->normalizeExceptionMessage($exception),
            ];
        }
    }

    /**
     * Inspect the query by executing a zero-row wrapper on the active connection.
     *
     * @param string $query
     * @param string|null $connectionName
     * @return array{columns:array<int, string>}
     */
    protected function inspect(string $query, ?string $connectionName = null): array
    {
        $query = $this->runtimeVariableParser->parse($query);
        $query = $this->replaceRoutePlaceholders((string) $query);
        $normalizedQuery = $this->normalizeQuery((string) $query);

        if ($normalizedQuery === '') {
            throw new \InvalidArgumentException('Query is required.');
        }

        if (! DataSource::validateQuery($normalizedQuery)) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed.');
        }

        $connection = DatabaseConnection::connection($connectionName);
        $sql = sprintf('SELECT * FROM (%s) AS query_validation WHERE 1=0', $normalizedQuery);

        $statement = $connection->getPdo()->prepare($sql);
        $statement->execute();

        $columns = $this->extractColumnsFromStatement($statement);

        return ['columns' => $columns];
    }

    /**
     * @param \PDOStatement $statement
     * @return array<int, string>
     */
    protected function extractColumnsFromStatement(\PDOStatement $statement): array
    {
        $columns = [];
        $count = $statement->columnCount();

        for ($index = 0; $index < $count; $index++) {
            $meta = $statement->getColumnMeta($index);

            if (! is_array($meta)) {
                continue;
            }

            $name = $meta['name'] ?? $meta['orgname'] ?? $meta['column_name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $columns[] = $name;
        }

        return array_values(array_unique($columns));
    }

    protected function normalizeQuery(string $query): string
    {
        $query = trim($query);

        return trim((string) preg_replace('/;+\s*$/', '', $query));
    }

    /**
     * Replace single-brace route placeholders with a safe dummy literal so
     * query validation/extraction can still parse the SQL shape.
     *
     * @param string $query
     * @return string
     */
    protected function replaceRoutePlaceholders(string $query): string
    {
        return preg_replace_callback(
            '/(?<!\{)\{([A-Za-z_][A-Za-z0-9_]*)\}(?!\})/',
            static function (array $matches): string {
                return '1';
            },
            $query
        ) ?? $query;
    }

    protected function normalizeExceptionMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'Invalid query.';
    }
}
