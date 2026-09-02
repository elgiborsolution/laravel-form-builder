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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

class ImportRecordProcessor
{
    /** @var array{message: string, errors: array<string, array<int, string>>}|null */
    protected ?array $lastPersistenceFailure = null;

    /** @var array<string, mixed> */
    protected array $customParameterValues = [];

    /** Final replay uses the already-validated staged dataset. */
    protected bool $skipPersistenceValidation = false;

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
        return $this->processImport($config, $request);
    }

    /**
     * Execute the complete import flow in a transaction which is always rolled back.
     */
    public function test(ImportConfig $config, Request $request): array
    {
        return $this->processImport($config, $request, true);
    }

    /** Build, validate, and execute the existing flow in a rollback-only transaction. */
    public function stage(ImportConfig $config, Request $request): array
    {
        return $this->processImport($config, $request, true, true);
    }

    /** Persist a dataset prepared during staging without parsing the workbook again. */
    public function finalize(ImportConfig $config, Request $request, array $dataset): array
    {
        $connectionName = $this->executionConnectionResolver->resolve($request);
        $connection = $this->executionConnectionResolver->connection($request);
        $config->loadMissing('masterParents.children');
        $this->customParameterValues = (array) ($dataset['custom_parameters'] ?? []);
        $this->skipPersistenceValidation = true;
        $connection->beginTransaction();
        $summary = ['success' => 0, 'failed' => 0, 'errors' => [], 'masters' => []];
        try {
            foreach ($config->masterParents->values() as $index => $parentTable) {
                $masterSummary = $this->processMasterParentAndChildren($config, $parentTable, $parentTable->children->values()->all(), (array) ($dataset['masters'][$index] ?? []), $connection, $connectionName, $request);
                $summary['success'] += $masterSummary['success']; $summary['failed'] += $masterSummary['failed'];
                $summary['errors'] = array_merge($summary['errors'], $masterSummary['errors']);
                $summary['masters'][] = ['name' => $this->masterName($parentTable), 'table_name' => $parentTable->table_name, 'success' => $masterSummary['success'], 'failed' => $masterSummary['failed']];
            }
            $summary = $this->applyAfterExecuteHook($config, $request, $summary);
            $connection->commit();
            return $summary;
        } catch (Throwable $exception) { $connection->rollBack(); throw $exception; }
        finally { $this->skipPersistenceValidation = false; }
    }

    protected function processImport(ImportConfig $config, Request $request, bool $rollbackOnly = false, bool $includeDataset = false): array
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

        $config->loadMissing('masterParents.children');
        $masterParents = $config->masterParents->values()->all();

        if ($masterParents === []) {
            throw ValidationException::withMessages([
                'master_parents' => ['At least one master parent must be configured.'],
            ]);
        }

        $this->customParameterValues = $this->resolveCustomParameters($config, $request);

        $workbook = $this->templateReader->readAllRows($uploadedFile);
        $worksheets = $workbook['worksheets'];
        $this->validateWorkbookCompatibility($config, $worksheets, $masterParents);

        $dataset = $this->buildNormalizedImportDataset($config, $worksheets, $masterParents);
        $dataset['custom_parameters'] = $this->customParameterValues;
        // Keep source rows intact for the test report; hooks may mutate $dataset by reference.
        $originalDataset = $dataset;

        // Existing hooks receive the legacy keys for a single master, while
        // new hooks can use data.masters for every independent parent group.
        if (count($dataset['masters']) === 1) {
            $dataset['parent'] =& $dataset['masters'][0]['parent'];
            $dataset['children'] =& $dataset['masters'][0]['children'];
        }
        $this->applyBeforeExecuteHook($config, $dataset, $request);
        unset($dataset['parent'], $dataset['children']);

        $connection->beginTransaction();
        $summary = ['success' => 0, 'failed' => 0, 'errors' => [], 'masters' => []];

        try {
            foreach ($masterParents as $masterIndex => $parentTable) {
                $masterDataset = (array) ($dataset['masters'][$masterIndex] ?? []);
                $masterSummary = $this->processMasterParentAndChildren(
                    $config,
                    $parentTable,
                    $parentTable->children->values()->all(),
                    $masterDataset,
                    $connection,
                    $connectionName,
                    $request
                );

                $summary['success'] += $masterSummary['success'];
                $summary['failed'] += $masterSummary['failed'];
                $summary['errors'] = array_merge($summary['errors'], $masterSummary['errors']);
                $summary['masters'][] = [
                    'name' => $this->masterName($parentTable),
                    'table_name' => $parentTable->table_name,
                    'success' => $masterSummary['success'],
                    'failed' => $masterSummary['failed'],
                ];
            }

            // Staging runs Before Execute against the prepared dataset. After
            // Execute is deferred until the staged rows are actually committed.
            if (! $includeDataset) {
                $summary = $this->applyAfterExecuteHook($config, $request, $summary);
            }
            if ($rollbackOnly) {
                $connection->rollBack();
                $result = $this->buildTestResult($originalDataset, $config, $summary);
                if ($includeDataset) { $result['staging_dataset'] = $dataset; }
                return $result;
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $summary;
    }

    /**
     * Reuses the original parent-to-child processor for one independent
     * master.  No lookup or parent runtime context leaves this method.
     */
    protected function processMasterParentAndChildren(
        ImportConfig $config,
        ImportTable $parentTable,
        array $childTables,
        array $dataset,
        ConnectionInterface $connection,
        string $connectionName,
        Request $request
    ): array {
        $parentSheet = trim((string) ($dataset['worksheet'] ?? ''))
            ?: $this->resolveParentWorksheet($config, $parentTable, []);
        $summary = ['success' => 0, 'failed' => 0, 'errors' => []];
        $importMode = strtoupper(trim((string) ($parentTable->import_mode ?: $config->import_mode))) ?: 'UPSERT';

        if (! $this->usesSeparateChildWorksheets($parentTable, $childTables)) {
            foreach ((array) ($dataset['parent'] ?? []) as $rowInfo) {
                $result = $this->processRow(
                    $config,
                    $connection,
                    $connectionName,
                    $this->normalizeRowData((array) ($rowInfo['data'] ?? [])),
                    (int) ($rowInfo['row'] ?? 0),
                    $request,
                    $parentTable,
                    $childTables,
                    $importMode
                );
                $summary['success'] += $result['success'];
                $summary['failed'] += $result['failed'];
                foreach ($result['errors'] as $error) {
                    $summary['errors'][] = $this->withWorksheet($error, $parentSheet);
                }
            }

            return $this->tagMasterErrors($summary, $parentTable);
        }

        $parentLookup = [];
        foreach ((array) ($dataset['parent'] ?? []) as $rowInfo) {
            $rowNumber = (int) ($rowInfo['row'] ?? 0);
            $rowData = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
            $result = $this->processParentRow(
                $config,
                $connection,
                $connectionName,
                $rowData,
                $rowNumber,
                $parentLookup,
                $parentTable,
                $importMode
            );
            $summary['success'] += $result['success'];
            $summary['failed'] += $result['failed'];
            foreach ($result['errors'] as $error) {
                $summary['errors'][] = $this->withWorksheet($error, $parentSheet);
            }
        }

        foreach ($childTables as $childIndex => $childTable) {
            $childSheet = trim((string) ($childTable->worksheet ?? '')) ?: $parentSheet;
            $this->processChildWorksheet(
                $config,
                $connection,
                $connectionName,
                $childTable,
                (array) ($dataset['children'][$childIndex] ?? []),
                $parentLookup,
                $request,
                $summary,
                $childSheet,
                $parentTable,
                $importMode
            );
        }

        return $this->tagMasterErrors($summary, $parentTable);
    }

    protected function masterName(ImportTable $parentTable): string
    {
        return trim((string) ($parentTable->master_name ?? ''))
            ?: trim((string) ($parentTable->table_name ?? ''));
    }

    protected function tagMasterErrors(array $summary, ImportTable $parentTable): array
    {
        $name = $this->masterName($parentTable);
        foreach ($summary['errors'] as &$error) {
            $error['master'] = $name;
        }
        unset($error);

        return $summary;
    }

    protected function processSingleSheet(
        ImportConfig $config,
        Request $request,
        array $rows,
        ConnectionInterface $connection,
        string $connectionName,
        bool $rollbackOnly = false,
        array $originalDataset = [],
        string $worksheet = ''
    ): array
    {
        $connection->beginTransaction();
        $summary = ['success' => 0, 'failed' => 0, 'errors' => []];

        try {
            foreach ($rows as $rowInfo) {
                $result = $this->processRow(
                    $config,
                    $connection,
                    $connectionName,
                    $this->normalizeRowData((array) ($rowInfo['data'] ?? [])),
                    (int) ($rowInfo['row'] ?? 0),
                    $request
                );
                $summary['success'] += $result['success'];
                $summary['failed'] += $result['failed'];
                foreach ($result['errors'] as $error) {
                    $summary['errors'][] = $this->withWorksheet($error, $worksheet);
                }
            }

            if ($rollbackOnly) {
                $summary = $this->applyAfterExecuteHook($config, $request, $summary);
                $connection->rollBack();
                return $this->buildTestResult($originalDataset, $config, $summary);
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $this->applyAfterExecuteHook($config, $request, $summary);
    }

    protected function processParentRow(
        ImportConfig $config,
        ConnectionInterface $connection,
        string $connectionName,
        array $rowData,
        int $rowNumber,
        array &$parentLookup,
        ?ImportTable $configuredParentTable = null,
        ?string $importMode = null
    ): array
    {
        $result = ['success' => 0, 'failed' => 0, 'errors' => []];
        $parentTable = $configuredParentTable ?? $config->parentTable;
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
            $persisted = $this->upsertTableRow(
                $connection,
                $connectionName,
                $parentTable,
                $mappedRow,
                $importMode ?: $config->import_mode,
                null
            );
            if ($persisted === null) {
                $result['failed']++;
                $result['errors'][] = $this->persistenceFailure($rowNumber, $this->firstColumnName($mappedRow), 'Failed to persist parent row.');
                continue;
            }

            $result['success']++;
            if ($matchColumn !== '' && $matchValue !== null && $matchValue !== '') {
                $parentLookup[(string) $matchValue] = $persisted;
            } elseif (trim((string) ($parentTable->worksheet ?? '')) === '') {
                $parentLookup['__single_parent__'] = $persisted;
            }
        }

        return $result;
    }

    protected function processChildWorksheet(
        ImportConfig $config,
        ConnectionInterface $connection,
        string $connectionName,
        ImportTable $childTable,
        array $rows,
        array $parentLookup,
        Request $request,
        array &$summary,
        string $worksheet = '',
        ?ImportTable $parentTable = null,
        ?string $importMode = null
    ): void
    {
        $matchColumn = trim((string) ($childTable->child_match_column ?? ''))
            ?: trim((string) ($childTable->parent_match_column ?? ''));
        $grouped = [];
        foreach ($rows as $rowInfo) {
            $rowNumber = (int) ($rowInfo['row'] ?? 0);
            $rowData = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
            $matchValue = $rowData[$matchColumn] ?? null;
            $parent = $matchColumn !== '' && $matchValue !== null
                ? ($parentLookup[(string) $matchValue] ?? null)
                : ($parentLookup['__single_parent__'] ?? null);
            if ($parent === null) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'row' => $rowNumber,
                    'column' => $matchColumn,
                    'message' => 'Parent not found' . ($matchValue !== null ? ' for ' . $matchColumn . '=' . $matchValue . '.' : '.'),
                    'worksheet' => $worksheet,
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
            $result = $this->persistChildRows(
                $connection,
                $connectionName,
                $childTable,
                $mappedRows,
                $group['parent'],
                $importMode ?: $config->import_mode,
                $request,
                $rowNumbers,
                $parentTable
            );
            $summary['success'] += $result['success'];
            $summary['failed'] += $result['failed'];
            foreach ($result['errors'] as $error) {
                $summary['errors'][] = $this->withWorksheet($error, $worksheet);
            }
        }
    }

    protected function processRow(
        ImportConfig $config,
        ConnectionInterface $connection,
        string $connectionName,
        array $rowData,
        int $rowNumber,
        Request $request,
        ?ImportTable $configuredParentTable = null,
        ?array $configuredChildTables = null,
        ?string $importMode = null
    ): array {
        $result = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $parentTable = $configuredParentTable ?? $config->parentTable;

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
                $importMode ?: $parentTable->import_mode ?: $config->import_mode,
                null
            );

            if ($parentPersisted === null) {
                $result['failed']++;
                $result['errors'][] = $this->persistenceFailure($rowNumber, $this->firstColumnName($mappedParentRow), 'Failed to persist parent row.');
                continue;
            }

            $result['success']++;

            foreach ($configuredChildTables ?? $config->childTables->all() as $childTable) {
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
                    $importMode ?: $parentTable->import_mode ?: $config->import_mode,
                    $request,
                    $rowNumber,
                    $parentTable
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
        int|array $rowNumber,
        ?ImportTable $parentTable = null
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
            $row[$foreignKey] = $this->resolveForeignKeyValue($parentRecord, $table, $foreignKey, $parentTable);
            $normalizedRows[] = $row;
        }

        $incomingIdentifiers = [];
        $lookupKey = $this->resolveLookupKey($table, $connectionName);
        foreach ($normalizedRows as $index => $childRow) {
            $currentRowNumber = is_array($rowNumber)
                ? (int) ($rowNumber[$index] ?? $rowNumber[0] ?? 0)
                : $rowNumber;
            $persisted = $this->upsertTableRow($connection, $connectionName, $table, $childRow, $importMode, $lookupKey);
            if ($persisted === null) {
                $result['failed']++;
                $result['errors'][] = $this->persistenceFailure($currentRowNumber, $this->firstColumnName($childRow), 'Failed to persist child row.');
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
                ->where($foreignKey, $this->resolveForeignKeyValue($parentRecord, $table, $foreignKey, $parentTable));

            $existingIds = $query->pluck($lookupKey)->all();
            $missing = array_values(array_diff($existingIds, $incomingIdentifiers));

            if ($missing !== []) {
                $deleteQuery = $connection->table($tableName)
                    ->where($foreignKey, $this->resolveForeignKeyValue($parentRecord, $table, $foreignKey, $parentTable))
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
        $this->lastPersistenceFailure = null;
        $lookupKey = trim((string) ($lookupKey ?? $this->resolveLookupKey($table, $connectionName)));
        $payload = $this->normalizePersistedRow($row);
        $tableName = $this->normalizeTableName($connection, (string) $table->table_name);

        if ($tableName === '') {
            $this->lastPersistenceFailure = ['message' => 'The configured table name is empty.', 'errors' => []];
            return null;
        }

        if (! $this->skipPersistenceValidation) {
            $rules = $this->buildValidationRules($tableName, $table, $payload, $lookupKey, $connectionName);
            $validator = Validator::make($payload, $rules);
            if ($validator->fails()) {
                $this->lastPersistenceFailure = [
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()->toArray(),
                ];
                return null;
            }
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
            $this->lastPersistenceFailure = ['message' => 'No matching record was found for update.', 'errors' => []];
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
            // Request custom parameters are cast and validated once in
            // resolveCustomParameters(). Mapping-level rules must not override
            // that authoritative definition.
            if (strtolower(trim((string) ($descriptor['source_type'] ?? ''))) !== 'custom_parameter') {
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

            $resolved = $this->resolveDescriptorValue($descriptor, $rowData, $context);
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

    protected function resolveDescriptorValue(array $descriptor, array $rowData, array $context): mixed
    {
        $sourceType = strtolower(trim((string) ($descriptor['source_type'] ?? '')));
        if ($sourceType === 'custom_parameter') {
            $name = trim((string) ($descriptor['custom_parameter'] ?? $descriptor['value'] ?? ''));
            return $this->customParameterValues[$name] ?? null;
        }

        return $this->resolveMappedValue($descriptor['value'] ?? null, $rowData, $context);
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
            'source_type' => $mapping['source_type'] ?? $mapping['sourceType'] ?? null,
            'custom_parameter' => $mapping['custom_parameter'] ?? $mapping['customParameter'] ?? null,
            'array_handling' => strtoupper((string) ($mapping['array_handling'] ?? $mapping['arrayHandling'] ?? 'RAW_VALUE')) === 'LOOP_INSERT'
                ? 'LOOP_INSERT'
                : 'RAW_VALUE',
        ];
    }

    /** @return array<string, mixed> */
    protected function resolveCustomParameters(ImportConfig $config, Request $request): array
    {
        $definitions = is_array($config->custom_parameters ?? null) ? $config->custom_parameters : [];
        if ($definitions === []) {
            return [];
        }

        $input = [];
        $rules = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $name = trim((string) ($definition['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $value = $request->input($name);
            if (strtolower(trim((string) ($definition['type'] ?? ''))) === 'boolean' && is_string($value)) {
                $value = match (strtolower(trim($value))) {
                    'true', '1' => true,
                    'false', '0' => false,
                    default => $value,
                };
            }
            if (($value === null || $value === '') && array_key_exists('default', $definition)) {
                $value = $definition['default'];
                if (strtolower(trim((string) ($definition['type'] ?? ''))) === 'boolean' && is_string($value)) {
                    $value = match (strtolower(trim($value))) {
                        'true', '1' => true,
                        'false', '0' => false,
                        default => $value,
                    };
                }
            }
            $input[$name] = $value;
            $rules[$name] = $this->customParameterRules($definition);
        }

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $values = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $name = trim((string) ($definition['name'] ?? ''));
            if ($name !== '') {
                $values[$name] = $this->castCustomParameterValue($input[$name] ?? null, (string) ($definition['type'] ?? 'string'));
            }
        }

        return $values;
    }

    /** @return array<int, mixed> */
    protected function customParameterRules(array $definition): array
    {
        $rules = [! empty($definition['required']) ? 'required' : 'nullable'];
        $type = strtolower(trim((string) ($definition['type'] ?? 'string')));
        $rules[] = match ($type) {
            'integer' => 'integer',
            'decimal', 'float' => 'numeric',
            'boolean' => 'boolean',
            'date', 'datetime' => 'date',
            default => 'string',
        };
        if (! empty($definition['validation_rules']) && is_string($definition['validation_rules'])) {
            $rules = array_merge($rules, array_filter(array_map('trim', explode('|', $definition['validation_rules']))));
        }

        return $rules;
    }

    protected function castCustomParameterValue(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (strtolower(trim($type))) {
            'integer' => (int) $value,
            'decimal', 'float' => (float) $value,
            'boolean' => in_array(strtolower((string) $value), ['1', 'true'], true),
            'date' => Carbon::parse($value)->toDateString(),
            'datetime' => Carbon::parse($value)->toDateTimeString(),
            default => (string) $value,
        };
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

    protected function resolveForeignKeyValue(
        array $parentRecord,
        ImportTable $childTable,
        string $foreignKey,
        ?ImportTable $parentTable = null
    ): mixed
    {
        $direct = $this->resolveParentValue($parentRecord, $foreignKey);
        if ($direct !== null) {
            return $direct;
        }

        $parentPrimaryKey = trim((string) ($parentTable?->primary_key ?? ''))
            ?: trim((string) ($childTable->parentTable?->primary_key ?? ''))
            ?: 'id';
        return $this->resolveParentValue($parentRecord, $parentPrimaryKey)
            ?? $this->resolveParentValue($parentRecord, 'id');
    }

    protected function usesSeparateChildWorksheets(ImportTable $parentTable, array $childTables): bool
    {
        $parentSheet = trim((string) ($parentTable->worksheet ?? ''));
        foreach ($childTables as $childTable) {
            $childSheet = trim((string) ($childTable->worksheet ?? ''));
            if ($childSheet !== '' && $childSheet !== $parentSheet) {
                return true;
            }
        }

        return false;
    }

    protected function resolveParentWorksheet(ImportConfig $config, ImportTable $parentTable, array $metadata): string
    {
        $configured = trim((string) ($parentTable->worksheet ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return trim((string) ($metadata['selected_sheet'] ?? $this->resolveSelectedSheet($config) ?? 'Sheet1')) ?: 'Sheet1';
    }

    protected function validateWorkbookCompatibility(ImportConfig $config, array $worksheets, array $masterParents): void
    {
        $storedWorksheets = (array) (($config->template_metadata ?? [])['worksheets'] ?? []);
        foreach ($masterParents as $masterIndex => $parentTable) {
            $configuredParentSheet = trim((string) ($parentTable->worksheet ?? ''));
            $parentSheet = $configuredParentSheet !== ''
                ? $this->resolveParentWorksheet($config, $parentTable, $config->template_metadata ?? [])
                : '';
            $masterPath = 'master_parents.' . $masterIndex;
            if ($parentSheet !== '' && ! isset($worksheets[$parentSheet])) {
                throw ValidationException::withMessages(['file' => ['Parent worksheet "' . $parentSheet . '" does not exist for master "' . $this->masterName($parentTable) . '".']]);
            }

            if ($parentSheet !== '') {
                $expectedHeaders = (array) ($storedWorksheets[$parentSheet]['column_headers'] ?? []);
                if ($expectedHeaders === [] && $masterIndex === 0) {
                    $expectedHeaders = (array) (($config->template_metadata ?? [])['column_headers'] ?? []);
                }
                $actualHeaders = (array) ($worksheets[$parentSheet]['metadata']['column_headers'] ?? []);
                $missing = array_values(array_diff($expectedHeaders, $actualHeaders));
                if ($missing !== []) {
                    throw ValidationException::withMessages(['file' => ['Template header mismatch in worksheet "' . $parentSheet . '": missing ' . implode(', ', $missing)]]);
                }
            }

            $children = $parentTable->children->values()->all();
            $parentMatchColumn = trim((string) ($parentTable->parent_match_column ?? ''));

            foreach ($children as $childIndex => $childTable) {
                $childSheet = trim((string) ($childTable->worksheet ?? '')) ?: $parentSheet;
                if (! isset($worksheets[$childSheet])) {
                    throw ValidationException::withMessages(['file' => ['Child worksheet "' . $childSheet . '" does not exist for master "' . $this->masterName($parentTable) . '".']]);
                }

                $expectedHeaders = (array) ($storedWorksheets[$childSheet]['column_headers'] ?? []);
                $actualHeaders = (array) ($worksheets[$childSheet]['metadata']['column_headers'] ?? []);
                $missing = array_values(array_diff($expectedHeaders, $actualHeaders));
                if ($missing !== []) {
                    throw ValidationException::withMessages(['file' => ['Template header mismatch in worksheet "' . $childSheet . '": missing ' . implode(', ', $missing)]]);
                }

                $usesWorksheetMatching = trim((string) ($parentTable->worksheet ?? '')) !== ''
                    && trim((string) ($childTable->worksheet ?? '')) !== '';
                if ($usesWorksheetMatching && $parentMatchColumn !== '') {
                    $this->assertWorksheetHasColumn($worksheets[$parentSheet], $parentMatchColumn, 'Parent Match Column');
                }
                if ($usesWorksheetMatching && $childSheet !== $parentSheet) {
                    $matchColumn = trim((string) ($childTable->child_match_column ?? ''))
                        ?: trim((string) ($childTable->parent_match_column ?? ''));
                    if ($matchColumn !== '') {
                        $this->assertWorksheetHasColumn($worksheets[$childSheet], $matchColumn, 'Child Match Column');
                    }
                }
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

    protected function buildNormalizedImportDataset(ImportConfig $config, array $worksheets, array $masterParents): array
    {
        $dataset = ['masters' => []];
        foreach ($masterParents as $parentTable) {
            $parentSheet = trim((string) ($parentTable->worksheet ?? ''));
            $masterDataset = [
                'name' => $this->masterName($parentTable),
                'worksheet' => $parentSheet,
                'parent' => $parentSheet !== '' ? $this->normalizeImportedRows($worksheets[$parentSheet]['rows'] ?? []) : [['row' => 0, 'data' => []]],
                'children' => [],
                'child_worksheets' => [],
            ];
            foreach ($parentTable->children->values() as $childIndex => $childTable) {
                $childSheet = trim((string) ($childTable->worksheet ?? '')) ?: $parentSheet;
                $masterDataset['children'][$childIndex] = $this->normalizeImportedRows($worksheets[$childSheet]['rows'] ?? []);
                $masterDataset['child_worksheets'][$childIndex] = $childSheet;
            }
            $dataset['masters'][] = $masterDataset;
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

    /** @param array<string, mixed> $error */
    protected function withWorksheet(array $error, string $worksheet): array
    {
        if ($worksheet !== '' && empty($error['worksheet'])) {
            $error['worksheet'] = $worksheet;
        }

        return $error;
    }

    /** @return array<string, mixed> */
    protected function persistenceFailure(int $row, string $column, string $fallbackMessage): array
    {
        $failure = $this->lastPersistenceFailure;
        $this->lastPersistenceFailure = null;

        return array_filter([
            'row' => $row,
            'column' => $column,
            'message' => $failure['message'] ?? $fallbackMessage,
            'errors' => $failure['errors'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * Convert the normal processor summary into a source-row report without
     * exposing mapped payloads or committing the transaction.
     */
    protected function buildTestResult(array $dataset, ImportConfig $config, array $summary): array
    {
        $errorsByRow = [];
        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $master = (string) ($error['master'] ?? '');
            $sheet = (string) ($error['worksheet'] ?? '');
            $key = $master . ':' . $sheet . ':' . (int) ($error['row'] ?? 0);
            $errorsByRow[$key][] = $error;
        }

        $rows = [];
        $masters = [];
        $appendRows = function (array $sourceRows, string $master, string $worksheet) use (&$rows, $errorsByRow): array {
            $result = ['success' => 0, 'failed' => 0];
            foreach ($sourceRows as $rowInfo) {
                $rowNumber = (int) ($rowInfo['row'] ?? 0);
                $rowErrors = $errorsByRow[$master . ':' . $worksheet . ':' . $rowNumber] ?? [];
                $status = $rowErrors === [] ? 'success' : 'failed';
                $rows[] = [
                    'master' => $master,
                    'worksheet' => $worksheet,
                    'row' => $rowNumber,
                    'data' => (array) ($rowInfo['data'] ?? []),
                    'status' => $status,
                    'reason' => $rowErrors[0]['message'] ?? null,
                    'errors' => array_map(static function (array $error): array {
                        return array_filter([
                            'column' => $error['column'] ?? null,
                            'message' => $error['message'] ?? null,
                            'errors' => $error['errors'] ?? null,
                        ], static fn ($value): bool => $value !== null && $value !== []);
                    }, $rowErrors),
                ];
                $result[$status]++;
            }
            return $result;
        };

        foreach ((array) ($dataset['masters'] ?? []) as $masterIndex => $masterDataset) {
            $parentTable = $config->masterParents->get($masterIndex);
            if (! $parentTable instanceof ImportTable) {
                continue;
            }
            $masterName = (string) ($masterDataset['name'] ?? $this->masterName($parentTable));
            $parentSheet = (string) ($masterDataset['worksheet'] ?? $this->resolveParentWorksheet($config, $parentTable, []));
            $counts = $appendRows((array) ($masterDataset['parent'] ?? []), $masterName, $parentSheet);
            $children = $parentTable->children->values()->all();
            if ($this->usesSeparateChildWorksheets($parentTable, $children)) {
                foreach ($children as $childIndex => $childTable) {
                    $childSheet = trim((string) ($childTable->worksheet ?? '')) ?: $parentSheet;
                    $childCounts = $appendRows((array) ($masterDataset['children'][$childIndex] ?? []), $masterName, $childSheet);
                    $counts['success'] += $childCounts['success'];
                    $counts['failed'] += $childCounts['failed'];
                }
            }
            $masters[] = [
                'name' => $masterName,
                'success' => $counts['success'],
                'failed' => $counts['failed'],
            ];
        }

        $failed = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'failed'));

        return [
            'total' => count($rows),
            'success' => count($rows) - $failed,
            'failed' => $failed,
            'masters' => $masters,
            'rows' => $rows,
        ];
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
            foreach ((array) ($data['masters'] ?? []) as &$master) {
                $instance->handle($master['parent'], $config, $request);
                foreach ($master['children'] as &$rows) {
                    $instance->handle($rows, $config, $request);
                }
                unset($rows);
            }
            unset($master);
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
