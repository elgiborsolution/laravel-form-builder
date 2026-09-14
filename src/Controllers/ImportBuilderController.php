<?php

namespace ESolution\DataSources\Controllers;

use App\Http\Controllers\Controller;
use ESolution\DataSources\Models\ImportConfig;
use ESolution\DataSources\Models\ImportTable;
use ESolution\DataSources\Models\ImportStagingBatch;
use ESolution\DataSources\Models\ImportStagingRecord;
use ESolution\DataSources\Services\Import\ImportRecordProcessor;
use ESolution\DataSources\Services\Import\ImportTemplateReader;
use ESolution\DataSources\Exceptions\ImportHookException;
use ESolution\DataSources\Exceptions\ImportRowValidationException;
use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use ESolution\DataSources\Support\DatabaseConnection;
use ESolution\DataSources\Support\DatabaseMetadataProvider;
use ESolution\DataSources\Support\ExecutionConnectionResolver;
use ESolution\DataSources\Support\ImportConfigResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportBuilderController extends Controller
{
    use AppliesSearchFilter;

    public function __construct(
        protected ImportConfigResolver $resolver,
        protected ImportTemplateReader $templateReader,
        protected ImportRecordProcessor $processor,
        protected ?DatabaseMetadataProvider $databaseMetadataProvider = null,
        protected ?ExecutionConnectionResolver $executionConnectionResolver = null
    ) {
        $this->databaseMetadataProvider ??= new DatabaseMetadataProvider();
        $this->executionConnectionResolver ??= new ExecutionConnectionResolver();
    }

    protected function importResponse(bool $success, string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function importValidationResponse(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $firstMessage = null;
        foreach ($errors as $messages) {
            $firstMessage = $messages[0] ?? null;
            if ($firstMessage !== null) {
                break;
            }
        }

        return $this->importResponse(false, $firstMessage ?: 'The request is invalid.', ['errors' => $errors], 422);
    }

    protected function importHookResponse(ImportHookException $exception): JsonResponse
    {
        $data = $exception->getData();

        return $this->importResponse(false, $exception->getMessage(), $data === [] ? null : $data, $exception->getStatusCode());
    }

    public function index(Request $request): JsonResponse
    {
        $query = ImportConfig::query()->with(['parentTable', 'childTables', 'masterParents.children'])->orderBy('id');

        if (trim((string) $request->query('search', '')) !== '') {
            $query = $this->applySearchFilter($query, $request, [
                'code',
                'name',
                'description',
                'endpoint',
                'import_mode',
            ]);
        }

        $enabled = $request->query('enabled');
        if ($enabled !== null && $enabled !== '') {
            $query->where('enabled', filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
        }

        return $this->importResponse(true, 'Import builders retrieved successfully.', $query->paginate((int) $request->query('per_page', 10) ?: 10));
    }

    public function defaults(): JsonResponse
    {
        return $this->importResponse(true, 'Import builder defaults retrieved successfully.', [
            'default_middlewares' => [],
            'import_mode_options' => ['INSERT', 'UPDATE', 'UPSERT'],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'template_file' => ['required', 'file', 'mimes:xlsx,csv'],
        ]);
        if ($validator->fails()) {
            return $this->importResponse(false, $validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }

        try {
            $analysis = $this->templateReader->analyze($request->file('template_file'));
        } catch (ValidationException $exception) {
            return $this->importValidationResponse($exception);
        } catch (Throwable $exception) {
            report($exception);
            return $this->importResponse(false, 'Unable to analyze the template.', ['errors' => [['message' => $exception->getMessage()]]], 500);
        }

        return $this->importResponse(true, 'Template preview generated successfully.', $analysis['metadata']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, true);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $validated = $this->prepareHookPayload($validated, true);
        $payload = $this->normalizePayload($validated, true);
        $payload['database_scope'] = $this->resolveDatabaseScope($request);
        $template = $this->storeTemplate($request->file('template_file'), $payload['code']);
        $payload = array_merge($payload, $template['attributes']);
        $payload['template_metadata'] = $template['metadata'];

        $config = ImportConfig::create($payload)->fresh(['parentTable', 'childTables', 'masterParents.children']);
        $this->syncRelations($config, $validated);
        $this->resolver->forget($config->endpoint);

        return $this->importResponse(true, 'Import builder created successfully.', $config->fresh(['parentTable', 'childTables', 'masterParents.children']), 201);
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->with(['parentTable', 'childTables', 'masterParents.children'])->find($id)
            ?? ImportConfig::query()->with(['parentTable', 'childTables', 'masterParents.children'])->where('code', $id)->first();

        if ($config === null) {
            return $this->importResponse(false, 'Import builder not found.', null, 404);
        }

        return $this->importResponse(true, 'Import builder retrieved successfully.', $config);
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->find($id);
        if ($config === null) {
            return $this->importResponse(false, 'Import builder not found.', null, 404);
        }

        $validated = $this->validatePayload($request, false, $config);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $validated = $this->prepareHookPayload($validated, false, $config);
        $originalEndpoint = $config->endpoint;
        $payload = $this->normalizePayload($validated, false);
        $payload['database_scope'] = $this->resolveDatabaseScope($request);

        if ($request->hasFile('template_file')) {
            $template = $this->storeTemplate($request->file('template_file'), $payload['code'] ?? $config->code);
            $payload = array_merge($payload, $template['attributes']);
            $payload['template_metadata'] = $template['metadata'];
        }

        $config->fill($payload);
        $config->save();
        $config = $config->fresh(['parentTable', 'childTables', 'masterParents.children']);
        $this->syncRelations($config, $validated);
        $config = $config->fresh(['parentTable', 'childTables', 'masterParents.children']);

        $this->resolver->forget($originalEndpoint);
        $this->resolver->forget($config->endpoint);

        return $this->importResponse(true, 'Import builder updated successfully.', $config);
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->find($id);

        if ($config === null) {
            return $this->importResponse(false, 'Import builder not found.', null, 404);
        }

        $this->resolver->forget($config->endpoint);
        $config->delete();

        return $this->importResponse(true, 'Import builder deleted successfully.', []);
    }

    public function updateStatus(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->find($id);
        if ($config === null) {
            return $this->importResponse(false, 'Import builder not found.', null, 404);
        }

        $payload = $this->normalizeIncomingPayload($request->all());
        $validator = Validator::make($payload, ['enabled' => ['required', 'boolean']]);
        if ($validator->fails()) {
            return $this->importResponse(false, $validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }
        $validated = $validator->validated();
        $config->update(['enabled' => (bool) $validated['enabled']]);
        $this->resolver->forget($config->endpoint);

        return $this->importResponse(true, 'Status updated successfully.', $config->fresh());
    }

    public function downloadTemplate(Request $request, string $endpoint)
    {
        $config = $this->resolver->findByEndpoint($endpoint);

        if ($config === null || $config->template_path === null) {
            abort(404);
        }

        $disk = Storage::disk($config->template_disk ?: 'local');
        if (! $disk->exists($config->template_path)) {
            abort(404);
        }

        $filename = $config->template_original_name ?: basename($config->template_path);
        return $disk->download($config->template_path, $filename);
    }

    public function import(Request $request, string $endpoint): JsonResponse
    {
        $config = $this->resolver->findByEndpoint($endpoint);

        if ($config === null) {
            return $this->importResponse(false, 'Import builder not found.', null, 404);
        }

        $hasImportUuid = $request->exists('import_uuid');
        $hasFile = $request->hasFile('file');
        if ($hasImportUuid && $hasFile) {
            return $this->importResponse(false, 'Provide either file for direct import or import_uuid for staged import finalization, not both.', null, 422);
        }
        if (! $hasImportUuid && ! $hasFile) {
            return $this->importResponse(false, 'Either file or import_uuid is required.', null, 422);
        }

        try {
            if (! $hasImportUuid) {
                return $this->importResponse(true, 'Import completed successfully.', $this->processor->process($config, $request));
            }
            $uuid = trim((string) $request->input('import_uuid', ''));
            if ($uuid === '') { return $this->importResponse(false, 'A valid import_uuid is required for final import.', null, 422); }
            // Fail fast for inaccessible/consumed UUIDs. The locked claim below
            // remains authoritative for concurrent requests.
            $batch = $this->stagingBatch($request, $config, $uuid);
            if ($batch === null) { return $this->importResponse(false, 'Import staging batch not found.', null, 404); }
            if ($batch->status !== 'staged') { return $this->importResponse(false, 'This import batch is already being processed or has already been finalized.', null, 409); }
            $summary = $this->finalizeStagedBatch($request, $config, $uuid);
            if ($summary === null) { return $this->importResponse(false, 'This import batch is already being processed or has already been finalized.', null, 409); }
        } catch (ImportHookException $exception) {
            return $this->importHookResponse($exception);
        } catch (ValidationException $exception) {
            return $this->importValidationResponse($exception);
        } catch (Throwable $exception) {
            report($exception);
            return $this->importResponse(false, 'Import failed.', ['errors' => [['message' => $exception->getMessage()]]], 500);
        }

        return $this->importResponse(true, 'Import completed successfully.', $summary);
    }

    public function stage(Request $request, string $endpoint): JsonResponse
    {
        $config = $this->resolver->findByEndpoint($endpoint);
        if ($config === null) { return $this->importResponse(false, 'Import builder not found.', null, 404); }
        if (! $request->hasFile('file')) {
            return $this->importResponse(false, 'The file field is required for staging.', null, 422);
        }

        $replaceExisting = $request->exists('import_uuid');
        $uuid = trim((string) $request->input('import_uuid', ''));
        if ($replaceExisting && $uuid === '') {
            return $this->importResponse(false, 'A valid import_uuid is required to replace staged data.', null, 422);
        }

        // Fail early before reading the workbook when the supplied UUID is not
        // accessible or cannot be replaced. The locked check below remains
        // authoritative if finalization starts concurrently.
        if ($replaceExisting) {
            $existingBatch = $this->stagingBatch($request, $config, $uuid);
            if ($existingBatch === null) {
                return $this->importResponse(false, 'Import staging batch not found.', null, 404);
            }
            if ($existingBatch->status !== 'staged') {
                return $this->importResponse(false, 'This import batch is already being processed or has already been finalized.', null, 409);
            }
        } else {
            $uuid = (string) Str::uuid();
        }

        try {
            // The processor validates and prepares data only. It must complete
            // before an existing batch is touched, so a bad replacement leaves
            // the previous staging records intact.
            $report = $this->processor->stage($config, $request);
            $stagingRows = $this->finalizeStagingRowStatuses($config, (array) ($report['rows'] ?? []));
            $connectionName = (string) $request->attributes->get('datasources.connection_name', '');
            $stageResult = DB::connection($connectionName)->transaction(function () use ($config, $request, $report, $stagingRows, $uuid, $replaceExisting): ?array {
                $dataset = $this->successfulStagingDataset((array) ($report['staging_dataset'] ?? []), $stagingRows);
                if ($replaceExisting) {
                    $query = ImportStagingBatch::query()
                        ->where('import_uuid', $uuid)
                        ->where('import_config_id', $config->id)
                        ->where('tenant_key', trim((string) $request->header('X-Tenant', '')) ?: null)
                        ->lockForUpdate();
                    if ($request->user() !== null) { $query->where('user_id', $request->user()->getAuthIdentifier()); }
                    $batch = $query->first();
                    if ($batch === null || $batch->status !== 'staged') {
                        return null;
                    }

                    // Delete only after the replacement has been fully prepared.
                    // Any record write or hook failure rolls this transaction back.
                    $batch->records()->delete();
                    $batch->update([
                        'connection_name' => (string) $request->attributes->get('datasources.connection_name', ''),
                        'status' => 'staged', 'total' => 0, 'success_count' => 0, 'failed_count' => 0,
                        'dataset' => $dataset,
                    ]);
                } else {
                    $batch = ImportStagingBatch::create([
                        'import_uuid' => $uuid, 'import_config_id' => $config->id, 'user_id' => $request->user()?->getAuthIdentifier(),
                        'tenant_key' => trim((string) $request->header('X-Tenant', '')) ?: null,
                        'connection_name' => (string) $request->attributes->get('datasources.connection_name', ''), 'status' => 'staged',
                        'total' => 0, 'success_count' => 0, 'failed_count' => 0, 'dataset' => $dataset,
                    ]);
                }
                foreach ($stagingRows as $order => $row) {
                    $prepared = $this->stagingPreparedRow((array) ($report['staging_dataset'] ?? []), $row);
                    ImportStagingRecord::create([
                        'import_uuid' => $uuid, 'import_config_id' => $config->id, 'master_name' => $row['master'] ?? null,
                        'table_name' => $this->stagingTableName($config, $row), 'row_no' => (int) ($row['row'] ?? 0),
                        'parent_row_key' => $prepared['parent_row_key'] ?? $this->stagingParentRowKey($config, $row),
                        'payload' => $row['data'] ?? [], 'mapped_payload' => $prepared['mapped_payload'] ?? null,
                        'status' => $row['status'] ?? 'failed', 'errors' => $row['errors'] ?? null, 'execution_order' => $order,
                    ]);
                }
                $finalCounts = ImportStagingRecord::query()
                    ->where('import_uuid', $uuid)
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status');
                $batch->update([
                    'total' => $finalCounts->sum(),
                    'success_count' => (int) ($finalCounts['success'] ?? 0),
                    'failed_count' => (int) ($finalCounts['failed'] ?? 0),
                ]);
                $batch->refresh();

                return $this->processor->applyAfterStageHook($config, $request, [
                    'import_uuid' => $batch->import_uuid,
                    'total' => $batch->total,
                    'success' => $batch->success_count,
                    'failed' => $batch->failed_count,
                    'staging_dataset' => $batch->dataset,
                ]);
            });
            if ($stageResult === null) {
                return $this->importResponse(false, 'This import batch is already being processed or has already been finalized.', null, 409);
            }
            return $this->importResponse(true, 'Import data staged successfully.', $stageResult);
        } catch (ImportHookException $exception) { return $this->importHookResponse($exception); }
        catch (ImportRowValidationException $exception) {
            return $this->importResponse(false, 'Import row validation exceptions are supported only by Before Stage hooks.', [
                'master_index' => $exception->getMasterIndex(), 'type' => $exception->getType(),
                'child_index' => $exception->getChildIndex(), 'row_index' => $exception->getRowIndex(),
                'errors' => $exception->getErrors(),
            ], 422);
        }
        catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first() ?: 'The staged import is invalid.';
            return $this->importResponse(false, $message, null, 422);
        }
        catch (Throwable $exception) { report($exception); return $this->importResponse(false, 'Import staging failed.', ['errors' => [['message' => $exception->getMessage()]]], 500); }
    }

    public function temporary(Request $request, string $endpoint, string $importUuid): JsonResponse
    {
        $config = $this->resolver->findByEndpoint($endpoint);
        if ($config === null) { return $this->importResponse(false, 'Import builder not found.', null, 404); }
        $batch = $this->stagingBatch($request, $config, $importUuid);
        if ($batch === null) { return $this->importResponse(false, 'Import staging batch not found.', null, 404); }
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $records = ImportStagingRecord::query()->where('import_uuid', $batch->import_uuid)->orderBy('execution_order');
        if (in_array($request->query('status'), ['success', 'failed'], true)) { $records->where('status', $request->query('status')); }
        if ($request->filled('table_name')) { $records->where('table_name', $request->query('table_name')); }
        return $this->importResponse(true, 'Temporary import data retrieved successfully.', ['import_uuid' => $batch->import_uuid, 'total' => $batch->total, 'success' => $batch->success_count, 'failed' => $batch->failed_count, 'records' => $records->paginate($perPage)]);
    }

    protected function stagingBatch(Request $request, ImportConfig $config, string $uuid): ?ImportStagingBatch
    {
        $query = ImportStagingBatch::query()->where('import_uuid', $uuid)->where('import_config_id', $config->id)
            ->where('tenant_key', trim((string) $request->header('X-Tenant', '')) ?: null);
        if ($request->user() !== null) { $query->where('user_id', $request->user()->getAuthIdentifier()); }
        return $query->first();
    }

    /**
     * Claim the batch with a row lock and keep that lock until the target-table
     * transaction and staging cleanup have both completed.
     */
    protected function finalizeStagedBatch(Request $request, ImportConfig $config, string $uuid): ?array
    {
        $connectionName = (string) $request->attributes->get('datasources.connection_name', '');
        return DB::connection($connectionName)->transaction(function () use ($request, $config, $uuid): ?array {
            $query = ImportStagingBatch::query()
                ->where('import_uuid', $uuid)
                ->where('import_config_id', $config->id)
                ->where('tenant_key', trim((string) $request->header('X-Tenant', '')) ?: null)
                ->lockForUpdate();
            if ($request->user() !== null) { $query->where('user_id', $request->user()->getAuthIdentifier()); }
            $batch = $query->first();
            if ($batch === null || $batch->status !== 'staged') { return null; }

            $batch->update(['status' => 'processing']);
            $summary = $this->processor->finalize($config, $request, (array) $batch->dataset);
            if ((int) ($summary['failed'] ?? 0) > 0) {
                throw ValidationException::withMessages([
                    'import_uuid' => ['Final import failed. Staged data was retained for retry.'],
                ]);
            }

            // This runs in the same outer transaction. If persistence or cleanup
            // fails, rollback restores the batch to staged for a safe retry.
            $batch->records()->delete();
            $batch->delete();
            return $summary;
        });
    }

    protected function stagingTableName(ImportConfig $config, array $row): ?string
    {
        $master = (string) ($row['master'] ?? ''); $worksheet = (string) ($row['worksheet'] ?? '');
        $parent = $config->masterParents->first(fn (ImportTable $table) => $this->masterNameForStaging($table) === $master);
        if (! $parent instanceof ImportTable) { return null; }
        if ((string) $parent->worksheet === $worksheet || $worksheet === '') { return $parent->table_name; }
        return $parent->children->first(fn (ImportTable $table) => (string) $table->worksheet === $worksheet)?->table_name ?? $parent->table_name;
    }

    protected function masterNameForStaging(ImportTable $table): string { return trim((string) $table->master_name) ?: trim((string) $table->table_name); }

    /** Logical relationship identity only. Never store a rollback-generated database ID. */
    protected function stagingParentRowKey(ImportConfig $config, array $row): ?string
    {
        $masterName = (string) ($row['master'] ?? ''); $worksheet = (string) ($row['worksheet'] ?? ''); $payload = (array) ($row['data'] ?? []);
        $parent = $config->masterParents->first(fn (ImportTable $table) => $this->masterNameForStaging($table) === $masterName);
        if (! $parent instanceof ImportTable) { return null; }
        $matchColumn = (string) $parent->parent_match_column;
        if ((string) $parent->worksheet !== $worksheet) {
            $child = $parent->children->first(fn (ImportTable $table) => (string) $table->worksheet === $worksheet);
            $matchColumn = (string) ($child?->child_match_column ?: $child?->parent_match_column ?: $matchColumn);
        }
        $matchValue = $payload[$matchColumn] ?? null;
        return $matchColumn !== '' && $matchValue !== null && $matchValue !== '' ? $masterName . '|' . (string) $matchValue : null;
    }

    /**
     * Finalize source-row statuses before saving the batch. A child may never
     * remain successful when its worksheet match points to a failed parent.
     */
    protected function finalizeStagingRowStatuses(ImportConfig $config, array $rows): array
    {
        $config->loadMissing('masterParents.children');
        foreach ($config->masterParents as $parent) {
            $masterName = $this->masterNameForStaging($parent);
            $parentSheet = (string) ($parent->worksheet ?? '');
            $parentMatchColumn = trim((string) ($parent->parent_match_column ?? ''));
            $failedParents = [];
            foreach ($rows as $row) {
                if (($row['master'] ?? '') !== $masterName || (string) ($row['worksheet'] ?? '') !== $parentSheet || ($row['status'] ?? '') !== 'failed') {
                    continue;
                }
                $value = $parentMatchColumn !== '' ? (($row['data'] ?? [])[$parentMatchColumn] ?? null) : null;
                if ($value !== null && $value !== '') { $failedParents[(string) $value] = true; }
            }

            foreach ($parent->children as $child) {
                $childSheet = (string) ($child->worksheet ?? $parentSheet);
                // Single-sheet reports contain the parent source row once; they
                // do not create an independently addressable child staging row.
                if ($childSheet === $parentSheet) { continue; }
                $matchColumn = trim((string) ($child->child_match_column ?? ''))
                    ?: trim((string) ($child->parent_match_column ?? ''))
                    ?: $parentMatchColumn;
                if ($matchColumn === '' || $failedParents === []) { continue; }
                foreach ($rows as &$row) {
                    if (($row['master'] ?? '') !== $masterName || (string) ($row['worksheet'] ?? '') !== $childSheet) {
                        continue;
                    }
                    $value = ($row['data'] ?? [])[$matchColumn] ?? null;
                    if ($value === null || $value === '' || ! isset($failedParents[(string) $value])) { continue; }
                    $row['status'] = 'failed';
                    $row['reason'] = 'Parent record is invalid or unavailable.';
                    $row['errors'] = [[
                        'column' => $matchColumn,
                        'message' => 'Parent record is invalid or unavailable.',
                    ]];
                }
                unset($row);
            }
        }

        return $rows;
    }

    /** Locate the processor's prepared target payload for the staging preview. */
    protected function stagingPreparedRow(array $dataset, array $record): array
    {
        $masterName = (string) ($record['master'] ?? '');
        $worksheet = (string) ($record['worksheet'] ?? '');
        $rowNumber = (int) ($record['row'] ?? 0);
        foreach ((array) ($dataset['masters'] ?? []) as $master) {
            if ((string) ($master['name'] ?? '') !== $masterName) {
                continue;
            }
            $collections = [[(array) ($master['parent'] ?? []), (string) ($master['worksheet'] ?? '')]];
            foreach ((array) ($master['children'] ?? []) as $index => $rows) {
                $collections[] = [(array) $rows, (string) ($master['child_worksheets'][$index] ?? $master['worksheet'] ?? '')];
            }
            foreach ($collections as [$rows, $rowWorksheet]) {
                if ($rowWorksheet !== $worksheet) {
                    continue;
                }
                foreach ($rows as $row) {
                    if ((int) ($row['row'] ?? 0) === $rowNumber) {
                        return [
                            'mapped_payload' => (array) ($row['mapped_payload'] ?? []),
                            'parent_row_key' => $row['parent_row_key'] ?? null,
                        ];
                    }
                }
            }
        }

        return [];
    }

    protected function successfulStagingDataset(array $dataset, array $records): array
    {
        $successful = [];
        foreach ($records as $record) {
            if (($record['status'] ?? null) === 'success') {
                $successful[(string) ($record['master'] ?? '') . '|' . (string) ($record['worksheet'] ?? '') . '|' . (int) ($record['row'] ?? 0)] = true;
            }
        }
        foreach ((array) ($dataset['masters'] ?? []) as $masterIndex => $master) {
            $filter = static function (array $rows, string $masterName, string $worksheet) use ($successful): array {
                return array_values(array_filter($rows, static fn (array $row): bool => isset($successful[$masterName . '|' . $worksheet . '|' . (int) ($row['row'] ?? 0)])));
            };
            $masterName = (string) ($master['name'] ?? ''); $worksheet = (string) ($master['worksheet'] ?? '');
            $dataset['masters'][$masterIndex]['parent'] = $filter((array) ($master['parent'] ?? []), $masterName, $worksheet);
            foreach ((array) ($master['children'] ?? []) as $childIndex => $children) {
                $childWorksheet = (string) ($master['child_worksheets'][$childIndex] ?? $worksheet);
                $dataset['masters'][$masterIndex]['children'][$childIndex] = $filter((array) $children, $masterName, $childWorksheet);
            }
        }
        return $dataset;
    }

    /**
     * Execute an import against the resolved request connection, then roll it
     * back and return the original worksheet rows with their test status.
     */
    public function test(Request $request, string $endpoint): JsonResponse
    {
        $config = $this->resolver->findByEndpoint($endpoint);

        if ($config === null) {
            return $this->importResponse(false, 'Import builder not found.', null, 404);
        }

        if (! $request->hasFile('file')) {
            return $this->importResponse(false, 'The file field is required.', null, 422);
        }

        try {
            $previewLimit = min(max((int) $request->input('preview_limit', 100), 1), 200);
            $result = $this->processor->test($config, $request, $previewLimit);
        } catch (ImportHookException $exception) {
            return $this->importHookResponse($exception);
        } catch (ValidationException $exception) {
            return $this->importValidationResponse($exception);
        } catch (Throwable $exception) {
            report($exception);
            return $this->importResponse(false, 'Import test failed.', ['errors' => [['message' => $exception->getMessage()]]], 500);
        }

        return $this->importResponse(true, 'Import test completed successfully.', $result);
    }

    protected function validatePayload(Request $request, bool $isCreate, ?ImportConfig $config = null): array|JsonResponse
    {
        $payload = $this->normalizeIncomingPayload($request->all());
        $rules = [
            'code' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:150',
                Rule::unique(DatabaseConnection::validationTable('import_configs'), 'code')->ignore($config?->id),
            ],
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'endpoint' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:255',
                'regex:/^(?!\.{1,2}(?:\/|$))(?!.*\/\.{1,2}(?:\/|$))[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/',
                Rule::unique(DatabaseConnection::validationTable('import_configs'), 'endpoint')->ignore($config?->id),
            ],
            'import_mode' => ['required', Rule::in(['INSERT', 'UPDATE', 'UPSERT'])],
            'enabled' => ['nullable', 'boolean'],
            'generate_before_execute_hook' => ['nullable', 'boolean'],
            'before_execute_hook_path' => ['nullable', 'string', 'max:255'],
            'generate_after_execute_hook' => ['nullable', 'boolean'],
            'after_execute_hook_path' => ['nullable', 'string', 'max:255'],
            'generate_before_stage_hook' => ['nullable', 'boolean'],
            'before_stage_hook_path' => ['nullable', 'string', 'max:255'],
            'generate_after_stage_hook' => ['nullable', 'boolean'],
            'after_stage_hook_path' => ['nullable', 'string', 'max:255'],
            'generate_before_final_hook' => ['nullable', 'boolean'],
            'before_final_hook_path' => ['nullable', 'string', 'max:255'],
            'generate_after_final_hook' => ['nullable', 'boolean'],
            'after_final_hook_path' => ['nullable', 'string', 'max:255'],
            'middlewares' => ['nullable', 'array'],
            'middlewares.*' => ['nullable', 'string'],
            'custom_parameters' => ['nullable', 'array'],
            'custom_parameters.*.name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'custom_parameters.*.type' => ['required', Rule::in(['string', 'integer', 'decimal', 'float', 'boolean', 'date', 'datetime'])],
            'custom_parameters.*.required' => ['nullable', 'boolean'],
            'custom_parameters.*.default' => ['nullable'],
            'custom_parameters.*.validation_rules' => ['nullable', 'string', 'max:1000'],
            'before_execute_hook' => ['nullable', 'string'],
            'after_execute_hook' => ['nullable', 'string'],
            'before_stage_hook' => ['nullable', 'string'],
            'after_stage_hook' => ['nullable', 'string'],
            'before_final_hook' => ['nullable', 'string'],
            'after_final_hook' => ['nullable', 'string'],
            'master_parents' => ['nullable', 'array'],
            'master_parents.*.name' => ['nullable', 'string', 'max:255'],
            'master_parents.*.table_name' => ['nullable', 'string'],
            'master_parents.*.primary_key' => ['nullable', 'string'],
            'master_parents.*.key_update_delete' => ['nullable', 'string'],
            'master_parents.*.worksheet' => ['nullable', 'string', 'max:255'],
            'master_parents.*.parent_match_column' => ['nullable', 'string', 'max:255'],
            'master_parents.*.import_mode' => ['nullable', Rule::in(['INSERT', 'UPDATE', 'UPSERT'])],
            'master_parents.*.use_soft_delete' => ['nullable', 'boolean'],
            'master_parents.*.data_params' => ['nullable', 'array'],
            'master_parents.*.children' => ['nullable', 'array'],
            'master_parents.*.children.*.table_name' => ['nullable', 'string'],
            'master_parents.*.children.*.foreign_key' => ['nullable', 'string'],
            'master_parents.*.children.*.primary_key' => ['nullable', 'string'],
            'master_parents.*.children.*.child_update_key' => ['nullable', 'string'],
            'master_parents.*.children.*.worksheet' => ['nullable', 'string', 'max:255'],
            'master_parents.*.children.*.parent_match_column' => ['nullable', 'string', 'max:255'],
            'master_parents.*.children.*.child_match_column' => ['nullable', 'string', 'max:255'],
            'master_parents.*.children.*.missing_child_strategy' => ['nullable', Rule::in(['KEEP_EXISTING', 'DELETE_MISSING'])],
            'master_parents.*.children.*.use_soft_delete' => ['nullable', 'boolean'],
            'master_parents.*.children.*.data_params' => ['nullable', 'array'],
            'parent_table' => ['nullable', 'array'],
            'parent_table.table_name' => ['nullable', 'string'],
            'parent_table.primary_key' => ['nullable', 'string'],
            'parent_table.key_update_delete' => ['nullable', 'string'],
            'parent_table.worksheet' => ['nullable', 'string', 'max:255'],
            'parent_table.parent_match_column' => ['nullable', 'string', 'max:255'],
            'parent_table.use_soft_delete' => ['nullable', 'boolean'],
            'parent_table.data_params' => ['nullable', 'array'],
            'child_tables' => ['nullable', 'array'],
            'child_tables.*.table_name' => ['nullable', 'string'],
            'child_tables.*.foreign_key' => ['nullable', 'string'],
            'child_tables.*.primary_key' => ['nullable', 'string'],
            'child_tables.*.child_update_key' => ['nullable', 'string'],
            'child_tables.*.worksheet' => ['nullable', 'string', 'max:255'],
            'child_tables.*.parent_match_column' => ['nullable', 'string', 'max:255'],
            'child_tables.*.child_match_column' => ['nullable', 'string', 'max:255'],
            'child_tables.*.missing_child_strategy' => ['nullable', Rule::in(['KEEP_EXISTING', 'DELETE_MISSING'])],
            'child_tables.*.use_soft_delete' => ['nullable', 'boolean'],
            'child_tables.*.data_params' => ['nullable', 'array'],
            'template_file' => [$isCreate ? 'required' : 'sometimes', 'file', 'mimes:xlsx,csv'],
        ];

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            return $this->importResponse(false, $validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }

        $parameterNames = array_map(
            static fn (array $parameter): string => strtolower(trim((string) ($parameter['name'] ?? ''))),
            (array) ($payload['custom_parameters'] ?? [])
        );
        if (count($parameterNames) !== count(array_unique($parameterNames))
            || array_intersect($parameterNames, ['file', 'template_file', '_method']) !== []) {
            return $this->importResponse(false, 'Custom parameter names must be unique and cannot use reserved request fields.', ['errors' => ['custom_parameters' => ['Duplicate or reserved custom parameter name.']]], 422);
        }

        if (($isCreate || array_key_exists('master_parents', $payload) || array_key_exists('parent_table', $payload) || array_key_exists('child_tables', $payload))
            && ! $this->hasWorksheetBackedTable($payload)) {
            return $this->importResponse(false, 'At least one table must use a worksheet. If your configuration does not require an Excel/CSV worksheet, use API Builder instead.', ['errors' => ['master_parents' => ['At least one master parent or child table must use a worksheet.']]], 422);
        }

        $insertModeErrors = $this->validateInsertModeLookupKeys($payload, $config);
        if ($insertModeErrors !== []) {
            return $this->importResponse(false, 'Import Builder configuration is invalid.', ['errors' => $insertModeErrors], 422);
        }

        $uniqueErrors = $this->validateDatabaseUniqueMappings($payload, $request, $config);
        if ($uniqueErrors !== []) {
            return $this->importResponse(false, 'Import Builder configuration is invalid.', ['errors' => $uniqueErrors], 422);
        }

        $requiredColumnErrors = $this->validateRequiredDatabaseMappings($payload, $request, $config);
        if ($requiredColumnErrors !== []) {
            return $this->importResponse(false, 'Import Builder configuration is invalid.', ['errors' => $requiredColumnErrors], 422);
        }

        return $validator->validated();
    }

    /** @return array<string, array<int, string>> */
    protected function validateInsertModeLookupKeys(array $payload, ?ImportConfig $existingConfig = null): array
    {
        $masters = (array) ($payload['master_parents'] ?? []);
        if ($masters === [] && $existingConfig !== null) {
            $existingConfig->loadMissing('masterParents');
            $masters = $existingConfig->masterParents->map(fn (ImportTable $table): array => $table->only(['import_mode', 'key_update_delete']))->all();
        }
        if ($masters === [] && is_array($payload['parent_table'] ?? null)) {
            $masters = [[
                'import_mode' => $payload['import_mode'] ?? null,
                'key_update_delete' => $payload['parent_table']['key_update_delete'] ?? null,
            ]];
        }

        $errors = [];
        foreach ($masters as $index => $master) {
            if (! is_array($master)) { continue; }
            if (strtoupper(trim((string) ($master['import_mode'] ?? $payload['import_mode'] ?? ''))) !== 'INSERT') { continue; }
            if (trim((string) ($master['key_update_delete'] ?? '')) !== '') {
                $key = count($masters) === 1 ? 'update_delete_key' : 'master_parents.' . $index . '.update_delete_key';
                $errors[$key][] = 'Update/Delete Key must be empty when Import Mode is INSERT.';
            }
        }

        return $errors;
    }

    /**
     * Enforce mapping flags only for database indexes that are truly unique on
     * one column. Composite indexes intentionally do not imply per-column
     * uniqueness.
     *
     * @return array<string, array<int, string>>
     */
    protected function validateDatabaseUniqueMappings(array $payload, Request $request, ?ImportConfig $existingConfig = null): array
    {
        $tables = [];
        $masters = (array) ($payload['master_parents'] ?? []);
        if ($masters !== []) {
            foreach ($masters as $master) {
                if (! is_array($master)) { continue; }
                $tables[] = $master;
                $masterMode = strtoupper(trim((string) ($master['import_mode'] ?? $payload['import_mode'] ?? 'UPSERT')));
                foreach ((array) ($master['children'] ?? []) as $child) {
                    if (is_array($child)) {
                        $child['_import_mode'] = $masterMode;
                        $tables[] = $child;
                    }
                }
            }
        } else {
            $legacyMode = strtoupper(trim((string) ($payload['import_mode'] ?? 'UPSERT')));
            if (is_array($payload['parent_table'] ?? null)) { $tables[] = $payload['parent_table']; }
            foreach ((array) ($payload['child_tables'] ?? []) as $child) {
                if (is_array($child)) {
                    $child['_import_mode'] = $legacyMode;
                    $tables[] = $child;
                }
            }
        }

        if ($tables === [] && $existingConfig !== null) {
            $existingConfig->loadMissing('masterParents.children', 'parentTable', 'childTables');
            $existingMasters = $existingConfig->masterParents->values();
            if ($existingMasters->isNotEmpty()) {
                foreach ($existingMasters as $master) {
                    $tables[] = $master->only(['table_name', 'data_params', 'import_mode']);
                    foreach ($master->children as $child) {
                        $childTable = $child->only(['table_name', 'data_params']);
                        $childTable['_import_mode'] = $master->import_mode ?? $existingConfig->import_mode;
                        $tables[] = $childTable;
                    }
                }
            } elseif ($existingConfig->parentTable !== null) {
                $tables[] = $existingConfig->parentTable->only(['table_name', 'data_params', 'import_mode']);
                foreach ($existingConfig->childTables as $child) {
                    $childTable = $child->only(['table_name', 'data_params']);
                    $childTable['_import_mode'] = $existingConfig->import_mode;
                    $tables[] = $childTable;
                }
            }
        }

        $connectionName = $this->executionConnectionResolver?->resolve($request);
        $errors = [];
        foreach ($tables as $table) {
            $tableName = trim((string) ($table['table_name'] ?? ''));
            if ($tableName === '') { continue; }
            $importMode = strtoupper(trim((string) ($table['_import_mode'] ?? $table['import_mode'] ?? $payload['import_mode'] ?? 'UPSERT')));
            if ($importMode !== 'INSERT') { continue; }

            try {
                $uniqueColumns = $this->singleColumnUniqueColumns($this->databaseMetadataProvider?->listIndexes($tableName, $connectionName) ?? []);
            } catch (Throwable $exception) {
                $errors[$tableName][] = 'Unable to inspect UNIQUE constraints for table \'' . $tableName . '\'.';
                continue;
            }

            foreach ((array) ($table['data_params'] ?? []) as $key => $mapping) {
                $column = trim((string) (is_array($mapping) ? ($mapping['column'] ?? $key) : $key));
                if ($column === '' || ! isset($uniqueColumns[$column])) { continue; }
                $isUnique = is_array($mapping) && filter_var($mapping['unique'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if (! $isUnique) {
                    $errors[$column][] = "Column '{$column}' is UNIQUE in table '{$tableName}'. Enable Unique for this mapping.";
                }
            }
        }

        return $errors;
    }

    /** @param array<int, array<string, mixed>> $indexes @return array<string, true> */
    protected function singleColumnUniqueColumns(array $indexes): array
    {
        $grouped = [];
        foreach ($indexes as $index) {
            if (empty($index['unique']) || ! empty($index['primary'])) { continue; }
            $name = trim((string) ($index['name'] ?? ''));
            $column = trim((string) ($index['column'] ?? ''));
            if ($name !== '' && $column !== '') { $grouped[$name][] = $column; }
        }

        $columns = [];
        foreach ($grouped as $indexColumns) {
            if (count($indexColumns) === 1) { $columns[$indexColumns[0]] = true; }
        }

        return $columns;
    }

    /**
     * Required target columns must be covered before an INSERT or UPSERT can
     * reach the import pipeline. UPDATE does not create a new database row.
     *
     * @return array<string, array<int, string>>
     */
    protected function validateRequiredDatabaseMappings(array $payload, Request $request, ?ImportConfig $existingConfig = null): array
    {
        $tables = $this->configuredImportTables($payload, $existingConfig);
        $connectionName = $this->executionConnectionResolver?->resolve($request);
        $errors = [];
        $columnsByTable = [];

        foreach ($tables as $table) {
            $tableName = trim((string) ($table['table_name'] ?? ''));
            $importMode = strtoupper(trim((string) ($table['import_mode'] ?? 'UPSERT')));
            if ($tableName === '' || ! in_array($importMode, ['INSERT', 'UPSERT'], true)) {
                continue;
            }

            $tableCacheKey = strtolower($tableName);
            if (! array_key_exists($tableCacheKey, $columnsByTable)) {
                try {
                    $columnsByTable[$tableCacheKey] = $this->databaseMetadataProvider?->listColumns($tableName, $connectionName) ?? [];
                } catch (Throwable $exception) {
                    $columnsByTable[$tableCacheKey] = null;
                }
            }
            $columns = $columnsByTable[$tableCacheKey];
            if ($columns === null) {
                $errors[$table['error_key']][] = "Unable to inspect required database columns for table '{$tableName}'.";
                continue;
            }

            $mappedColumns = $this->mappedDatabaseColumns((array) ($table['data_params'] ?? []));
            $automaticallyProvided = trim((string) ($table['foreign_key'] ?? ''));

            foreach ($columns as $column) {
                if (! is_array($column) || ! $this->isRequiredInsertColumn($column)) {
                    continue;
                }

                $columnName = trim((string) ($column['name'] ?? ''));
                if ($columnName === '' || isset($mappedColumns[strtolower($columnName)])) {
                    continue;
                }

                // Child foreign keys are populated from the resolved parent
                // context and must not require a duplicate mapping row.
                if ($automaticallyProvided !== '' && strcasecmp($automaticallyProvided, $columnName) === 0) {
                    continue;
                }

                $errors[$table['error_key']][] = "Required database column '{$columnName}' in table '{$tableName}' must be mapped.";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array{table_name:string, import_mode:string, data_params:array<mixed>, foreign_key:?string, error_key:string}>
     */
    protected function configuredImportTables(array $payload, ?ImportConfig $existingConfig = null): array
    {
        $tables = [];
        $masters = (array) ($payload['master_parents'] ?? []);

        if ($masters !== []) {
            foreach ($masters as $masterIndex => $master) {
                if (! is_array($master)) {
                    continue;
                }

                $mode = strtoupper(trim((string) ($master['import_mode'] ?? $payload['import_mode'] ?? 'UPSERT')));
                $tables[] = [
                    'table_name' => (string) ($master['table_name'] ?? ''),
                    'import_mode' => $mode,
                    'data_params' => (array) ($master['data_params'] ?? []),
                    'foreign_key' => null,
                    'error_key' => 'masters.' . $masterIndex . '.mappings',
                ];
                foreach ((array) ($master['children'] ?? []) as $childIndex => $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    $tables[] = [
                        'table_name' => (string) ($child['table_name'] ?? ''),
                        'import_mode' => $mode,
                        'data_params' => (array) ($child['data_params'] ?? []),
                        'foreign_key' => $this->nullableString($child['foreign_key'] ?? null),
                        'error_key' => 'masters.' . $masterIndex . '.children.' . $childIndex . '.mappings',
                    ];
                }
            }

            return $tables;
        }

        $mode = strtoupper(trim((string) ($payload['import_mode'] ?? $existingConfig?->import_mode ?? 'UPSERT')));
        if (is_array($payload['parent_table'] ?? null)) {
            $parent = $payload['parent_table'];
            $tables[] = [
                'table_name' => (string) ($parent['table_name'] ?? ''),
                'import_mode' => $mode,
                'data_params' => (array) ($parent['data_params'] ?? []),
                'foreign_key' => null,
                'error_key' => 'parent_table.mappings',
            ];
        }
        foreach ((array) ($payload['child_tables'] ?? []) as $childIndex => $child) {
            if (! is_array($child)) {
                continue;
            }
            $tables[] = [
                'table_name' => (string) ($child['table_name'] ?? ''),
                'import_mode' => $mode,
                'data_params' => (array) ($child['data_params'] ?? []),
                'foreign_key' => $this->nullableString($child['foreign_key'] ?? null),
                'error_key' => 'child_tables.' . $childIndex . '.mappings',
            ];
        }

        if ($tables !== [] || $existingConfig === null) {
            return $tables;
        }

        $existingConfig->loadMissing('masterParents.children', 'parentTable', 'childTables');
        foreach ($existingConfig->masterParents as $masterIndex => $master) {
            $masterMode = strtoupper(trim((string) ($master->import_mode ?: $existingConfig->import_mode ?: 'UPSERT')));
            $tables[] = [
                'table_name' => (string) $master->table_name,
                'import_mode' => $masterMode,
                'data_params' => (array) $master->data_params,
                'foreign_key' => null,
                'error_key' => 'masters.' . $masterIndex . '.mappings',
            ];
            foreach ($master->children as $childIndex => $child) {
                $tables[] = [
                    'table_name' => (string) $child->table_name,
                    'import_mode' => $masterMode,
                    'data_params' => (array) $child->data_params,
                    'foreign_key' => $this->nullableString($child->foreign_key),
                    'error_key' => 'masters.' . $masterIndex . '.children.' . $childIndex . '.mappings',
                ];
            }
        }

        return $tables;
    }

    /** @return array<string, true> */
    protected function mappedDatabaseColumns(array $mappings): array
    {
        $mapped = [];
        foreach ($mappings as $key => $mapping) {
            $column = is_array($mapping) ? ($mapping['column'] ?? $key) : $key;
            $source = is_array($mapping)
                ? ($mapping['value'] ?? $mapping['path'] ?? $mapping['source'] ?? null)
                : $mapping;
            $column = trim((string) $column);
            if ($column === '' || ! $this->hasMappingSource($source)) {
                continue;
            }
            $mapped[strtolower($column)] = true;
        }

        return $mapped;
    }

    protected function hasMappingSource(mixed $source): bool
    {
        return $source !== null && (! is_string($source) || trim($source) !== '');
    }

    /** @param array<string, mixed> $column */
    protected function isRequiredInsertColumn(array $column): bool
    {
        if (! empty($column['nullable']) || array_key_exists('default', $column) && $column['default'] !== null) {
            return false;
        }

        $extra = strtolower(trim((string) ($column['extra'] ?? '')));
        return ! str_contains($extra, 'auto_increment')
            && ! str_contains($extra, 'identity')
            && ! str_contains($extra, 'generated');
    }

    protected function hasWorksheetBackedTable(array $payload): bool
    {
        foreach ((array) ($payload['master_parents'] ?? []) as $master) {
            if (! is_array($master)) { continue; }
            if (trim((string) ($master['worksheet'] ?? '')) !== '') { return true; }
            foreach ((array) ($master['children'] ?? []) as $child) {
                if (is_array($child) && trim((string) ($child['worksheet'] ?? '')) !== '') { return true; }
            }
        }
        if (trim((string) (($payload['parent_table'] ?? [])['worksheet'] ?? '')) !== '') { return true; }
        foreach ((array) ($payload['child_tables'] ?? []) as $child) {
            if (is_array($child) && trim((string) ($child['worksheet'] ?? '')) !== '') { return true; }
        }
        return false;
    }

    protected function normalizePayload(array $payload, bool $isCreate): array
    {
        $normalized = [];

        if ($isCreate || array_key_exists('code', $payload)) {
            $normalized['code'] = trim((string) ($payload['code'] ?? ''));
        }
        if ($isCreate || array_key_exists('name', $payload)) {
            $normalized['name'] = trim((string) ($payload['name'] ?? ''));
        }
        if ($isCreate || array_key_exists('description', $payload)) {
            $normalized['description'] = $this->nullableString($payload['description'] ?? null);
        }
        if ($isCreate || array_key_exists('endpoint', $payload)) {
            $normalized['endpoint'] = $this->normalizeImportEndpoint($payload['endpoint'] ?? '');
        }
        if ($isCreate || array_key_exists('import_mode', $payload)) {
            $normalized['import_mode'] = strtoupper(trim((string) ($payload['import_mode'] ?? 'UPSERT'))) ?: 'UPSERT';
        }
        if ($isCreate || array_key_exists('enabled', $payload)) {
            $normalized['enabled'] = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : true;
        }
        if ($isCreate || array_key_exists('middlewares', $payload)) {
            $normalized['middlewares'] = $this->normalizeStringArray($payload['middlewares'] ?? null);
        }
        if ($isCreate || array_key_exists('custom_parameters', $payload)) {
            $normalized['custom_parameters'] = $this->normalizeCustomParameters($payload['custom_parameters'] ?? []);
        }
        if ($isCreate || array_key_exists('before_execute_hook', $payload)) {
            $normalized['before_execute_hook'] = $this->nullableString($payload['before_execute_hook'] ?? null);
        }
        if ($isCreate || array_key_exists('after_execute_hook', $payload)) {
            $normalized['after_execute_hook'] = $this->nullableString($payload['after_execute_hook'] ?? null);
        }
        foreach (['before_stage_hook', 'after_stage_hook', 'before_final_hook', 'after_final_hook'] as $hookKey) {
            if ($isCreate || array_key_exists($hookKey, $payload)) {
                $normalized[$hookKey] = $this->nullableString($payload[$hookKey] ?? null);
            }
        }

        return $normalized;
    }

    protected function normalizeIncomingPayload(array $payload): array
    {
        if (array_key_exists('endpoint', $payload)) {
            $payload['endpoint'] = $this->normalizeImportEndpoint($payload['endpoint']);
        }

        foreach ([
            'enabled',
            'generate_before_execute_hook', 'generate_after_execute_hook',
            'generate_before_stage_hook', 'generate_after_stage_hook',
            'generate_before_final_hook', 'generate_after_final_hook',
        ] as $booleanKey) {
            if (array_key_exists($booleanKey, $payload)) {
                $payload[$booleanKey] = $this->normalizeBooleanValue($payload[$booleanKey]);
            }
        }

        foreach (['master_parents', 'parent_table', 'child_tables', 'middlewares', 'custom_parameters'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            if (is_string($payload[$key]) && trim($payload[$key]) !== '') {
                $decoded = json_decode($payload[$key], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload[$key] = $decoded;
                }
            }
        }

        return $payload;
    }

    protected function prepareHookPayload(array $payload, bool $isCreate, ?ImportConfig $config = null): array
    {
        $hooks = [
            ['type' => 'before', 'generate' => 'generate_before_execute_hook', 'path' => 'before_execute_hook_path', 'config' => 'before_execute_hook'],
            ['type' => 'after', 'generate' => 'generate_after_execute_hook', 'path' => 'after_execute_hook_path', 'config' => 'after_execute_hook'],
            ['type' => 'before_stage', 'generate' => 'generate_before_stage_hook', 'path' => 'before_stage_hook_path', 'config' => 'before_stage_hook'],
            ['type' => 'after_stage', 'generate' => 'generate_after_stage_hook', 'path' => 'after_stage_hook_path', 'config' => 'after_stage_hook'],
            ['type' => 'before_final', 'generate' => 'generate_before_final_hook', 'path' => 'before_final_hook_path', 'config' => 'before_final_hook'],
            ['type' => 'after_final', 'generate' => 'generate_after_final_hook', 'path' => 'after_final_hook_path', 'config' => 'after_final_hook'],
        ];

        foreach ($hooks as $hook) {
            $type = $hook['type'];
            $generateKey = $hook['generate'];
            $pathKey = $hook['path'];
            $configKey = $hook['config'];

            if (! $isCreate && ! array_key_exists($generateKey, $payload)) {
                continue;
            }

            $generate = (bool) ($payload[$generateKey] ?? false);
            if (! $generate) {
                $payload[$configKey] = null;
                continue;
            }

            $defaultClass = $this->getImportHookClass($payload['code'] ?? $config?->code ?? '', $type);
            $hookClass = trim((string) ($payload[$pathKey] ?? '')) ?: $defaultClass;

            if ($hookClass === $defaultClass) {
                $this->ensureImportHook($hookClass, $type);
            } elseif (! class_exists($hookClass)) {
                throw ValidationException::withMessages([
                    $pathKey => [ucfirst($type) . ' execute hook class not found: ' . $hookClass],
                ]);
            }

            $payload[$configKey] = $hookClass;
            $payload[$pathKey] = $hookClass;
        }

        return $payload;
    }

    protected function getImportHookClass(string $code, string $type): string
    {
        $cleanCode = preg_replace('/[^A-Za-z0-9]/', ' ', $code);
        $cleanCode = str_replace(' ', '', ucwords((string) $cleanCode));
        $prefix = match ($type) {
            'before_stage' => 'BeforeStage',
            'after_stage' => 'AfterStage',
            'before_final' => 'BeforeFinal',
            'after_final' => 'AfterFinal',
            'before' => 'BeforeExecute',
            default => 'AfterExecute',
        };

        return 'App\\Hooks\\Import\\' . $prefix . $cleanCode . 'Hook';
    }

    protected function ensureImportHook(string $hookClass, string $type): void
    {
        $prefix = 'App\\Hooks\\Import\\';
        $className = str_starts_with($hookClass, $prefix)
            ? substr($hookClass, strlen($prefix))
            : '';

        if ($className === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className)) {
            throw ValidationException::withMessages([
                match ($type) {
                    'before_stage' => 'before_stage_hook_path',
                    'after_stage' => 'after_stage_hook_path',
                    'before_final' => 'before_final_hook_path',
                    'after_final' => 'after_final_hook_path',
                    'before' => 'before_execute_hook_path',
                    default => 'after_execute_hook_path',
                } => ['Generated hook path is invalid.'],
            ]);
        }

        $classPath = app_path('Hooks/Import/' . $className . '.php');
        if (File::exists($classPath)) {
            return;
        }

        File::ensureDirectoryExists(dirname($classPath));
        $isBeforeHook = str_starts_with($type, 'before');
        $interface = $isBeforeHook
            ? 'ImportBeforeExecuteHookInterface'
            : 'ImportAfterExecuteHookInterface';
        $method = $isBeforeHook
            ? <<<'METHOD'
    public function handle(
        array &$data,
        ImportConfig $importConfig,
        Request $request
    ): void {
        /*
         * The complete normalized dataset is available by reference.
         * $data['masters'] contains independent master groups. Each group has
         * name, parent rows, and its own configured child worksheet rows.
         * For one legacy master, parent and children aliases are also present.
         *
         * Example: normalize and modify parent rows:
         * $parentRows =& $data['masters'][0]['parent'];
         * foreach ($parentRows as $index => &$rowInfo) {
         *     $row =& $rowInfo['data'];
         *     $row['name'] = trim((string) ($row['name'] ?? ''));
         *     $row['email'] = strtolower(trim((string) ($row['email'] ?? '')));
         *     $row['import_source'] = 'excel';
         *     unset($row['temporary_column']);
         *
         *     if ($row['name'] === '') {
         *         throw ValidationException::withMessages([
         *             "rows.{$index}.name" => ['Name is required.'],
         *         ]);
         *     }
         * }
         * unset($row);
         *
         * Example: add or remove complete rows before persistence:
         * // unset($parentRows[3]);
         * // $parentRows[] = ['row' => 0, 'data' => ['name' => 'Added row']];
         *
         * Example: reject the complete import before any database write:
         * if (empty($parentRows)) {
         *     throw ValidationException::withMessages([
         *         'file' => ['The import contains no rows.'],
         *     ]);
         * }
         *
         * Example: stop the whole import request with a custom response:
         * throw new ImportHookException(
         *     409,
         *     'Data import tidak valid',
         *     [
         *         'reason' => 'Master tidak ditemukan',
         *     ],
         * );
         *
         * Before Stage only: reject one worksheet row and keep staging the
         * remaining rows. This row error is saved in the staging batch:
         * $childRows =& $data['masters'][0]['children'][0];
         * foreach ($childRows as $index => &$rowInfo) {
         *     $row =& $rowInfo['data'];
         *     if (($row['Kode Barang *'] ?? null) === 'YGP.0001') {
         *         throw new ImportRowValidationException(
         *             masterIndex: 0,
         *             type: 'child',
         *             childIndex: 0,
         *             rowIndex: $index,
         *             errors: [
         *                 'kode_barang' => ['Kode barang busuk.'],
         *             ],
         *         );
         *     }
         * }
         * unset($row);
         */
    }
METHOD
            : <<<'METHOD'
    public function handle(
        Request $request,
        ImportConfig $config,
        mixed $data
    ): mixed {
        $data['hook_metadata'] = [
            'processed_by' => static::class,
            'processed_at' => now()->toIso8601String(),
        ];

        Log::info('Import completed', [
            'import' => $config->code,
            'summary' => $data,
        ]);

        /*
         * ImportHookException also aborts After Stage/After Final processing.
         * The surrounding staging/final transaction is rolled back:
         * throw new ImportHookException(409, 'Import result cannot be completed.', [
         *     'reason' => 'Audit requirement failed',
         * ]);
         */

        // Add audit fields or append additional summary data here.
        return $data;
    }
METHOD;
        $imports = $isBeforeHook
            ? "use ESolution\\DataSources\\Contracts\\{$interface};\nuse ESolution\\DataSources\\Exceptions\\ImportHookException;\nuse ESolution\\DataSources\\Exceptions\\ImportRowValidationException;\nuse Illuminate\\Validation\\ValidationException;"
            : "use ESolution\\DataSources\\Contracts\\{$interface};\nuse ESolution\\DataSources\\Exceptions\\ImportHookException;\nuse Illuminate\\Support\\Facades\\Log;";

        $content = "<?php\n\nnamespace App\\Hooks\\Import;\n\nuse ESolution\\DataSources\\Models\\ImportConfig;\nuse Illuminate\\Http\\Request;\n{$imports}\n\nclass {$className} implements {$interface}\n{\n{$method}}\n";
        File::put($classPath, $content);
    }

    /**
     * Multipart form fields arrive as strings. Normalize only the boolean
     * spellings accepted by the API, leaving invalid values for validation.
     */
    protected function normalizeBooleanValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1' => true,
                'false', '0' => false,
                default => $value,
            };
        }

        return $value;
    }

    protected function storeTemplate(?UploadedFile $file, string $code): array
    {
        if ($file === null) {
            throw ValidationException::withMessages([
                'template_file' => ['Template file is required.'],
            ]);
        }

        $disk = 'local';
        $directory = 'import-builder/templates/' . trim($code) . '/' . now()->format('Y/m/d');
        $storedName = now()->format('His') . '-' . uniqid('', true) . '.' . strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs($directory, $storedName, $disk);

        $analysis = $this->templateReader->analyze($file);

        return [
            'attributes' => [
                'template_disk' => $disk,
                'template_path' => $path,
                'template_original_name' => $file->getClientOriginalName(),
                'template_type' => strtolower($file->getClientOriginalExtension() ?: 'bin'),
            ],
            'metadata' => $analysis['metadata'],
        ];
    }

    protected function syncRelations(ImportConfig $config, array $payload): void
    {
        if (array_key_exists('master_parents', $payload) && is_array($payload['master_parents'])) {
            $config->importTables()->delete();

            foreach ($payload['master_parents'] as $master) {
                if (! is_array($master)) {
                    continue;
                }

                $parentRecord = $config->masterParents()->create([
                    'parent_id' => 0,
                    'master_name' => $this->nullableString($master['name'] ?? null)
                        ?? $this->nullableString($master['table_name'] ?? null),
                    'import_mode' => strtoupper(trim((string) ($master['import_mode'] ?? ''))) ?: null,
                    'table_name' => $master['table_name'] ?? '',
                    'primary_key' => $master['primary_key'] ?? null,
                    'key_update_delete' => $master['key_update_delete'] ?? null,
                    'worksheet' => $master['worksheet'] ?? null,
                    'parent_match_column' => $master['parent_match_column'] ?? null,
                    'child_match_column' => null,
                    'use_soft_delete' => (bool) ($master['use_soft_delete'] ?? false),
                    'data_params' => $this->normalizeMappingDataParams($master['data_params'] ?? []),
                ]);

                foreach ((array) ($master['children'] ?? []) as $childTable) {
                    if (! is_array($childTable)) {
                        continue;
                    }

                    $parentRecord->children()->create(array_merge(
                        ['import_config_id' => $config->id],
                        $this->childTableAttributes($childTable)
                    ));
                }
            }

            return;
        }

        $parentRecord = null;
        if (array_key_exists('parent_table', $payload) && is_array($payload['parent_table'])) {
            $parent = $payload['parent_table'];
            $parentRecord = $config->parentTable()->updateOrCreate(
                ['parent_id' => 0],
                [
                    'table_name' => $parent['table_name'] ?? '',
                    'primary_key' => $parent['primary_key'] ?? null,
                    'key_update_delete' => $parent['key_update_delete'] ?? null,
                    'worksheet' => $parent['worksheet'] ?? null,
                    'parent_match_column' => $parent['parent_match_column'] ?? null,
                    'child_match_column' => null,
                    'use_soft_delete' => (bool) ($parent['use_soft_delete'] ?? false),
                    'data_params' => $this->normalizeMappingDataParams($parent['data_params'] ?? []),
                ]
            );
        }

        if (array_key_exists('child_tables', $payload) && is_array($payload['child_tables'])) {
            $children = $payload['child_tables'];
            $config->childTables()->delete();
            foreach ($children as $childTable) {
                if (! is_array($childTable)) {
                    continue;
                }

                $config->childTables()->create(array_merge(
                    ['parent_id' => (int) ($parentRecord?->id ?? $config->parentTable?->id ?? 0)],
                    $this->childTableAttributes($childTable)
                ));
            }
        }
    }

    protected function childTableAttributes(array $childTable): array
    {
        return [
            'table_name' => $childTable['table_name'] ?? '',
            'foreign_key' => $childTable['foreign_key'] ?? null,
            'primary_key' => $childTable['primary_key'] ?? null,
            'child_update_key' => $childTable['child_update_key'] ?? null,
            'worksheet' => $childTable['worksheet'] ?? null,
            'parent_match_column' => $childTable['parent_match_column'] ?? null,
            'child_match_column' => $childTable['child_match_column'] ?? null,
            'missing_child_strategy' => $childTable['missing_child_strategy'] ?? 'KEEP_EXISTING',
            'use_soft_delete' => (bool) ($childTable['use_soft_delete'] ?? false),
            'data_params' => $this->normalizeMappingDataParams($childTable['data_params'] ?? []),
        ];
    }

    protected function normalizeStringArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $value), static fn ($item) => $item !== ''));

        return $normalized === [] ? null : $normalized;
    }

    protected function normalizeCustomParameters(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static function (array $parameter): array {
            return [
                'name' => trim((string) ($parameter['name'] ?? '')),
                'type' => strtolower(trim((string) ($parameter['type'] ?? 'string'))),
                'required' => (bool) ($parameter['required'] ?? false),
                'default' => $parameter['default'] ?? null,
                'validation_rules' => trim((string) ($parameter['validation_rules'] ?? '')),
            ];
        }, array_filter($value, 'is_array')));
    }

    protected function normalizeMappingDataParams(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_map(static function (mixed $mapping): mixed {
            if (! is_array($mapping)) {
                return $mapping;
            }

            unset($mapping['array_handling'], $mapping['arrayHandling']);
            return $mapping;
        }, $value);
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    /** Keep a configured import path canonical without removing inner segments. */
    protected function normalizeImportEndpoint(mixed $endpoint): string
    {
        $endpoint = trim((string) $endpoint);
        $endpoint = trim($endpoint, '/');

        return preg_replace('#/+#', '/', $endpoint) ?? '';
    }
}
