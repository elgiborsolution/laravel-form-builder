<?php

namespace ESolution\DataSources\Controllers;

use App\Http\Controllers\Controller;
use ESolution\DataSources\Models\ImportConfig;
use ESolution\DataSources\Models\ImportTable;
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
        $query = ImportConfig::query()->with(['parentTable', 'childTables'])->orderBy('id');

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

        $config = ImportConfig::create($payload)->fresh(['parentTable', 'childTables']);
        $this->syncRelations($config, $validated);
        $this->resolver->forget($config->endpoint);

        return response()->json([
            'status' => 201,
            'message' => 'Import builder created successfully',
            'data' => $config->fresh(['parentTable', 'childTables']),
        ], 201);
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $config = ImportConfig::query()->with(['parentTable', 'childTables'])->find($id)
            ?? ImportConfig::query()->with(['parentTable', 'childTables'])->where('code', $id)->first();

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
        $config = $config->fresh(['parentTable', 'childTables']);
        $this->syncRelations($config, $validated);
        $config = $config->fresh(['parentTable', 'childTables']);

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

        if (! $request->hasFile('file')) {
            return response()->json(['message' => 'Import file is required.'], 422);
        }

        try {
            $summary = $this->processor->process($config, $request);
        } catch (ApiHookException $exception) {
            return response()->json(
                $exception->toResponsePayload(),
                $exception->getStatusCode()
            );
        }

        return response()->json($summary, 200);
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
            'before_execute_hook' => ['nullable', 'string'],
            'after_execute_hook' => ['nullable', 'string'],
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

        return $validator->validated();
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

        foreach (['parent_table', 'child_tables', 'middlewares'] as $key) {
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
         * $data['parent'] contains parent rows and $data['children'] contains
         * each configured child worksheet's rows.
         *
         * Example: normalize and modify parent rows:
         * $parentRows =& $data['parent'];
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

                $config->childTables()->create([
                    'parent_id' => (int) ($parentRecord?->id ?? $config->parentTable?->id ?? 0),
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
                ]);
            }
        }
    }

    protected function normalizeStringArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $value), static fn ($item) => $item !== ''));

        return $normalized === [] ? null : $normalized;
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
