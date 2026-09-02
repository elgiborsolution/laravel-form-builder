<?php

namespace ESolution\DataSources\Controllers;

use App\Http\Controllers\Controller;
use ESolution\DataSources\Models\ImportConfig;
use ESolution\DataSources\Models\ImportTable;
use ESolution\DataSources\Models\ImportStagingBatch;
use ESolution\DataSources\Models\ImportStagingRecord;
use ESolution\DataSources\Services\Import\ImportRecordProcessor;
use ESolution\DataSources\Services\Import\ImportTemplateReader;
use ESolution\DataSources\Exceptions\ApiHookException;
use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use ESolution\DataSources\Support\DatabaseConnection;
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

class ImportBuilderController extends Controller
{
    use AppliesSearchFilter;

    public function __construct(
        protected ImportConfigResolver $resolver,
        protected ImportTemplateReader $templateReader,
        protected ImportRecordProcessor $processor
    ) {
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

        return response()->json($query->paginate((int) $request->query('per_page', 10) ?: 10));
    }

    public function defaults(): JsonResponse
    {
        return response()->json([
            'data' => [
                'default_middlewares' => [],
                'import_mode_options' => ['INSERT', 'UPDATE', 'UPSERT'],
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'template_file' => ['required', 'file', 'mimes:xlsx,csv'],
        ])->validate();

        $analysis = $this->templateReader->analyze($request->file('template_file'));

        return response()->json([
            'data' => $analysis['metadata'],
        ]);
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

        return response()->json([
            'status' => 201,
            'message' => 'Import builder created successfully',
            'data' => $config->fresh(['parentTable', 'childTables', 'masterParents.children']),
        ], 201);
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->with(['parentTable', 'childTables', 'masterParents.children'])->find($id)
            ?? ImportConfig::query()->with(['parentTable', 'childTables', 'masterParents.children'])->where('code', $id)->first();

        if ($config === null) {
            return response()->json(['status' => 404, 'message' => 'Import builder not found'], 404);
        }

        return response()->json(['status' => 200, 'data' => $config], 200);
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->find($id);
        if ($config === null) {
            return response()->json(['status' => 404, 'message' => 'Import builder not found'], 404);
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

        return response()->json([
            'status' => 200,
            'message' => 'Import builder updated successfully',
            'data' => $config,
        ], 200);
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->find($id);

        if ($config === null) {
            return response()->json(['status' => 404, 'message' => 'Import builder not found'], 404);
        }

        $this->resolver->forget($config->endpoint);
        $config->delete();

        return response()->json(['status' => 200, 'message' => 'Import builder deleted successfully', 'data' => []], 200);
    }

    public function updateStatus(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->find($id);
        if ($config === null) {
            return response()->json(['status' => 404, 'message' => 'Import builder not found'], 404);
        }

        $payload = $this->normalizeIncomingPayload($request->all());
        $validated = Validator::make($payload, ['enabled' => ['required', 'boolean']])->validate();
        $config->update(['enabled' => (bool) $validated['enabled']]);
        $this->resolver->forget($config->endpoint);

        return response()->json(['status' => 200, 'message' => 'Status updated successfully', 'data' => $config->fresh()], 200);
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
            return response()->json(['message' => 'Import builder not found'], 404);
        }

        $hasImportUuid = $request->exists('import_uuid');
        $hasFile = $request->hasFile('file');
        if ($hasImportUuid && $hasFile) {
            return response()->json(['success' => false, 'message' => 'Provide either file for direct import or import_uuid for staged import finalization, not both.'], 422);
        }
        if (! $hasImportUuid && ! $hasFile) {
            return response()->json(['success' => false, 'message' => 'Either file or import_uuid is required.'], 422);
        }

        try {
            if (! $hasImportUuid) {
                return response()->json($this->processor->process($config, $request), 200);
            }
            $uuid = trim((string) $request->input('import_uuid', ''));
            if ($uuid === '') { return response()->json(['success' => false, 'message' => 'A valid import_uuid is required for final import.'], 422); }
            // Fail fast for inaccessible/consumed UUIDs. The locked claim below
            // remains authoritative for concurrent requests.
            $batch = $this->stagingBatch($request, $config, $uuid);
            if ($batch === null) { return response()->json(['message' => 'Staged import was not found.'], 404); }
            if ($batch->status !== 'staged') { return response()->json(['message' => 'This staged import is already being processed.'], 409); }
            $summary = $this->finalizeStagedBatch($request, $config, $uuid);
            if ($summary === null) { return response()->json(['message' => 'This staged import was already finalized or is being processed.'], 409); }
        } catch (ApiHookException $exception) {
            return response()->json(
                $exception->toResponsePayload(),
                $exception->getStatusCode()
            );
        }

        return response()->json($summary, 200);
    }

    public function stage(Request $request, string $endpoint): JsonResponse
    {
        $config = $this->resolver->findByEndpoint($endpoint);
        if ($config === null) { return response()->json(['message' => 'Import builder not found'], 404); }
        if ($request->exists('import_uuid') || ! $request->hasFile('file')) {
            return response()->json(['success' => false, 'message' => 'A file is required for staging and import_uuid is not accepted.'], 422);
        }
        try {
            $report = $this->processor->stage($config, $request);
            $uuid = (string) Str::uuid();
            $batch = ImportStagingBatch::create([
                'import_uuid' => $uuid, 'import_config_id' => $config->id, 'user_id' => $request->user()?->getAuthIdentifier(),
                'tenant_key' => trim((string) $request->header('X-Tenant', '')) ?: null,
                'connection_name' => (string) $request->attributes->get('datasources.connection_name', ''), 'status' => 'staged',
                'total' => $report['total'], 'success_count' => $report['success'], 'failed_count' => $report['failed'],
                'dataset' => $this->successfulStagingDataset((array) ($report['staging_dataset'] ?? []), (array) ($report['rows'] ?? [])),
            ]);
            foreach ((array) ($report['rows'] ?? []) as $order => $row) {
                ImportStagingRecord::create([
                    'import_uuid' => $uuid, 'import_config_id' => $config->id, 'master_name' => $row['master'] ?? null,
                    'table_name' => $this->stagingTableName($config, $row), 'row_no' => (int) ($row['row'] ?? 0),
                    'parent_row_key' => $this->stagingParentRowKey($config, $row), 'payload' => $row['data'] ?? [],
                    'status' => $row['status'] ?? 'failed', 'errors' => $row['errors'] ?? null, 'execution_order' => $order,
                ]);
            }
            return response()->json(['success' => true, 'data' => ['import_uuid' => $batch->import_uuid, 'total' => $batch->total, 'success' => $batch->success_count, 'failed' => $batch->failed_count]]);
        } catch (ApiHookException $exception) { return response()->json($exception->toResponsePayload(), $exception->getStatusCode()); }
    }

    public function temporary(Request $request, string $endpoint, string $importUuid): JsonResponse
    {
        $config = $this->resolver->findByEndpoint($endpoint);
        if ($config === null) { return response()->json(['message' => 'Import builder not found'], 404); }
        $batch = $this->stagingBatch($request, $config, $importUuid);
        if ($batch === null) { return response()->json(['message' => 'Staged import was not found.'], 404); }
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $records = ImportStagingRecord::query()->where('import_uuid', $batch->import_uuid)->orderBy('execution_order');
        if (in_array($request->query('status'), ['success', 'failed'], true)) { $records->where('status', $request->query('status')); }
        if ($request->filled('table')) { $records->where('table_name', $request->query('table')); }
        return response()->json(['success' => true, 'data' => ['import_uuid' => $batch->import_uuid, 'total' => $batch->total, 'success' => $batch->success_count, 'failed' => $batch->failed_count, 'records' => $records->paginate($perPage)]]);
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
            return response()->json(['message' => 'Import builder not found'], 404);
        }

        if (! $request->hasFile('file')) {
            return response()->json(['message' => 'Import file is required.'], 422);
        }

        try {
            $result = $this->processor->test($config, $request);
        } catch (ApiHookException $exception) {
            return response()->json(
                $exception->toResponsePayload(),
                $exception->getStatusCode()
            );
        }

        return response()->json(['success' => true, 'data' => $result], 200);
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
                Rule::unique(DatabaseConnection::validationTable('import_configs'), 'endpoint')->ignore($config?->id),
            ],
            'import_mode' => ['required', Rule::in(['INSERT', 'UPDATE', 'UPSERT'])],
            'enabled' => ['nullable', 'boolean'],
            'generate_before_execute_hook' => ['nullable', 'boolean'],
            'before_execute_hook_path' => ['nullable', 'string', 'max:255'],
            'generate_after_execute_hook' => ['nullable', 'boolean'],
            'after_execute_hook_path' => ['nullable', 'string', 'max:255'],
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
            return response()->json([
                'status' => 422,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $parameterNames = array_map(
            static fn (array $parameter): string => strtolower(trim((string) ($parameter['name'] ?? ''))),
            (array) ($payload['custom_parameters'] ?? [])
        );
        if (count($parameterNames) !== count(array_unique($parameterNames))
            || array_intersect($parameterNames, ['file', 'template_file', '_method']) !== []) {
            return response()->json([
                'status' => 422,
                'message' => 'Custom parameter names must be unique and cannot use reserved request fields.',
                'errors' => ['custom_parameters' => ['Duplicate or reserved custom parameter name.']],
            ], 422);
        }

        if (($isCreate || array_key_exists('master_parents', $payload) || array_key_exists('parent_table', $payload) || array_key_exists('child_tables', $payload))
            && ! $this->hasWorksheetBackedTable($payload)) {
            return response()->json([
                'status' => 422,
                'message' => 'At least one table must use a worksheet. If your configuration does not require an Excel/CSV worksheet, use API Builder instead.',
                'errors' => ['master_parents' => ['At least one master parent or child table must use a worksheet.']],
            ], 422);
        }

        return $validator->validated();
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
            $normalized['endpoint'] = trim((string) ($payload['endpoint'] ?? ''));
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

        return $normalized;
    }

    protected function normalizeIncomingPayload(array $payload): array
    {
        foreach (['enabled', 'generate_before_execute_hook', 'generate_after_execute_hook'] as $booleanKey) {
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
        foreach (['before', 'after'] as $type) {
            $generateKey = 'generate_' . $type . '_execute_hook';
            $pathKey = $type . '_execute_hook_path';
            $legacyKey = $type . '_execute_hook';

            if (! $isCreate && ! array_key_exists($generateKey, $payload)) {
                continue;
            }

            $generate = (bool) ($payload[$generateKey] ?? false);
            if (! $generate) {
                $payload[$legacyKey] = null;
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

            $payload[$legacyKey] = $hookClass;
            $payload[$pathKey] = $hookClass;
        }

        return $payload;
    }

    protected function getImportHookClass(string $code, string $type): string
    {
        $cleanCode = preg_replace('/[^A-Za-z0-9]/', ' ', $code);
        $cleanCode = str_replace(' ', '', ucwords((string) $cleanCode));
        $prefix = $type === 'before' ? 'BeforeExecute' : 'AfterExecute';

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
                $type . '_execute_hook_path' => ['Generated hook path is invalid.'],
            ]);
        }

        $classPath = app_path('Hooks/Import/' . $className . '.php');
        if (File::exists($classPath)) {
            return;
        }

        File::ensureDirectoryExists(dirname($classPath));
        $interface = $type === 'before'
            ? 'ImportBeforeExecuteHookInterface'
            : 'ImportAfterExecuteHookInterface';
        $method = $type === 'before'
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
         * Example: return a custom import error with row context:
         * throw new ApiHookException(
         *     409,
         *     'Data import tidak valid',
         *     [
         *         'row' => 12,
         *         'column' => 'email',
         *         'reason' => 'Email sudah digunakan',
         *     ],
         * );
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

        // Add audit fields or append additional summary data here.
        return $data;
    }
METHOD;
        $imports = $type === 'before'
            ? "use ESolution\\DataSources\\Contracts\\{$interface};\nuse ESolution\\DataSources\\Exceptions\\ApiHookException;\nuse Illuminate\\Validation\\ValidationException;"
            : "use ESolution\\DataSources\\Contracts\\{$interface};\nuse Illuminate\\Support\\Facades\\Log;";

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
}
