<?php

namespace ESolution\DataSources\Services\Import;

use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Contracts\ImportBeforeExecuteHookInterface;
use ESolution\DataSources\Models\ImportConfig;
use ESolution\DataSources\Models\ImportTable;
use ESolution\DataSources\Services\Runtime\DynamicVariableParser;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
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
        protected ?DatabaseMetadataProvider $databaseMetadataProvider = null,
        protected ?ExecutionConnectionResolver $executionConnectionResolver = null
    ) {
        $this->databaseMetadataProvider ??= new DatabaseMetadataProvider();
        $this->executionConnectionResolver ??= new ExecutionConnectionResolver();
    }

    public function process(ImportConfig $config, Request $request): array
    {
        $uploadedFile = $request->file('file');

        if ($uploadedFile === null) {
            throw ValidationException::withMessages([
                'file' => ['The import file is required.'],
            ]);
        }

        // Match API Builder: resolve once and reuse this request connection.
        $connectionName = $this->executionConnectionResolver->resolve($request);
        $connection = $this->executionConnectionResolver->connection($request);

        $workbook = $this->templateReader->readAllRows($uploadedFile);
        $worksheets = $workbook['worksheets'];
        $parentSheet = $this->resolveParentWorksheet($config, $workbook['metadata'] ?? []);

        $this->validateWorkbookCompatibility($config, $worksheets, $parentSheet);

        $dataset = $this->buildNormalizedImportDataset($config, $worksheets, $parentSheet);
        $this->applyBeforeExecuteHook($config, $dataset, $request);

        // Keep the legacy single-sheet persistence behavior when no child worksheet is configured.
        if (! $this->usesSeparateChildWorksheets($config)) {
            return $this->processSingleSheet($config, $request, $dataset['parent'], $connection, $connectionName);
        }

        $parentRows = $dataset['parent'];
        $connection->beginTransaction();

        $summary = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        try {
            $parentLookup = [];
            foreach ($parentRows as $rowInfo) {
                $rowNumber = (int) ($rowInfo['row'] ?? 0);
                $rowData = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
                $result = $this->processParentRow($config, $connection, $connectionName, $rowData, $rowNumber, $parentLookup);

                $summary['success'] += $result['success'];
                $summary['failed'] += $result['failed'];
                foreach ($result['errors'] as $error) {
                    $summary['errors'][] = $error;
                }
            }

            foreach ($config->childTables as $childIndex => $childTable) {
                $childRows = $dataset['children'][$childIndex] ?? [];
                $this->processChildWorksheet($config, $connection, $connectionName, $childTable, $childRows, $parentLookup, $request, $summary);
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $this->applyAfterExecuteHook($config, $request, $summary);
    }

    protected function processSingleSheet(
        ImportConfig $config,
        Request $request,
        array $rows,
        ConnectionInterface $connection,
        string $connectionName
    ): array
    {
        $connection->beginTransaction();
        $summary = ['success' => 0, 'failed' => 0, 'errors' => []];

        try {
            foreach ($rows as $rowInfo) {
                $result = $this->processRow(
                    $config,
                    $connection,
                    $this->normalizeRowData((array) ($rowInfo['data'] ?? [])),
                    (int) ($rowInfo['row'] ?? 0),
                    $request,
                    $connectionName
                );
                $summary['success'] += $result['success'];
                $summary['failed'] += $result['failed'];
                $summary['errors'] = array_merge($summary['errors'], $result['errors']);
            }
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $this->applyAfterExecuteHook($config, $request, $summary);
    }

    protected function processParentRow(ImportConfig $config, ConnectionInterface $connection, string $connectionName, array $rowData, int $rowNumber, array &$parentLookup): array
    {
        $result = ['success' => 0, 'failed' => 0, 'errors' => []];
        $parentTable = $config->parentTable;
        if ($parentTable === null) {
            return $result;
        }

        $matchColumn = trim((string) ($parentTable->parent_match_column ?? ''));
        $matchValue = $matchColumn !== '' ? ($rowData[$matchColumn] ?? null) : null;
        if ($matchColumn !== '' && ($matchValue === null || $matchValue === '')) {
            return [
                'success' => 0,
                'failed' => 1,
                'errors' => [[
                    'row' => $rowNumber,
                    'column' => $matchColumn,
                    'message' => 'Parent Match Column value is required.',
                ]],
            ];
        }
        foreach ($this->buildMappedRows($parentTable->data_params ?? [], $rowData, ['row' => $rowData], false) as $mappedRow) {
            $persisted = $this->upsertTableRow($connection, $connectionName, $parentTable, $mappedRow, $config->import_mode, null);
            if ($persisted === null) {
                $result['failed']++;
                $result['errors'][] = ['row' => $rowNumber, 'column' => $this->firstColumnName($mappedRow), 'message' => 'Failed to persist parent row.'];
                continue;
            }

            $result['success']++;
            if ($matchColumn !== '' && $matchValue !== null && $matchValue !== '') {
                $parentLookup[(string) $matchValue] = $persisted;
            }
        }

        return $result;
    }

    protected function processChildWorksheet(ImportConfig $config, ConnectionInterface $connection, string $connectionName, ImportTable $childTable, array $rows, array $parentLookup, Request $request, array &$summary): void
    {
        $matchColumn = trim((string) ($childTable->child_match_column ?? ''))
            ?: trim((string) ($childTable->parent_match_column ?? ''));
        $grouped = [];
        foreach ($rows as $rowInfo) {
            $rowNumber = (int) ($rowInfo['row'] ?? 0);
            $rowData = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
            $matchValue = $rowData[$matchColumn] ?? null;
            $parent = $matchColumn !== '' && $matchValue !== null ? ($parentLookup[(string) $matchValue] ?? null) : null;
            if ($parent === null) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'row' => $rowNumber,
                    'column' => $matchColumn,
                    'message' => 'Parent not found' . ($matchValue !== null ? ' for ' . $matchColumn . '=' . $matchValue . '.' : '.'),
                ];
                continue;
            }
            $parentKey = (string) ($this->resolveParentValue($parent, $childTable->foreign_key ?: 'id') ?? $matchValue);
            $grouped[$parentKey]['parent'] = $parent;
            $grouped[$parentKey]['rows'][] = ['row' => $rowNumber, 'data' => $rowData];
        }

        foreach ($grouped as $group) {
            $mappedRows = [];
            $rowNumbers = [];
            foreach ($group['rows'] as $rowInfo) {
                $rowNumbers[] = $rowInfo['row'];
                $mappedRows = array_merge($mappedRows, $this->buildMappedRows(
                    $childTable->data_params ?? [],
                    $rowInfo['data'],
                    ['row' => $rowInfo['data'], 'parent' => $group['parent']],
                    false
                ));
            }
            $result = $this->persistChildRows($connection, $connectionName, $childTable, $mappedRows, $group['parent'], $config->import_mode, $request, $rowNumbers[0] ?? 0);
            $summary['success'] += $result['success'];
            $summary['failed'] += $result['failed'];
            $summary['errors'] = array_merge($summary['errors'], $result['errors']);
        }
    }

    protected function processRow(
        ImportConfig $config,
        ConnectionInterface $connection,
        string $connectionName,
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

        $mappedParent = $this->buildMappedRows($parentTable->data_params ?? [], $rowData, ['row' => $rowData], false);

        foreach ($mappedParent as $mappedParentRow) {
            $parentPersisted = $this->upsertTableRow(
                $connection,
                $connectionName,
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
                    ],
                    false
                );

                $childResult = $this->persistChildRows(
                    $connection,
                    $connectionName,
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
        string $connectionName,
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
            // Child foreign keys normally differ from the parent primary-key name
            // (for example customer_id -> id), so resolve the parent key explicitly.
            $row[$foreignKey] = $this->resolveForeignKeyValue($parentRecord, $table, $foreignKey);
            $normalizedRows[] = $row;
        }

        $incomingIdentifiers = [];
        $lookupKey = $this->resolveLookupKey($table, $connectionName);
        foreach ($normalizedRows as $childRow) {
            $persisted = $this->upsertTableRow($connection, $connectionName, $table, $childRow, $importMode, $lookupKey);
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
            $tableName = $this->normalizeTableName($connection, (string) $table->table_name);
            $query = $connection->table($tableName)
                ->where($foreignKey, $this->resolveForeignKeyValue($parentRecord, $table, $foreignKey));

            $existingIds = $query->pluck($lookupKey)->all();
            $missing = array_values(array_diff($existingIds, $incomingIdentifiers));

            if ($missing !== []) {
                $deleteQuery = $connection->table($tableName)
                    ->where($foreignKey, $this->resolveForeignKeyValue($parentRecord, $table, $foreignKey))
                    ->whereIn($lookupKey, $missing);

                if ($table->use_soft_delete && $this->tableHasDeletedAt($tableName, $connectionName)) {
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
        string $connectionName,
        ImportTable $table,
        array $row,
        string $importMode,
        ?string $lookupKey = null
    ): ?array {
        $lookupKey = trim((string) ($lookupKey ?? $this->resolveLookupKey($table, $connectionName)));
        $payload = $this->normalizePersistedRow($row);
        $tableName = $this->normalizeTableName($connection, (string) $table->table_name);

        if ($tableName === '') {
            return null;
        }

        $rules = $this->buildValidationRules($tableName, $table, $payload, $lookupKey, $connectionName);
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
                $persisted = $connection->table($tableName)->where($lookupKey, $payload[$lookupKey])->first();
                return $persisted !== null ? (array) $persisted : $payload;
            }

            $insertedId = $connection->table($tableName)->insertGetId($payload);

            $primaryKey = $table->primary_key ?: 'id';
            return (array) $connection->table($tableName)->where($primaryKey, $insertedId)->first();
        }

        return null;
    }

    protected function buildValidationRules(string $tableName, ImportTable $table, array $payload, string $lookupKey, string $connectionName): array
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
                $unique = Rule::unique($tableName, $targetColumn)->on($connectionName);
                if ($lookupKey !== '' && array_key_exists($lookupKey, $payload) && $payload[$lookupKey] !== null && $payload[$lookupKey] !== '') {
                    $unique = $unique->ignore($payload[$lookupKey], $lookupKey);
                }
                $fieldRules[] = $unique;
            }

            $rules[$targetColumn] = $fieldRules;
        }

        return $rules;
    }

    protected function buildMappedRows(array $dataParams, array $rowData, array $context = [], bool $allowLoopInsert = true): array
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

            if ($allowLoopInsert && ($descriptor['array_handling'] ?? 'RAW_VALUE') === 'LOOP_INSERT' && is_array($resolved)) {
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

        $isRuntimeVariable = preg_match('/^\{\{\s*([^}]+?)\s*\}\}$/', $trimmed, $matches) === 1;
        if ($isRuntimeVariable) {
            $contextValue = data_get($context, trim($matches[1]), '__import_missing__');
            if ($contextValue !== '__import_missing__') {
                return $contextValue;
            }
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

        // Preserve compatibility with existing composite runtime expressions,
        // while exact source selections above remain unambiguous.
        if (str_contains($trimmed, '{{')) {
            return $this->runtimeVariableParser->parse($trimmed);
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

    protected function resolveLookupKey(ImportTable $table, ?string $connectionName = null): string
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

        return $this->resolveTablePrimaryKeyName($table->table_name, $connectionName);
    }

    protected function resolveTablePrimaryKeyName(string $tableName, ?string $connectionName = null): string
    {
        if ($this->databaseMetadataProvider === null || trim($tableName) === '') {
            return 'id';
        }

        try {
            $indexes = $this->databaseMetadataProvider->listIndexes($tableName, $connectionName);
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

    protected function normalizeTableName(ConnectionInterface $connection, string $tableName): string
    {
        $tableName = trim($tableName);
        $prefix = trim((string) $connection->getTablePrefix());

        if ($prefix !== '' && str_starts_with($tableName, $prefix)) {
            return substr($tableName, strlen($prefix));
        }

        return $tableName;
    }

    protected function tableHasDeletedAt(string $tableName, ?string $connectionName = null): bool
    {
        if ($this->databaseMetadataProvider === null) {
            return false;
        }

        try {
            $columns = $this->databaseMetadataProvider->listColumns($tableName, $connectionName);
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

    protected function resolveForeignKeyValue(array $parentRecord, ImportTable $childTable, string $foreignKey): mixed
    {
        $direct = $this->resolveParentValue($parentRecord, $foreignKey);
        if ($direct !== null) {
            return $direct;
        }

        $parentPrimaryKey = trim((string) ($childTable->importConfig?->parentTable?->primary_key ?? '')) ?: 'id';
        return $this->resolveParentValue($parentRecord, $parentPrimaryKey)
            ?? $this->resolveParentValue($parentRecord, 'id');
    }

    protected function usesSeparateChildWorksheets(ImportConfig $config): bool
    {
        $parentSheet = trim((string) ($config->parentTable?->worksheet ?? ''));
        foreach ($config->childTables as $childTable) {
            $childSheet = trim((string) ($childTable->worksheet ?? ''));
            if ($childSheet !== '' && $childSheet !== $parentSheet) {
                return true;
            }
        }

        return false;
    }

    protected function resolveParentWorksheet(ImportConfig $config, array $metadata): string
    {
        $configured = trim((string) ($config->parentTable?->worksheet ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return trim((string) ($metadata['selected_sheet'] ?? $this->resolveSelectedSheet($config) ?? 'Sheet1')) ?: 'Sheet1';
    }

    protected function validateWorkbookCompatibility(ImportConfig $config, array $worksheets, string $parentSheet): void
    {
        if (! isset($worksheets[$parentSheet])) {
            throw ValidationException::withMessages(['file' => ['Parent worksheet "' . $parentSheet . '" does not exist.']]);
        }

        $this->validateTemplateCompatibility($config, $worksheets[$parentSheet]['metadata'] ?? []);
        $storedWorksheets = (array) (($config->template_metadata ?? [])['worksheets'] ?? []);
        $parentMatchColumn = trim((string) ($config->parentTable?->parent_match_column ?? ''));

        if ($this->usesSeparateChildWorksheets($config) && $parentMatchColumn === '') {
            throw ValidationException::withMessages(['parent_table.parent_match_column' => ['Parent Match Column is required for multi-worksheet imports.']]);
        }

        if ($parentMatchColumn !== '') {
            $this->assertWorksheetHasColumn($worksheets[$parentSheet], $parentMatchColumn, 'Parent Match Column');
        }

        foreach ($config->childTables as $childTable) {
            $childSheet = trim((string) ($childTable->worksheet ?? '')) ?: $parentSheet;
            if (! isset($worksheets[$childSheet])) {
                throw ValidationException::withMessages(['file' => ['Child worksheet "' . $childSheet . '" does not exist.']]);
            }

            $expectedHeaders = (array) ($storedWorksheets[$childSheet]['column_headers'] ?? []);
            if ($expectedHeaders !== []) {
                $actualHeaders = (array) ($worksheets[$childSheet]['metadata']['column_headers'] ?? []);
                $missing = array_values(array_diff($expectedHeaders, $actualHeaders));
                if ($missing !== []) {
                    throw ValidationException::withMessages(['file' => ['Template header mismatch in worksheet "' . $childSheet . '": missing ' . implode(', ', $missing)]]);
                }
            }

            if ($childSheet !== $parentSheet) {
                $matchColumn = trim((string) ($childTable->child_match_column ?? ''))
                    ?: trim((string) ($childTable->parent_match_column ?? ''));
                if ($matchColumn === '') {
                    throw ValidationException::withMessages(['child_tables' => ['Child Match Column is required for worksheet "' . $childSheet . '".']]);
                }
                $this->assertWorksheetHasColumn($worksheets[$childSheet], $matchColumn, 'Child Match Column');
            }
        }
    }

    protected function assertWorksheetHasColumn(array $worksheet, string $column, string $label): void
    {
        $headers = (array) ($worksheet['metadata']['column_headers'] ?? []);
        if (! in_array($column, $headers, true)) {
            throw ValidationException::withMessages(['file' => [$label . ' "' . $column . '" does not exist in worksheet "' . ($worksheet['metadata']['selected_sheet'] ?? '') . '".']]);
        }
    }

    protected function buildNormalizedImportDataset(ImportConfig $config, array $worksheets, string $parentSheet): array
    {
        $dataset = [
            'parent' => $this->normalizeImportedRows($worksheets[$parentSheet]['rows'] ?? []),
            'children' => [],
        ];

        foreach ($config->childTables as $childIndex => $childTable) {
            $childSheet = trim((string) ($childTable->worksheet ?? '')) ?: $parentSheet;
            $dataset['children'][$childIndex] = $this->normalizeImportedRows($worksheets[$childSheet]['rows'] ?? []);
        }

        return $dataset;
    }

    protected function normalizeImportedRows(array $rows): array
    {
        foreach ($rows as $index => $rowInfo) {
            $rows[$index]['data'] = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
        }

        return $rows;
    }

    protected function applyBeforeExecuteHook(ImportConfig $config, array &$data, Request $request): void
    {
        $hook = trim((string) ($config->before_execute_hook ?? ''));
        if ($hook === '' || ! class_exists($hook)) {
            return;
        }

        $instance = app($hook);
        if (method_exists($instance, 'handle')) {
            if ($instance instanceof ImportBeforeExecuteHookInterface) {
                $instance->handle($data, $config, $request);
                return;
            }

            // Preserve compatibility with older Import hooks that were called
            // once per worksheet instead of receiving the complete dataset.
            foreach (['parent', 'children'] as $section) {
                if ($section === 'parent') {
                    $instance->handle($data['parent'], $config, $request);
                    continue;
                }

                foreach ($data['children'] as &$rows) {
                    $instance->handle($rows, $config, $request);
                }
                unset($rows);
            }
        }
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
