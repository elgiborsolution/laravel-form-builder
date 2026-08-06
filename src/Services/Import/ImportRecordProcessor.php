<?php

namespace ESolution\DataSources\Services\Import;

use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Models\ImportConfig;
use ESolution\DataSources\Models\ImportTable;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

class ImportRecordProcessor
{
    public function __construct(
        protected ImportTemplateReader $templateReader,
        protected DynamicVariableParser $runtimeVariableParser,
        protected ?DatabaseMetadataProvider $databaseMetadataProvider = null
    ) {
        $this->databaseMetadataProvider ??= new DatabaseMetadataProvider();
    }

    public function process(ImportConfig $config, Request $request): array
    {
        $uploadedFile = $request->file('file');

        if ($uploadedFile === null) {
            throw ValidationException::withMessages([
                'file' => ['The import file is required.'],
            ]);
        }

        $analysis = $this->templateReader->readRows($uploadedFile, $this->resolveSelectedSheet($config));
        $metadata = $analysis['metadata'];
        $rows = $analysis['rows'];

        $this->validateTemplateCompatibility($config, $metadata);

        $rows = $this->applyBeforeExecuteHook($config, $rows, $request);
        $connection = DatabaseConnection::connection();
        $connection->beginTransaction();

        $summary = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        try {
            foreach ($rows as $rowInfo) {
                $rowNumber = (int) ($rowInfo['row'] ?? 0);
                $rowData = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
                $result = $this->processRow($config, $connection, $rowData, $rowNumber, $request);

                $summary['success'] += $result['success'];
                $summary['failed'] += $result['failed'];
                foreach ($result['errors'] as $error) {
                    $summary['errors'][] = $error;
                }
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $this->applyAfterExecuteHook($config, $request, $summary);
    }

    protected function processRow(
        ImportConfig $config,
        ConnectionInterface $connection,
        array $rowData,
        int $rowNumber,
        Request $request
    ): array {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $parentTable = $config->parentTable;

        if ($parentTable === null) {
            return $result;
        }

        $mappedParent = $this->buildMappedRows($parentTable->data_params ?? [], $rowData, ['row' => $rowData]);

        foreach ($mappedParent as $mappedParentRow) {
            $parentPersisted = $this->upsertTableRow(
                $connection,
                $parentTable,
                $mappedParentRow,
                $config->import_mode,
                null
            );

            if ($parentPersisted === null) {
                $result['failed']++;
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'column' => $this->firstColumnName($mappedParentRow),
                    'message' => 'Failed to persist parent row.',
                ];
                continue;
            }

            $result['success']++;

            foreach ($config->childTables as $childTable) {
                $childRows = $this->buildMappedRows(
                    $childTable->data_params ?? [],
                    $rowData,
                    [
                        'row' => $rowData,
                        'parent' => $parentPersisted,
                    ]
                );

                $childResult = $this->persistChildRows(
                    $connection,
                    $childTable,
                    $childRows,
                    $parentPersisted,
                    $config->import_mode,
                    $request,
                    $rowNumber
                );

                $result['success'] += $childResult['success'];
                $result['failed'] += $childResult['failed'];
                $result['errors'] = array_merge($result['errors'], $childResult['errors']);
            }
        }

        return $result;
    }

    protected function persistChildRows(
        ConnectionInterface $connection,
        ImportTable $table,
        array $rows,
        array $parentRecord,
        string $importMode,
        Request $request,
        int $rowNumber
    ): array {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $foreignKey = trim((string) ($table->foreign_key ?? ''));
        if ($foreignKey === '') {
            return $result;
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $row[$foreignKey] = $this->resolveParentValue($parentRecord, $foreignKey);
            $normalizedRows[] = $row;
        }

        $incomingIdentifiers = [];
        $lookupKey = $this->resolveLookupKey($table);
        foreach ($normalizedRows as $childRow) {
            $persisted = $this->upsertTableRow($connection, $table, $childRow, $importMode, $lookupKey);
            if ($persisted === null) {
                $result['failed']++;
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'column' => $this->firstColumnName($childRow),
                    'message' => 'Failed to persist child row.',
                ];
                continue;
            }

            $result['success']++;
            if ($lookupKey !== '' && array_key_exists($lookupKey, $persisted)) {
                $incomingIdentifiers[] = $persisted[$lookupKey];
            }
        }

        if (
            strtoupper((string) ($table->missing_child_strategy ?? 'KEEP_EXISTING')) === 'DELETE_MISSING'
            && $lookupKey !== ''
            && $incomingIdentifiers !== []
        ) {
            $query = $connection->table($table->table_name)
                ->where($foreignKey, $this->resolveParentValue($parentRecord, $foreignKey));

            $existingIds = $query->pluck($lookupKey)->all();
            $missing = array_values(array_diff($existingIds, $incomingIdentifiers));

            if ($missing !== []) {
                $deleteQuery = $connection->table($table->table_name)
                    ->where($foreignKey, $this->resolveParentValue($parentRecord, $foreignKey))
                    ->whereIn($lookupKey, $missing);

                if ($table->use_soft_delete && $this->tableHasDeletedAt($table->table_name)) {
                    $deleteQuery->update(['deleted_at' => now()]);
                } else {
                    $deleteQuery->delete();
                }
            }
        }

        return $result;
    }

