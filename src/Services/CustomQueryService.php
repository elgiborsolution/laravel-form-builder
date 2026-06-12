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
    public function validate(string $query, ?string $connectionName = null, array $customParameters = []): array
    {
        try {
            $this->inspect($query, $connectionName, $customParameters);

            return [
                'valid' => true,
                'custom_parameters' => $this->syncCustomParameters($customParameters, $query),
            ];
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
    public function extractColumns(string $query, ?string $connectionName = null, array $customParameters = []): array
    {
        try {
            $inspection = $this->inspect($query, $connectionName, $customParameters);

            return [
                'valid' => true,
                'columns' => $inspection['columns'],
                'custom_parameters' => $inspection['custom_parameters'],
            ];
        } catch (\Throwable $exception) {
            return [
                'valid' => false,
                'columns' => [],
                'custom_parameters' => [],
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
    public function validateAndExtract(string $query, ?string $connectionName = null, array $customParameters = []): array
    {
        try {
            $inspection = $this->inspect($query, $connectionName, $customParameters);

            return [
                'valid' => true,
                'columns' => $inspection['columns'],
                'custom_parameters' => $inspection['custom_parameters'],
            ];
        } catch (\Throwable $exception) {
            return [
                'valid' => false,
                'columns' => [],
                'custom_parameters' => [],
                'message' => $this->normalizeExceptionMessage($exception),
            ];
        }
    }

    /**
     * Inspect the query by executing a zero-row wrapper on the active connection.
     *
     * @param string $query
     * @param string|null $connectionName
     * @return array{columns:array<int, string>,custom_parameters:array<int, array<string, mixed>>}
     */
    protected function inspect(string $query, ?string $connectionName = null, array $customParameters = []): array
    {
        $query = $this->runtimeVariableParser->parse($query);
        $query = $this->replaceRoutePlaceholders((string) $query);
        $syncedCustomParameters = $this->syncCustomParameters($customParameters, (string) $query);
        $query = $this->applyConditionalBlocks((string) $query, []);
        $query = $this->replaceCustomPlaceholders((string) $query, $syncedCustomParameters);
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

        return [
            'columns' => $columns,
            'custom_parameters' => $syncedCustomParameters,
        ];
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
     * Resolve conditional SQL blocks using the set of available custom parameters.
     *
     * @param string $query
     * @param array<int, string> $availableCustomParameters
     * @return string
     */
    protected function applyConditionalBlocks(string $query, array $availableCustomParameters = []): string
    {
        $available = array_fill_keys($availableCustomParameters, true);

        return preg_replace_callback(
            '/\[\[\s*(.*?)\s*\]\]/s',
            function (array $matches) use ($available): string {
                $inner = (string) ($matches[1] ?? '');
                $parameterNames = $this->extractCustomParameterNames($inner);

                foreach ($parameterNames as $name) {
                    if (! array_key_exists($name, $available)) {
                        return '';
                    }
                }

                return $inner;
            },
            $query
        ) ?? $query;
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

    /**
     * Replace custom parameter placeholders with safe dummy values for validation.
     *
     * @param string $query
     * @param array<int, mixed> $customParameters
     * @return string
     */
    protected function replaceCustomPlaceholders(string $query, array $customParameters = []): string
    {
        $definitions = $this->normalizeCustomParameters($customParameters);
        $names = $this->extractCustomParameterNames($query);

        foreach ($names as $name) {
            if (! array_key_exists($name, $definitions)) {
                throw new \InvalidArgumentException("Custom parameter \"{$name}\" is not defined.");
            }
        }

        return preg_replace_callback(
            '/(?<!:):([A-Za-z_][A-Za-z0-9_]*)\b/',
            function (array $matches) use ($definitions): string {
                $name = $matches[1];
                $definition = $definitions[$name] ?? ['type' => 'string'];

                return $this->dummyValueForType((string) ($definition['type'] ?? 'string'));
            },
            $query
        ) ?? $query;
    }

    /**
     * Extract custom parameter names referenced in a query.
     *
     * @param string $query
     * @return array<int, string>
     */
    protected function extractCustomParameterNames(string $query): array
    {
        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)\b/', $query, $matches);

        $names = $matches[1] ?? [];

        return array_values(array_unique(array_filter($names, static fn ($name) => is_string($name) && trim($name) !== '')));
    }

    /**
     * Normalize custom parameter definitions into a keyed array.
     *
     * @param array<int, mixed> $customParameters
     * @return array<string, array<string, mixed>>
     */
    public function syncCustomParameters(array $customParameters, string $query): array
    {
        $definitions = $this->normalizeCustomParameters($customParameters);
        $usedNames = $this->extractCustomParameterNames($query);
        $synced = [];

        foreach ($definitions as $name => $definition) {
            $definition['unused'] = ! in_array($name, $usedNames, true);
            $synced[$name] = $definition;
        }

        foreach ($usedNames as $name) {
            if (array_key_exists($name, $synced)) {
                $synced[$name]['unused'] = false;
                continue;
            }

            $synced[$name] = [
                'name' => $name,
                'type' => 'string',
                'required' => false,
                'default' => null,
                'description' => '',
                'unused' => false,
            ];
        }

        return array_values($synced);
    }

    protected function normalizeCustomParameters(array $customParameters): array
    {
        $normalized = [];

        foreach ($customParameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = trim((string) ($parameter['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized[$name] = [
                'name' => $name,
                'type' => $this->normalizeCustomParameterType($parameter['type'] ?? 'string'),
                'required' => (bool) ($parameter['required'] ?? false),
                'default' => $parameter['default'] ?? $parameter['default_value'] ?? null,
                'description' => is_string($parameter['description'] ?? null) ? trim((string) $parameter['description']) : '',
                'unused' => (bool) ($parameter['unused'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * Normalize a custom parameter type.
     *
     * @param mixed $value
     * @return string
     */
    protected function normalizeCustomParameterType(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['string', 'integer', 'boolean', 'date', 'float'], true)
            ? $normalized
            : 'string';
    }

    /**
     * Build a safe dummy SQL literal for query inspection.
     *
     * @param string $type
     * @return string
     */
    protected function dummyValueForType(string $type): string
    {
        return match ($this->normalizeCustomParameterType($type)) {
            'integer', 'float' => '1',
            'boolean' => '1',
            'date' => "'1970-01-01'",
            default => "'1'",
        };
    }

    protected function normalizeExceptionMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'Invalid query.';
    }
}
