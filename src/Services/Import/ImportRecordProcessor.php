<?php

namespace ESolution\DataSources\Services\Import;

use ESolution\DataSources\Exceptions\InvalidRuntimeVariableException;
use ESolution\DataSources\Exceptions\ImportHookException;
use ESolution\DataSources\Exceptions\ImportRowValidationException;
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
    /** A long physical gap marks the end of a worksheet's tabular data region. */
    protected const DATA_REGION_MAX_EMPTY_ROWS = 25;

    /** @var array{message: string, errors: array<string, array<int, string>>}|null */
    protected ?array $lastPersistenceFailure = null;

    /** @var array<string, mixed> */
    protected array $customParameterValues = [];

    /** Final replay uses the already-validated staged dataset. */
    protected bool $skipPersistenceValidation = false;

    /** Stage treats a worksheet-free parent as a request-level prerequisite. */
    protected bool $failFastNonWorksheetParent = false;

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
    public function test(ImportConfig $config, Request $request, int $previewLimit = 100): array
    {
        return $this->processImport($config, $request, true, false, $previewLimit);
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
            $this->applyBeforeFinalHook($config, $dataset, $request);
            if (! empty($dataset['prepared'])) {
                $summary = $this->finalizePreparedDataset($config, $request, $dataset, $connection, $connectionName);
                $summary = $this->applyAfterFinalHook($config, $request, $summary);
                $connection->commit();

                return $summary;
            }

            foreach ($config->masterParents->values() as $index => $parentTable) {
                $masterSummary = $this->processMasterParentAndChildren($config, $parentTable, $parentTable->children->values()->all(), (array) ($dataset['masters'][$index] ?? []), $connection, $connectionName, $request);
                $summary['success'] += $masterSummary['success']; $summary['failed'] += $masterSummary['failed'];
                $summary['errors'] = array_merge($summary['errors'], $masterSummary['errors']);
                $summary['masters'][] = ['name' => $this->masterName($parentTable), 'table_name' => $parentTable->table_name, 'success' => $masterSummary['success'], 'failed' => $masterSummary['failed']];
            }
            $summary = $this->applyAfterFinalHook($config, $request, $summary);
            $connection->commit();
            return $summary;
        } catch (Throwable $exception) { $connection->rollBack(); throw $exception; }
        finally { $this->skipPersistenceValidation = false; }
    }

    protected function processImport(
        ImportConfig $config,
        Request $request,
        bool $rollbackOnly = false,
        bool $includeDataset = false,
        ?int $previewLimit = null
    ): array
    {
        $this->failFastNonWorksheetParent = false;
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
        if ($includeDataset) {
            $this->applyBeforeStageHook($config, $dataset, $request);
        } else {
            // Direct and legacy test imports retain the original shared hooks.
            $this->applyBeforeExecuteHook($config, $dataset, $request);
        }
        unset($dataset['parent'], $dataset['children']);

        if ($includeDataset) {
            // Staging must only read target tables. Prepare the final payload,
            // validate it, and resolve parent context without calling the
            // persistence engine or opening a target-table transaction.
            $dataset = $this->prepareStagingDataset($dataset, $masterParents, $connection, $connectionName);
            $summary = $this->summarizeStagingDataset($dataset, $masterParents);
            $result = $this->buildTestResult($originalDataset, $config, $summary);
            $result['staging_dataset'] = $dataset;

            return $result;
        }

        $this->failFastNonWorksheetParent = false;
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
                $result = $this->buildTestResult($originalDataset, $config, $summary, $previewLimit);
                if ($includeDataset) { $result['staging_dataset'] = $dataset; }
                return $result;
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        } finally {
            $this->failFastNonWorksheetParent = false;
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
        $parentSheet = trim((string) ($dataset['worksheet'] ?? ''));
        if ($parentSheet === '' && trim((string) ($parentTable->worksheet ?? '')) !== '') {
            $parentSheet = $this->resolveParentWorksheet($config, $parentTable, []);
        }
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

        if ($this->failFastNonWorksheetParent && $parentSheet === '' && $summary['failed'] > 0) {
            $firstError = (array) ($summary['errors'][0] ?? []);
            $column = (string) ($firstError['column'] ?? 'import');
            $message = (string) ($firstError['message'] ?? 'The non-worksheet parent record is invalid.');
            throw ValidationException::withMessages([$column => [$message]]);
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

    /**
     * Resolve mappings once for a staged batch. Raw worksheet data remains in
     * `data`; `mapped_payload` is the immutable target payload used on final
     * replay. Auto-increment relationships intentionally remain metadata only.
     */
    protected function prepareStagingDataset(
        array $dataset,
        array $masterParents,
        ConnectionInterface $connection,
        string $connectionName
    ): array
    {
        foreach ($masterParents as $masterIndex => $parentTable) {
            $masterName = $this->masterName($parentTable);
            $parentContexts = [];
            $importMode = strtoupper(trim((string) ($parentTable->import_mode ?: 'UPSERT'))) ?: 'UPSERT';
            $parentWorksheet = (string) ($dataset['masters'][$masterIndex]['worksheet'] ?? '');
            foreach ((array) ($dataset['masters'][$masterIndex]['parent'] ?? []) as $rowIndex => $rowInfo) {
                $rowData = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
                $rowNumber = (int) ($rowInfo['row'] ?? 0);
                $payload = $this->buildMappedRows($parentTable->data_params ?? [], $rowData, ['row' => $rowData], false)[0] ?? [];
                $parentKey = $this->stagingParentRowKey($masterName, $parentTable, $rowData, $rowNumber);
                if (! empty($rowInfo['stage_errors'])) {
                    $dataset['masters'][$masterIndex]['parent'][$rowIndex]['mapped_payload'] = $payload;
                    $dataset['masters'][$masterIndex]['parent'][$rowIndex]['parent_row_key'] = $parentKey;
                    continue;
                }
                $plan = $this->planStagedTableRow($connection, $connectionName, $parentTable, $payload, $importMode);
                if ($plan === null) {
                    $dataset['masters'][$masterIndex]['parent'][$rowIndex]['stage_errors'] = [
                        $this->persistenceFailure($rowNumber, $this->firstColumnName($payload), 'Failed to prepare parent row.'),
                    ];
                    $dataset['masters'][$masterIndex]['parent'][$rowIndex]['mapped_payload'] = $payload;
                    $dataset['masters'][$masterIndex]['parent'][$rowIndex]['parent_row_key'] = $parentKey;
                    continue;
                }

                $dataset['masters'][$masterIndex]['parent'][$rowIndex]['mapped_payload'] = $plan['payload'];
                $dataset['masters'][$masterIndex]['parent'][$rowIndex]['parent_row_key'] = $parentKey;
                $dataset['masters'][$masterIndex]['parent'][$rowIndex]['stage_operation'] = $plan['operation'];
                $parentContexts[$parentKey] = $plan['context'];
            }

            foreach ($parentTable->children->values() as $childIndex => $childTable) {
                foreach ((array) ($dataset['masters'][$masterIndex]['children'][$childIndex] ?? []) as $rowIndex => $rowInfo) {
                    $rowData = $this->normalizeRowData((array) ($rowInfo['data'] ?? []));
                    $rowNumber = (int) ($rowInfo['row'] ?? 0);
                    $parentKey = $this->stagingChildParentRowKey($masterName, $parentTable, $childTable, $rowData, $rowNumber);
                    if (! empty($rowInfo['stage_errors'])) {
                        $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['parent_row_key'] = $parentKey;
                        continue;
                    }
                    if (! isset($parentContexts[$parentKey])) {
                        $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['stage_errors'] = [[
                            'row' => $rowNumber,
                            'column' => (string) ($childTable->foreign_key ?? ''),
                            'message' => 'Parent record is invalid or unavailable.',
                        ]];
                        $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['parent_row_key'] = $parentKey;
                        continue;
                    }

                    // Supplying null keys prevents unresolved {{ parent.* }}
                    // markers when the parent uses an auto-increment key.
                    $parentContext = array_merge(['id' => null, 'uuid' => null], (array) $parentContexts[$parentKey]);
                    $payload = $this->buildMappedRows(
                        $childTable->data_params ?? [],
                        $rowData,
                        ['row' => $rowData, 'parent' => $parentContext],
                        false
                    )[0] ?? [];
                    $foreignKey = trim((string) ($childTable->foreign_key ?? ''));
                    if ($foreignKey !== '') {
                        $foreignValue = $this->resolveForeignKeyValue($parentContext, $childTable, $foreignKey, $parentTable);
                        if ($foreignValue === null) {
                            unset($payload[$foreignKey]);
                        } else {
                            $payload[$foreignKey] = $foreignValue;
                        }
                    }
                    $plan = $this->planStagedTableRow($connection, $connectionName, $childTable, $payload, $importMode);
                    if ($plan === null) {
                        $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['stage_errors'] = [
                            $this->persistenceFailure($rowNumber, $this->firstColumnName($payload), 'Failed to prepare child row.'),
                        ];
                        $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['mapped_payload'] = $payload;
                        $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['parent_row_key'] = $parentKey;
                        continue;
                    }

                    $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['mapped_payload'] = $plan['payload'];
                    $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['parent_row_key'] = $parentKey;
                    $dataset['masters'][$masterIndex]['children'][$childIndex][$rowIndex]['stage_operation'] = $plan['operation'];
                }
            }
        }

        $dataset['prepared'] = true;

        return $dataset;
    }

    /**
     * Plan a target operation for staging. This deliberately performs SELECT
     * and Validator work only: no insert, update, delete, or transaction is
     * opened against the target table.
     *
     * @return array{payload:array<string, mixed>, context:array<string, mixed>, operation:string}|null
     */
    protected function planStagedTableRow(
        ConnectionInterface $connection,
        string $connectionName,
        ImportTable $table,
        array $row,
        string $importMode
    ): ?array {
        $this->lastPersistenceFailure = null;
        $payload = $this->normalizePersistedRow($row);
        $tableName = $this->normalizeTableName($connection, (string) $table->table_name);
        if ($tableName === '') {
            $this->lastPersistenceFailure = ['message' => 'The configured table name is empty.', 'errors' => []];
            return null;
        }

        $lookupKey = trim((string) $this->resolveLookupKey($table, $connectionName));
        $existing = null;
        if ($lookupKey !== '' && array_key_exists($lookupKey, $payload) && $payload[$lookupKey] !== null && $payload[$lookupKey] !== '') {
            $existing = $connection->table($tableName)->where($lookupKey, $payload[$lookupKey])->first();
        }

        $mode = strtoupper(trim($importMode));
        if ($mode === 'UPDATE' && $existing === null) {
            $this->lastPersistenceFailure = ['message' => 'No matching record was found for update.', 'errors' => []];
            return null;
        }

        $operation = $mode === 'UPDATE' || ($mode === 'UPSERT' && $existing !== null) ? 'UPDATE' : 'INSERT';
        $primaryKey = trim((string) ($table->primary_key ?: $this->resolveTablePrimaryKeyName($table->table_name, $connectionName)));
        $existingValues = $existing !== null ? (array) $existing : [];
        if ($existing !== null && $primaryKey !== '' && array_key_exists($primaryKey, $existingValues)) {
            // Existing UPSERT/UPDATE rows retain their real primary key even
            // when a mapping contains a generated value such as uuid.random.
            $payload[$primaryKey] = $existingValues[$primaryKey];
        }

        $ignoreValue = $operation === 'UPDATE' && $primaryKey !== ''
            ? ($existingValues[$primaryKey] ?? null)
            : null;
        $rules = $this->buildValidationRules($tableName, $table, $payload, $connectionName, $primaryKey, $ignoreValue);
        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            $this->lastPersistenceFailure = [
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ];
            return null;
        }

        return [
            'payload' => $payload,
            'context' => array_merge($existingValues, $payload),
            'operation' => $operation,
        ];
    }

    protected function summarizeStagingDataset(array $dataset, array $masterParents): array
    {
        $summary = ['success' => 0, 'failed' => 0, 'errors' => [], 'masters' => []];
        foreach ($masterParents as $masterIndex => $parentTable) {
            $masterSummary = ['success' => 0, 'failed' => 0, 'errors' => []];
            $master = (array) ($dataset['masters'][$masterIndex] ?? []);
            $masterName = $this->masterName($parentTable);
            $parentWorksheet = (string) ($master['worksheet'] ?? '');

            $append = function (array $rows, string $worksheet) use (&$masterSummary, $masterName): void {
                foreach ($rows as $rowInfo) {
                    $rowErrors = (array) ($rowInfo['stage_errors'] ?? []);
                    if ($rowErrors === []) {
                        $masterSummary['success']++;
                        continue;
                    }
                    $masterSummary['failed']++;
                    foreach ($rowErrors as $error) {
                        $error['master'] = $masterName;
                        $masterSummary['errors'][] = $this->withWorksheet((array) $error, $worksheet);
                    }
                }
            };

            $append((array) ($master['parent'] ?? []), $parentWorksheet);
            foreach ($parentTable->children->values() as $childIndex => $childTable) {
                $childWorksheet = (string) ($master['child_worksheets'][$childIndex] ?? $parentWorksheet);
                $append((array) ($master['children'][$childIndex] ?? []), $childWorksheet);
            }

            $summary['success'] += $masterSummary['success'];
            $summary['failed'] += $masterSummary['failed'];
            $summary['errors'] = array_merge($summary['errors'], $masterSummary['errors']);
            $summary['masters'][] = [
                'name' => $masterName,
                'table_name' => $parentTable->table_name,
                'success' => $masterSummary['success'],
                'failed' => $masterSummary['failed'],
            ];
        }

        return $summary;
    }

    protected function stagingParentRowKey(string $masterName, ImportTable $parentTable, array $rowData, int $rowNumber): string
    {
        if (trim((string) ($parentTable->worksheet ?? '')) === '') {
            return $masterName . '|__single_parent__';
        }

        $matchColumn = trim((string) ($parentTable->parent_match_column ?? ''));
        $matchValue = $matchColumn !== '' ? ($rowData[$matchColumn] ?? null) : null;

        return $matchValue !== null && $matchValue !== ''
            ? $masterName . '|' . (string) $matchValue
            : $masterName . '|row:' . $rowNumber;
    }

    protected function stagingChildParentRowKey(string $masterName, ImportTable $parentTable, ImportTable $childTable, array $rowData, int $rowNumber): string
    {
        $matchColumn = trim((string) ($childTable->child_match_column ?? ''))
            ?: trim((string) ($childTable->parent_match_column ?? ''));
        $matchValue = $matchColumn !== '' ? ($rowData[$matchColumn] ?? null) : null;
        if ($matchValue !== null && $matchValue !== '') {
            return $masterName . '|' . (string) $matchValue;
        }

        if (trim((string) ($parentTable->worksheet ?? '')) === '') {
            return $masterName . '|__single_parent__';
        }

        return $masterName . '|row:' . $rowNumber;
    }

    /** Persist staged mapped_payload values without rerunning source mapping. */
    protected function finalizePreparedDataset(ImportConfig $config, Request $request, array $dataset, ConnectionInterface $connection, string $connectionName): array
    {
        $summary = ['success' => 0, 'failed' => 0, 'errors' => [], 'masters' => []];
        foreach ($config->masterParents->values() as $masterIndex => $parentTable) {
            $master = (array) ($dataset['masters'][$masterIndex] ?? []);
            $masterSummary = ['success' => 0, 'failed' => 0, 'errors' => []];
            $parentRecords = [];
            $importMode = strtoupper(trim((string) ($parentTable->import_mode ?: $config->import_mode))) ?: 'UPSERT';

            foreach ((array) ($master['parent'] ?? []) as $rowInfo) {
                $payload = (array) ($rowInfo['mapped_payload'] ?? []);
                $rowNumber = (int) ($rowInfo['row'] ?? 0);
                // A staged UPSERT has already chosen INSERT or UPDATE using a
                // read-only lookup. Preserve that decision at commit time so a
                // staged UPDATE can never fall through to an INSERT.
                $plannedOperation = strtoupper(trim((string) ($rowInfo['stage_operation'] ?? '')));
                $persistenceMode = in_array($plannedOperation, ['INSERT', 'UPDATE'], true)
                    ? $plannedOperation
                    : $importMode;
                $persisted = $this->upsertTableRow($connection, $connectionName, $parentTable, $payload, $persistenceMode, null);
                if ($persisted === null) {
                    $masterSummary['failed']++;
                    $masterSummary['errors'][] = $this->withWorksheet($this->persistenceFailure($rowNumber, $this->firstColumnName($payload), 'Failed to persist parent row.'), (string) ($master['worksheet'] ?? ''));
                    continue;
                }
                $masterSummary['success']++;
                $parentRecords[(string) ($rowInfo['parent_row_key'] ?? '')] = $persisted;
            }

            foreach ($parentTable->children->values() as $childIndex => $childTable) {
                $groups = [];
                foreach ((array) ($master['children'][$childIndex] ?? []) as $rowInfo) {
                    $parentKey = (string) ($rowInfo['parent_row_key'] ?? '');
                    if (! isset($parentRecords[$parentKey])) {
                        $masterSummary['failed']++;
                        $masterSummary['errors'][] = $this->withWorksheet([
                            'row' => (int) ($rowInfo['row'] ?? 0),
                            'column' => (string) ($childTable->foreign_key ?? ''),
                            'message' => 'Parent not found for staged child row.',
                        ], (string) ($master['child_worksheets'][$childIndex] ?? $master['worksheet'] ?? ''));
                        continue;
                    }
                    $groups[$parentKey]['parent'] = $parentRecords[$parentKey];
                    $groups[$parentKey]['rows'][] = (array) ($rowInfo['mapped_payload'] ?? []);
                    $groups[$parentKey]['row_numbers'][] = (int) ($rowInfo['row'] ?? 0);
                }

                foreach ($groups as $group) {
                    $result = $this->persistChildRows(
                        $connection,
                        $connectionName,
                        $childTable,
                        $group['rows'],
                        $group['parent'],
                        $importMode,
                        $request,
                        $group['row_numbers'],
                        $parentTable
                    );
                    $masterSummary['success'] += $result['success'];
                    $masterSummary['failed'] += $result['failed'];
                    foreach ($result['errors'] as $error) {
                        $masterSummary['errors'][] = $this->withWorksheet($error, (string) ($master['child_worksheets'][$childIndex] ?? $master['worksheet'] ?? ''));
                    }
                }
            }

            $masterSummary = $this->tagMasterErrors($masterSummary, $parentTable);
            $summary['success'] += $masterSummary['success'];
            $summary['failed'] += $masterSummary['failed'];
            $summary['errors'] = array_merge($summary['errors'], $masterSummary['errors']);
            $summary['masters'][] = ['name' => $this->masterName($parentTable), 'table_name' => $parentTable->table_name, 'success' => $masterSummary['success'], 'failed' => $masterSummary['failed']];
        }

        return $summary;
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

        if (! $this->skipPersistenceValidation) {
            $ignoreColumn = trim((string) ($table->primary_key ?: $this->resolveTablePrimaryKeyName($table->table_name, $connectionName)));
            $ignoreValue = $shouldUpdate && $existing !== null && $ignoreColumn !== ''
                ? data_get((array) $existing, $ignoreColumn)
                : null;
            $rules = $this->buildValidationRules($tableName, $table, $payload, $connectionName, $ignoreColumn, $ignoreValue);
            $validator = Validator::make($payload, $rules);
            if ($validator->fails()) {
                $this->lastPersistenceFailure = [
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()->toArray(),
                ];
                return null;
            }
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

    protected function buildValidationRules(string $tableName, ImportTable $table, array $payload, string $connectionName, ?string $ignoreColumn = null, mixed $ignoreValue = null): array
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
                // Laravel's Unique rule selects a connection through the table
                // identifier; it does not support an ->on() method.
                $validationTable = $connectionName !== ''
                    ? $connectionName . '.' . $tableName
                    : $tableName;
                $unique = Rule::unique($validationTable, $targetColumn);
                if ($ignoreColumn !== null && $ignoreColumn !== '' && $ignoreValue !== null && $ignoreValue !== '') {
                    $unique = $unique->ignore($ignoreValue, $ignoreColumn);
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
            'required' => filter_var($mapping['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'unique' => filter_var($mapping['unique'] ?? false, FILTER_VALIDATE_BOOLEAN),
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
                'parent' => $parentSheet !== ''
                    ? $this->filterWorksheetRowsForMappings(
                        $this->normalizeImportedRows($worksheets[$parentSheet]['rows'] ?? []),
                        (array) ($parentTable->data_params ?? [])
                    )
                    : [['row' => 0, 'data' => []]],
                'children' => [],
                'child_worksheets' => [],
            ];
            foreach ($parentTable->children->values() as $childIndex => $childTable) {
                $childSheet = trim((string) ($childTable->worksheet ?? '')) ?: $parentSheet;
                $masterDataset['children'][$childIndex] = $childSheet !== ''
                    ? $this->filterWorksheetRowsForMappings(
                        $this->normalizeImportedRows($worksheets[$childSheet]['rows'] ?? []),
                        (array) ($childTable->data_params ?? [])
                    )
                    : [];
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

    /**
     * A worksheet's used range can include formatting and footer notes well
     * beyond its table. Classify rows before validation/persistence using only
     * this table's mapped headers and its physical data region.
     */
    protected function filterWorksheetRowsForMappings(array $rows, array $dataParams): array
    {
        $sources = [];
        $requiredSources = [];
        foreach ($dataParams as $column => $mapping) {
            $descriptor = $this->normalizeMappingDescriptor($mapping, is_string($column) ? $column : null);
            $sourceType = strtolower(trim((string) ($descriptor['source_type'] ?? '')));
            if (in_array($sourceType, ['custom_parameter', 'runtime_variable'], true)) {
                continue;
            }
            $source = trim((string) ($descriptor['value'] ?? ''));
            if ($source !== '' && preg_match('/^\{\{\s*[^}]+?\s*\}\}$/', $source) !== 1) {
                $sources[] = $source;
                if ((bool) ($descriptor['required'] ?? false)) {
                    $requiredSources[] = $source;
                }
            }
        }
        $sources = array_values(array_unique($sources));
        $requiredSources = array_values(array_unique($requiredSources));
        if ($sources === []) {
            return [];
        }

        $valueForSource = static function (array $data, string $source): mixed {
            // Excel headers are literal labels and may contain dots, which
            // data_get would otherwise interpret as nested-path syntax.
            return array_key_exists($source, $data)
                ? $data[$source]
                : data_get($data, $source);
        };
        $hasValue = static fn (mixed $value): bool => $value !== null && $value !== '';
        $minimumRequiredValues = count($requiredSources) > 1 ? 2 : count($requiredSources);
        $classifiedRows = [];
        $dataRegionStarted = false;
        $lastCandidateRow = null;

        foreach ($rows as $rowInfo) {
            $data = (array) ($rowInfo['data'] ?? []);
            $mappedValues = 0;
            foreach ($sources as $source) {
                if ($hasValue($valueForSource($data, $source))) {
                    $mappedValues++;
                }
            }
            if ($mappedValues === 0) {
                continue;
            }

            $rowNumber = (int) ($rowInfo['row'] ?? 0);
            if ($dataRegionStarted && $lastCandidateRow !== null
                && $rowNumber > ($lastCandidateRow + self::DATA_REGION_MAX_EMPTY_ROWS + 1)) {
                // A footer cannot restart a completed data region after a long
                // blank/formatted range in the worksheet's used range.
                break;
            }

            $requiredValues = 0;
            foreach ($requiredSources as $source) {
                if ($hasValue($valueForSource($data, $source))) {
                    $requiredValues++;
                }
            }

            if (! $dataRegionStarted) {
                // A single value such as a footer label is not enough to begin
                // a multi-column required data table. Once the region starts,
                // incomplete rows remain so the existing validators report them.
                if ($minimumRequiredValues > 0 && $requiredValues < $minimumRequiredValues) {
                    continue;
                }
                $dataRegionStarted = true;
            }

            $classifiedRows[] = $rowInfo;
            $lastCandidateRow = $rowNumber;
        }

        return $classifiedRows;
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
    protected function buildTestResult(array $dataset, ImportConfig $config, array $summary, ?int $previewLimit = null): array
    {
        $previewLimit = $previewLimit === null ? null : max(1, $previewLimit);
        $errorsByRow = [];
        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $master = (string) ($error['master'] ?? '');
            $sheet = (string) ($error['worksheet'] ?? '');
            $key = $master . ':' . $sheet . ':' . (int) ($error['row'] ?? 0);
            $errorsByRow[$key][] = $error;
        }

        $rows = [];
        $masters = [];
        $total = 0;
        $failed = 0;
        $appendRows = function (array $sourceRows, string $master, string $worksheet) use (&$rows, &$total, &$failed, $errorsByRow, $previewLimit): array {
            $result = ['success' => 0, 'failed' => 0];
            foreach ($sourceRows as $rowInfo) {
                $rowNumber = (int) ($rowInfo['row'] ?? 0);
                $rowErrors = $errorsByRow[$master . ':' . $worksheet . ':' . $rowNumber] ?? [];
                $status = $rowErrors === [] ? 'success' : 'failed';
                $total++;
                if ($status === 'failed') {
                    $failed++;
                }
                if ($previewLimit === null || count($rows) < $previewLimit) {
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
                }
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

        return [
            'total' => $total,
            'success' => $total - $failed,
            'failed' => $failed,
            'masters' => $masters,
            'rows' => $rows,
            'preview' => [
                'limit' => $previewLimit,
                'returned_rows' => count($rows),
                'truncated' => $previewLimit !== null && $total > count($rows),
            ],
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
        return $this->applyAfterHook($hook, $config, $request, $summary);
    }

    /** Run only for POST /api/{code}/import/stage before staging data is prepared. */
    protected function applyBeforeStageHook(ImportConfig $config, array &$data, Request $request): void
    {
        try {
            $this->applyBeforeHook(trim((string) ($config->before_stage_hook ?? '')), $config, $data, $request);
        } catch (ImportRowValidationException $exception) {
            $this->applyRowValidationException($data, $exception);
        }
    }

    /** Run only while a staged batch is being committed to target tables. */
    protected function applyBeforeFinalHook(ImportConfig $config, array &$data, Request $request): void
    {
        $this->applyBeforeHook(trim((string) ($config->before_final_hook ?? '')), $config, $data, $request);
    }

    /**
     * Invoked by the controller after staging records have been written. The
     * result includes import_uuid, summary, and the prepared staging dataset.
     */
    public function applyAfterStageHook(ImportConfig $config, Request $request, array $result): array
    {
        return $this->applyAfterHook(trim((string) ($config->after_stage_hook ?? '')), $config, $request, $result);
    }

    /** Run only after staged records have been persisted successfully. */
    protected function applyAfterFinalHook(ImportConfig $config, Request $request, array $summary): array
    {
        return $this->applyAfterHook(trim((string) ($config->after_final_hook ?? '')), $config, $request, $summary);
    }

    protected function applyBeforeHook(string $hook, ImportConfig $config, array &$data, Request $request): void
    {
        if ($hook === '' || ! class_exists($hook)) {
            return;
        }

        $instance = app($hook);
        if ($instance instanceof ImportBeforeExecuteHookInterface) {
            $instance->handle($data, $config, $request);
            return;
        }

        throw new \LogicException('Import before hook must implement ' . ImportBeforeExecuteHookInterface::class . '.');
    }

    /** Convert a hook row exception into the same row-level error shape as validation. */
    protected function applyRowValidationException(array &$data, ImportRowValidationException $exception): void
    {
        $masterIndex = $exception->getMasterIndex();
        $type = $exception->getType();
        $childIndex = $exception->getChildIndex();
        $rowIndex = $exception->getRowIndex();

        if (
            ! isset($data['masters'][$masterIndex])
            || ($type === 'child' && ($childIndex === null || ! isset($data['masters'][$masterIndex]['children'][$childIndex])))
            || ($type === 'parent' && ! isset($data['masters'][$masterIndex]['parent']))
        ) {
            throw new ImportHookException(422, 'Import row validation target could not be resolved.', [
                'master_index' => $masterIndex,
                'type' => $type,
                'child_index' => $childIndex,
                'row_index' => $rowIndex,
            ]);
        }

        if ($type === 'parent') {
            $rows =& $data['masters'][$masterIndex]['parent'];
        } else {
            $rows =& $data['masters'][$masterIndex]['children'][$childIndex];
        }
        if (! array_key_exists($rowIndex, $rows)) {
            throw new ImportHookException(422, 'Import row validation target could not be resolved.', [
                'master_index' => $masterIndex,
                'type' => $type,
                'child_index' => $childIndex,
                'row_index' => $rowIndex,
            ]);
        }

        $rowNumber = (int) ($rows[$rowIndex]['row'] ?? 0);
        $stageErrors = [];
        foreach ($exception->getErrors() as $column => $messages) {
            $messages = is_array($messages) ? array_values($messages) : [(string) $messages];
            $message = (string) ($messages[0] ?? 'The row is invalid.');
            $stageErrors[] = [
                'row' => $rowNumber,
                'column' => (string) $column,
                'message' => $message,
                'errors' => [(string) $column => $messages],
            ];
        }
        $rows[$rowIndex]['stage_errors'] = array_merge((array) ($rows[$rowIndex]['stage_errors'] ?? []), $stageErrors);
    }

    protected function applyAfterHook(string $hook, ImportConfig $config, Request $request, array $data): array
    {
        if ($hook === '' || ! class_exists($hook)) {
            return $data;
        }

        $instance = app($hook);
        if (method_exists($instance, 'handle')) {
            $result = $instance->handle($request, $config, $data);
            return is_array($result) ? $result : $data;
        }

        return $data;
    }

    protected function resolveSelectedSheet(ImportConfig $config): ?string
    {
        $metadata = is_array($config->template_metadata ?? null) ? $config->template_metadata : [];
        $selected = trim((string) ($metadata['selected_sheet'] ?? ''));
        return $selected !== '' ? $selected : null;
    }
}