    protected function upsertTableRow(
        ConnectionInterface $connection,
        ImportTable $table,
        array $row,
        string $importMode,
        ?string $lookupKey = null
    ): ?array {
        $lookupKey = trim((string) ($lookupKey ?? $this->resolveLookupKey($table)));
        $payload = $this->normalizePersistedRow($row);
        $tableName = trim((string) $table->table_name);

        if ($tableName === '') {
            return null;
        }

        $rules = $this->buildValidationRules($tableName, $table, $payload, $lookupKey);
        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            return null;
        }

        $existing = null;
        if ($lookupKey !== '' && array_key_exists($lookupKey, $payload) && $payload[$lookupKey] !== null && $payload[$lookupKey] !== '') {
            $existing = $connection->table($tableName)
                ->where($lookupKey, $payload[$lookupKey])
                ->first();
        }

        $mode = strtoupper(trim($importMode));
        $shouldUpdate = $mode === 'UPDATE' || ($mode === 'UPSERT' && $existing !== null);
        $shouldInsert = $mode === 'INSERT' || ($mode === 'UPSERT' && $existing === null);

        if ($mode === 'UPDATE' && $existing === null) {
            return null;
        }

        if ($shouldUpdate && $existing !== null) {
            $connection->table($tableName)
                ->where($lookupKey !== '' ? $lookupKey : 'id', $existing->{$lookupKey !== '' ? $lookupKey : 'id'})
                ->update($payload);

            return (array) $connection->table($tableName)
                ->where($lookupKey !== '' ? $lookupKey : 'id', $existing->{$lookupKey !== '' ? $lookupKey : 'id'})
                ->first();
        }

        if ($shouldInsert) {
            if ($lookupKey !== '' && array_key_exists($lookupKey, $payload) && $payload[$lookupKey] !== null && $payload[$lookupKey] !== '') {
                $connection->table($tableName)->insert($payload);
                return $payload;
            }

            $insertedId = $connection->table($tableName)->insertGetId($payload);

            $primaryKey = $table->primary_key ?: 'id';
            return (array) $connection->table($tableName)->where($primaryKey, $insertedId)->first();
        }

        return null;
    }

    protected function buildValidationRules(string $tableName, ImportTable $table, array $payload, string $lookupKey): array
    {
        $rules = [];
        $definitions = is_array($table->data_params ?? null) ? $table->data_params : [];

        foreach ($definitions as $column => $mapping) {
            $descriptor = $this->normalizeMappingDescriptor($mapping, is_string($column) ? $column : null);
            $targetColumn = trim((string) ($descriptor['column'] ?? $column));
            if ($targetColumn === '') {
                continue;
            }

            $fieldRules = [];
            if (! empty($descriptor['required'])) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            $type = strtolower(trim((string) ($descriptor['type'] ?? '')));
            if (in_array($type, ['numeric', 'integer'], true)) {
                $fieldRules[] = 'numeric';
            } elseif ($type === 'email') {
                $fieldRules[] = 'email';
            } elseif ($type === 'date') {
                $fieldRules[] = 'date';
            }

            if (! empty($descriptor['validation_rules']) && is_string($descriptor['validation_rules'])) {
                $fieldRules = array_merge($fieldRules, array_filter(array_map('trim', explode('|', $descriptor['validation_rules']))));
            }

            if (! empty($descriptor['unique'])) {
                $unique = Rule::unique($tableName, $targetColumn);
                if ($lookupKey !== '' && array_key_exists($lookupKey, $payload) && $payload[$lookupKey] !== null && $payload[$lookupKey] !== '') {
                    $unique = $unique->ignore($payload[$lookupKey], $lookupKey);
                }
                $fieldRules[] = $unique;
            }

            $rules[$targetColumn] = $fieldRules;
        }

        return $rules;
    }

    protected function buildMappedRows(array $dataParams, array $rowData, array $context = []): array
    {
        if ($dataParams === []) {
            return [$rowData];
        }

        $staticRow = [];
        $loopColumns = [];

        foreach ($dataParams as $column => $mapping) {
            $descriptor = $this->normalizeMappingDescriptor($mapping, is_string($column) ? $column : null);
            $targetColumn = trim((string) ($descriptor['column'] ?? $column));
            if ($targetColumn === '') {
                continue;
            }

            $resolved = $this->resolveMappedValue($descriptor['value'] ?? null, $rowData, $context);
            $resolved = $this->normalizeImportedValue($resolved);

            if (($descriptor['array_handling'] ?? 'RAW_VALUE') === 'LOOP_INSERT' && is_array($resolved)) {
                $loopColumns[$targetColumn] = $resolved;
                continue;
            }

            $staticRow[$targetColumn] = $resolved;
        }

        if ($loopColumns === []) {
            return [$this->resolveRuntimeVariables($staticRow)];
        }

        $rows = [[]];
        foreach ($loopColumns as $column => $values) {
            $nextRows = [];
            foreach ($rows as $row) {
                foreach ($values as $value) {
                    $nextRows[] = array_merge($row, [$column => $value]);
                }
            }
            $rows = $nextRows;
        }

        foreach ($rows as &$row) {
            $row = $this->resolveRuntimeVariables(array_merge($staticRow, $row));
        }
        unset($row);

        return $rows;
    }

    protected function resolveMappedValue(mixed $value, array $rowData, array $context): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        if (str_contains($trimmed, '{{')) {
            return $this->runtimeVariableParser->parse($trimmed);
        }

        $contextValue = data_get($context, $trimmed, '__import_missing__');
        if ($contextValue !== '__import_missing__') {
            return $contextValue;
        }

        $rowValue = data_get($rowData, $trimmed, '__import_missing__');
        if ($rowValue !== '__import_missing__') {
            return $rowValue;
        }

        return $value;
    }

    protected function resolveRuntimeVariables(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = $this->runtimeVariableParser->parse($value);
        }

        return $row;
    }

    protected function normalizeImportedValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
                || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
                try {
                    return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    return $trimmed;
                }
            }
        }

        return $value;
    }

    protected function normalizeRowData(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = $this->normalizeImportedValue($value);
        }

        return $row;
    }

    protected function normalizePersistedRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $normalized[$key] = $encoded === false ? '[]' : $encoded;
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $trimmed === '' ? null : $trimmed;
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    protected function normalizeMappingDescriptor(mixed $mapping, ?string $column = null): array
    {
        if (! is_array($mapping)) {
            return [
                'column' => $column,
                'value' => $mapping,
                'array_handling' => 'RAW_VALUE',
            ];
        }

        return [
            'column' => $column ?? ($mapping['column'] ?? null),
            'value' => $mapping['value'] ?? $mapping['path'] ?? $mapping['source'] ?? null,
            'required' => (bool) ($mapping['required'] ?? false),
            'unique' => (bool) ($mapping['unique'] ?? false),
            'type' => $mapping['type'] ?? null,
            'validation_rules' => $mapping['validation_rules'] ?? $mapping['rules'] ?? null,
            'array_handling' => strtoupper((string) ($mapping['array_handling'] ?? $mapping['arrayHandling'] ?? 'RAW_VALUE')) === 'LOOP_INSERT'
                ? 'LOOP_INSERT'
                : 'RAW_VALUE',
        ];
    }

    protected function resolveLookupKey(ImportTable $table): string
    {
        $lookup = trim((string) ($table->child_update_key ?? ''));
        if ($lookup !== '') {
            return $lookup;
        }

        $lookup = trim((string) ($table->key_update_delete ?? ''));
        if ($lookup !== '') {
            return $lookup;
        }

        $lookup = trim((string) ($table->primary_key ?? ''));
        if ($lookup !== '') {
            return $lookup;
        }

        return $this->resolveTablePrimaryKeyName($table->table_name);
    }

    protected function resolveTablePrimaryKeyName(string $tableName): string
    {
        if ($this->databaseMetadataProvider === null || trim($tableName) === '') {
            return 'id';
        }

        try {
            $indexes = $this->databaseMetadataProvider->listIndexes($tableName, null);
            foreach ($indexes as $index) {
                if (! empty($index['primary']) && ! empty($index['column'])) {
                    return trim((string) $index['column']);
                }
            }
        } catch (Throwable) {
        }

        return 'id';
    }

    protected function resolveParentValue(array $parentRecord, string $key): mixed
    {
        return Arr::get($parentRecord, $key);
    }

    protected function firstColumnName(array $row): string
    {
        return (string) array_key_first($row);
    }

    protected function tableHasDeletedAt(string $tableName): bool
    {
        if ($this->databaseMetadataProvider === null) {
            return false;
        }

        try {
            $columns = $this->databaseMetadataProvider->listColumns($tableName, null);
            foreach ($columns as $column) {
                if (($column['name'] ?? null) === 'deleted_at') {
                    return true;
                }
            }
        } catch (Throwable) {
        }

        return false;
    }

    protected function validateTemplateCompatibility(ImportConfig $config, array $metadata): void
    {
        $stored = is_array($config->template_metadata ?? null) ? $config->template_metadata : [];
        $expectedHeaders = array_values(array_filter((array) ($stored['column_headers'] ?? []), static fn ($value) => trim((string) $value) !== ''));
        $actualHeaders = array_values(array_filter((array) ($metadata['column_headers'] ?? []), static fn ($value) => trim((string) $value) !== ''));

        if ($expectedHeaders === []) {
            return;
        }

        $missing = array_values(array_diff($expectedHeaders, $actualHeaders));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => ['Template header mismatch: missing ' . implode(', ', $missing)],
            ]);
        }
    }

    protected function applyBeforeExecuteHook(ImportConfig $config, array $rows, Request $request): array
    {
        $hook = trim((string) ($config->before_execute_hook ?? ''));
        if ($hook === '' || ! class_exists($hook)) {
            return $rows;
        }

        $instance = app($hook);
        if (method_exists($instance, 'handle')) {
            $payload = $rows;
            $instance->handle($payload, $config, $request);
            return is_array($payload) ? $payload : $rows;
        }

        return $rows;
    }

    protected function applyAfterExecuteHook(ImportConfig $config, Request $request, array $summary): array
    {
        $hook = trim((string) ($config->after_execute_hook ?? ''));
        if ($hook === '' || ! class_exists($hook)) {
            return $summary;
        }

        $instance = app($hook);
        if (method_exists($instance, 'handle')) {
            $result = $instance->handle($request, $config, $summary);
            return is_array($result) ? $result : $summary;
        }

        return $summary;
    }

    protected function resolveSelectedSheet(ImportConfig $config): ?string
    {
        $metadata = is_array($config->template_metadata ?? null) ? $config->template_metadata : [];
        $selected = trim((string) ($metadata['selected_sheet'] ?? ''));
        return $selected !== '' ? $selected : null;
    }
}
