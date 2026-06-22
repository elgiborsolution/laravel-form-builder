<?php
namespace ESolution\DataSources\Controllers;

use App\Http\Controllers\Controller;
use ESolution\DataSources\Http\Requests\FormBuilderStatusRequest;
use ESolution\DataSources\Http\Requests\FormBuilderStoreRequest;
use ESolution\DataSources\Http\Requests\FormBuilderUpdateRequest;
use ESolution\DataSources\Models\FormBuilder;
use ESolution\DataSources\Resources\FormBuilderResource;
use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FormBuilderController extends Controller
{
    use AppliesSearchFilter;

    private const EXPORT_VERSION = 1;

    public function index(Request $request): JsonResponse
    {
        $query = FormBuilder::query()->select([
            'id',
            'code',
            'name',
            'description',
            'enabled',
            'schema',
            'created_at',
            'updated_at',
        ]);

        if (trim((string) $request->query('search', '')) !== '') {
            $query = $this->applySearchFilter($query, $request, ['code', 'name', 'description']);
        }

        $enabled = $request->query('enabled');
        if ($enabled !== null && $enabled !== '') {
            $query->where('enabled', filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
        }

        $sortBy = $this->normalizeSortColumn($request->query('sort_by', 'id'));
        $sortDirection = $this->normalizeSortDirection($request->query('sort_direction', 'desc'));
        $query->orderBy($sortBy, $sortDirection);

        $page = (int) $request->query('page', 0);
        $perPage = (int) $request->query('per_page', 0);

        if ($page > 0 || $perPage > 0) {
            $paginator = $query->paginate($perPage > 0 ? $perPage : 10);

            return response()->json([
                'status' => 200,
                'message' => 'Data retrieved successfully',
                'data' => FormBuilderResource::summaries($paginator->getCollection()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Data retrieved successfully',
            'data' => FormBuilderResource::summaries($query->get()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $requestRules = new FormBuilderStoreRequest();
        $payload = $this->validatePayload($request, $requestRules->rules(), $requestRules->messages());

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $formBuilder = FormBuilder::create($payload)->fresh();
        if ($formBuilder instanceof FormBuilder) {
            $this->cacheFormBuilderDetail($formBuilder);
        }

        return response()->json([
            'status' => 201,
            'message' => 'Form created successfully',
            'data' => $formBuilder instanceof FormBuilder ? FormBuilderResource::detail($formBuilder) : [],
        ], 201);
    }

    public function showById(Request $request, int|string $id): JsonResponse
    {
        $formBuilder = FormBuilder::query()->find($id);

        if ($formBuilder === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Form builder not found',
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => FormBuilderResource::detail($formBuilder),
        ]);
    }

    public function showByCode(Request $request, string $code): JsonResponse
    {
        $cacheKey = $this->formBuilderCacheKey($code);
        $payload = Cache::get($cacheKey);

        if (! is_array($payload)) {
            $formBuilder = FormBuilder::query()->where('code', $code)->first();

            if ($formBuilder === null) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Form builder not found',
                ], 404);
            }

            $payload = FormBuilderResource::detail($formBuilder);
            Cache::forever($cacheKey, $payload);
        }

        return response()->json([
            'status' => 200,
            'data' => $payload,
        ]);
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $formBuilder = FormBuilder::query()->find($id);

        if ($formBuilder === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Form builder not found',
            ], 404);
        }

        $payload = $this->validatePayload(
            $request,
            (new FormBuilderUpdateRequest($formBuilder))->rules()
        );

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $originalCode = $formBuilder->code;
        $formBuilder->fill($payload);
        $formBuilder->save();
        $formBuilder = $formBuilder->fresh();

        $this->forgetFormBuilderCache($originalCode);
        if ($formBuilder instanceof FormBuilder) {
            $this->cacheFormBuilderDetail($formBuilder);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Form updated successfully',
            'data' => $formBuilder instanceof FormBuilder ? FormBuilderResource::detail($formBuilder) : [],
        ]);
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $formBuilder = FormBuilder::query()->find($id);

        if ($formBuilder === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Form builder not found',
            ], 404);
        }

        $this->forgetFormBuilderCache($formBuilder->code);
        $formBuilder->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Form deleted successfully',
            'data' => [],
        ]);
    }

    public function updateStatus(Request $request, int|string $id): JsonResponse
    {
        $formBuilder = FormBuilder::query()->find($id);

        if ($formBuilder === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Form builder not found',
            ], 404);
        }

        $payload = $this->validatePayload(
            $request,
            (new FormBuilderStatusRequest())->rules()
        );

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $formBuilder->update([
            'enabled' => (bool) $payload['enabled'],
        ]);
        $freshFormBuilder = $formBuilder->fresh();
        if ($freshFormBuilder instanceof FormBuilder) {
            $this->forgetFormBuilderCache($freshFormBuilder->code);
            $this->cacheFormBuilderDetail($freshFormBuilder);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Status updated successfully',
            'data' => $freshFormBuilder instanceof FormBuilder ? FormBuilderResource::detail($freshFormBuilder) : [],
        ]);
    }

    public function export(Request $request)
    {
        $selectedIds = $this->normalizeSelectedIds($request->input('ids', []));
        $selectedCodes = $this->normalizeSelectedCodes($request->input('codes', []));

        if ($selectedIds === [] && $selectedCodes === []) {
            return response()->json([
                'status' => 422,
                'message' => 'The ids or codes field is required.',
                'errors' => [
                    'ids' => ['Select at least one form builder by ids or codes.'],
                ],
            ], 422);
        }

        $query = FormBuilder::query()->orderBy('id');
        $query->where(function ($builder) use ($selectedIds, $selectedCodes): void {
            if ($selectedIds !== []) {
                $builder->orWhereIn('id', $selectedIds);
            }

            if ($selectedCodes !== []) {
                $builder->orWhereIn('code', $selectedCodes);
            }
        });

        return $this->streamExportPayload(
            $this->buildExportPayload($query->get()),
            'form-builders-' . now()->format('Y-m-d_His') . '.json'
        );
    }

    public function exportAll(Request $request)
    {
        return $this->streamExportPayload(
            $this->buildExportPayload(FormBuilder::query()->orderBy('id')->get()),
            'form-builders-all-' . now()->format('Y-m-d_His') . '.json'
        );
    }

    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file'],
            'mode' => ['nullable', Rule::in(['skip', 'update'])],
        ], [
            'file.required' => 'The file field is required.',
            'file.file' => 'The uploaded file must be a valid file.',
            'mode.in' => 'The mode field must be either skip or update.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return response()->json([
                'status' => 422,
                'message' => 'The file field is required.',
                'errors' => [
                    'file' => ['The file field is required.'],
                ],
            ], 422);
        }

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'json') {
            return response()->json([
                'status' => 422,
                'message' => 'The file must be a JSON file.',
                'errors' => [
                    'file' => ['The file must be a JSON file.'],
                ],
            ], 422);
        }

        $contents = @file_get_contents($file->getRealPath());

        if ($contents === false || trim($contents) === '') {
            return response()->json([
                'status' => 422,
                'message' => 'The uploaded file is empty.',
                'errors' => [
                    'file' => ['The uploaded file is empty.'],
                ],
            ], 422);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json([
                'status' => 422,
                'message' => 'The uploaded file must contain valid JSON.',
                'errors' => [
                    'file' => ['The uploaded file must contain valid JSON.'],
                ],
            ], 422);
        }

        $normalizedPayload = $this->normalizeImportPayload($decoded);

        if ($normalizedPayload === null) {
            return response()->json([
                'status' => 422,
                'message' => 'Invalid export structure.',
                'errors' => [
                    'file' => ['The export structure is invalid.'],
                ],
            ], 422);
        }

        $items = $normalizedPayload['items'];
        $mode = (string) $request->input('mode', 'skip');
        $summary = [
            'selected' => count($items),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $errors = [];

        $connection = FormBuilder::query()->getConnection();

        try {
            $connection->transaction(function () use ($items, $mode, &$summary, &$errors): void {
                foreach ($items as $index => $item) {
                    $rowNumber = $index + 1;

                    if (! is_array($item)) {
                        $summary['failed']++;
                        $errors[] = [
                            'row' => $rowNumber,
                            'message' => 'Each item must be an object.',
                        ];
                        continue;
                    }

                    $validated = $this->validateImportItem($item, $rowNumber);

                    if (isset($validated['error'])) {
                        $summary['failed']++;
                        $errors[] = [
                            'row' => $rowNumber,
                            'message' => $validated['error'],
                        ];
                        continue;
                    }

                    $existing = FormBuilder::query()->where('code', $validated['data']['code'])->first();

                    if ($existing instanceof FormBuilder) {
                        if ($mode === 'skip') {
                            $summary['skipped']++;
                            continue;
                        }

                        $originalCode = $existing->code;
                        $existing->fill($validated['data']);
                        $existing->save();
                        $updated = $existing->fresh();

                        $this->forgetFormBuilderCache($originalCode);
                        if ($updated instanceof FormBuilder) {
                            $this->cacheFormBuilderDetail($updated);
                        }

                        $summary['updated']++;
                        continue;
                    }

                    $created = FormBuilder::create($validated['data'])->fresh();
                    if ($created instanceof FormBuilder) {
                        $this->cacheFormBuilderDetail($created);
                    }

                    $summary['created']++;
                }
            });
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to import form builders.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Import completed.',
            'mode' => $mode,
            'summary' => $summary,
            'errors' => $errors,
        ]);
    }

    public function docs(): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'data' => $this->buildDocsPayload(),
        ]);
    }

    public function postman()
    {
        return $this->streamExportPayload(
            $this->buildPostmanCollection(),
            'form-builder.postman_collection.json'
        );
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>|JsonResponse
     */
    protected function validatePayload(Request $request, array $rules, array $messages = []): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        return $validator->validated();
    }

    protected function normalizeSortColumn(mixed $value): string
    {
        $column = trim((string) $value);

        return in_array($column, ['id', 'code', 'name', 'enabled', 'created_at', 'updated_at'], true)
            ? $column
            : 'id';
    }

    protected function normalizeSortDirection(mixed $value): string
    {
        $direction = strtolower(trim((string) $value));

        return in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';
    }

    protected function formBuilderCacheKey(string $code): string
    {
        return 'form_builder:' . trim($code);
    }

    protected function cacheFormBuilderDetail(FormBuilder $formBuilder): void
    {
        $code = trim((string) $formBuilder->code);

        if ($code === '') {
            return;
        }

        Cache::forever(
            $this->formBuilderCacheKey($code),
            FormBuilderResource::detail($formBuilder)
        );
    }

    protected function forgetFormBuilderCache(?string $code): void
    {
        $normalizedCode = trim((string) $code);

        if ($normalizedCode === '') {
            return;
        }

        Cache::forget($this->formBuilderCacheKey($normalizedCode));
    }

    /**
     * @param iterable<int, FormBuilder> $items
     * @return array{version:int,exported_at:string,items:array<int, array<string, mixed>>}
     */
    protected function buildExportPayload(iterable $items): array
    {
        $exportedItems = [];

        foreach ($items as $item) {
            if (! $item instanceof FormBuilder) {
                continue;
            }

            $exportedItems[] = [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'description' => $item->description,
                'enabled' => (bool) $item->enabled,
                'schema' => FormBuilderResource::schema($item),
                'created_at' => $this->formatTimestamp($item->created_at),
                'updated_at' => $this->formatTimestamp($item->updated_at),
            ];
        }

        return [
            'version' => self::EXPORT_VERSION,
            'exported_at' => now()->format('Y-m-d H:i:s'),
            'items' => $exportedItems,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{version:int,exported_at:string,items:array<int, array<string, mixed>>}|null
     */
    protected function normalizeImportPayload(array $payload): ?array
    {
        $items = $payload['items'] ?? null;

        if ($items === null && $this->isSequentialArray($payload)) {
            $items = $payload;
            $payload = [
                'version' => self::EXPORT_VERSION,
                'exported_at' => now()->format('Y-m-d H:i:s'),
                'items' => $items,
            ];
        }

        if (! is_array($items) || $items === []) {
            return null;
        }

        if (! $this->isExportStructureValid($payload)) {
            return null;
        }

        return [
            'version' => (int) ($payload['version'] ?? self::EXPORT_VERSION),
            'exported_at' => (string) ($payload['exported_at'] ?? now()->format('Y-m-d H:i:s')),
            'items' => array_values($items),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{data:array<string, mixed>}|array{error:string}
     */
    protected function validateImportItem(array $item, int $rowNumber): array
    {
        $validator = Validator::make($item, [
            'code' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
            'schema' => ['required'],
        ], [
            'code.required' => "Row {$rowNumber}: the code field is required.",
            'name.required' => "Row {$rowNumber}: the name field is required.",
            'schema.required' => "Row {$rowNumber}: the schema field is required.",
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        $schema = $this->normalizeImportSchemaValue($item['schema'] ?? null);

        if ($schema === null) {
            return ['error' => "Row {$rowNumber}: the schema field must contain valid JSON data."];
        }

        return [
            'data' => [
                'code' => trim((string) $item['code']),
                'name' => trim((string) $item['name']),
                'description' => array_key_exists('description', $item) ? $item['description'] : null,
                'enabled' => array_key_exists('enabled', $item) ? $this->normalizeBooleanValue($item['enabled']) : true,
                'schema' => $schema,
            ],
        ];
    }

    /**
     * @param mixed $schema
     * @return array<string, mixed>|array<int, mixed>|object|null
     */
    protected function normalizeImportSchemaValue(mixed $schema): array|object|null
    {
        if (is_array($schema) || is_object($schema)) {
            return $schema;
        }

        if (! is_string($schema) || trim($schema) === '') {
            return null;
        }

        try {
            $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $value
     * @return array<int, int|string>
     */
    protected function normalizeSelectedIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $id): int|string|null {
            if (! is_int($id) && ! is_string($id)) {
                return null;
            }

            $trimmed = trim((string) $id);

            if ($trimmed === '') {
                return null;
            }

            return ctype_digit($trimmed) ? (int) $trimmed : $trimmed;
        }, $value), static fn (mixed $id): bool => $id !== null));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    protected function normalizeSelectedCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $code): ?string {
            if (! is_string($code) && ! is_int($code)) {
                return null;
            }

            $trimmed = trim((string) $code);

            return $trimmed === '' ? null : $trimmed;
        }, $value), static fn (mixed $code): bool => is_string($code) && $code !== ''));
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
     */
    protected function streamExportPayload(array $payload, string $filename)
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to generate export file.',
            ], 500);
        }

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $filename,
            [
                'Content-Type' => 'application/json',
            ]
        );
    }

    protected function buildDocsPayload(): array
    {
        $exportExample = [
            'version' => self::EXPORT_VERSION,
            'exported_at' => '2026-06-22 10:00:00',
            'items' => [
                [
                    'id' => 1,
                    'code' => 'FORM_CUSTOMER',
                    'name' => 'Customer Form',
                    'description' => 'Customer master form',
                    'enabled' => true,
                    'schema' => [
                        [
                            'name' => 'customer_name',
                            'type' => 'string',
                        ],
                    ],
                    'created_at' => '2026-06-22 09:30:00',
                    'updated_at' => '2026-06-22 09:45:00',
                ],
            ],
        ];

        return [
            'title' => 'Form Builder API Documentation',
            'version' => self::EXPORT_VERSION,
            'base_path' => '/api/form-builder',
            'endpoints' => [
                [
                    'name' => 'Export Selected',
                    'method' => 'POST',
                    'path' => '/api/form-builder/export',
                    'description' => 'Export a selected set of form builders using ids or codes.',
                    'request' => [
                        'content_type' => 'application/json',
                        'body_examples' => [
                            ['ids' => [1, 2, 3]],
                            ['codes' => ['FORM_CUSTOMER', 'FORM_PRODUCT']],
                        ],
                    ],
                    'response' => [
                        'type' => 'download',
                        'content_type' => 'application/json',
                        'example' => $exportExample,
                    ],
                ],
                [
                    'name' => 'Export All',
                    'method' => 'GET',
                    'path' => '/api/form-builder/export-all',
                    'description' => 'Export every form builder configuration in a single JSON file.',
                    'response' => [
                        'type' => 'download',
                        'content_type' => 'application/json',
                        'example' => $exportExample,
                    ],
                ],
                [
                    'name' => 'Import',
                    'method' => 'POST',
                    'path' => '/api/form-builder/import',
                    'description' => 'Import a JSON export file using multipart/form-data.',
                    'request' => [
                        'content_type' => 'multipart/form-data',
                        'fields' => [
                            ['key' => 'file', 'type' => 'file', 'required' => true],
                            ['key' => 'mode', 'type' => 'text', 'required' => false, 'allowed_values' => ['skip', 'update']],
                        ],
                        'examples' => [
                            ['mode' => 'skip'],
                            ['mode' => 'update'],
                        ],
                    ],
                    'response' => [
                        'type' => 'json',
                        'example' => [
                            'status' => 200,
                            'message' => 'Import completed.',
                            'mode' => 'skip',
                            'summary' => [
                                'selected' => 1,
                                'created' => 1,
                                'updated' => 0,
                                'skipped' => 0,
                                'failed' => 0,
                            ],
                            'errors' => [],
                        ],
                    ],
                ],
            ],
            'validation' => [
                'file_required' => 'The file field is required.',
                'json_required' => 'The file must contain valid JSON.',
                'items_required' => 'The items field must contain at least one item.',
                'code_required' => 'Each item must include a code.',
                'schema_required' => 'Each item must include a valid schema.',
            ],
            'request_examples' => [
                'export_selected_by_ids' => ['ids' => [1, 2, 3]],
                'export_selected_by_codes' => ['codes' => ['FORM_CUSTOMER', 'FORM_PRODUCT']],
                'import_skip' => ['mode' => 'skip'],
                'import_update' => ['mode' => 'update'],
            ],
            'response_examples' => [
                'export' => $exportExample,
                'import' => [
                    'status' => 200,
                    'message' => 'Import completed.',
                    'mode' => 'update',
                    'summary' => [
                        'selected' => 1,
                        'created' => 0,
                        'updated' => 1,
                        'skipped' => 0,
                        'failed' => 0,
                    ],
                    'errors' => [],
                ],
            ],
        ];
    }

    protected function buildPostmanCollection(): array
    {
        $baseUrl = '{{baseUrl}}';

        return [
            'info' => [
                '_postman_id' => 'c4b7d7a8-51cb-4ad7-bdc8-11d0e5bd5b91',
                'name' => 'Laravel Form Builder - Form Builder Management',
                'description' => 'Postman Collection v2.1 for Form Builder endpoints including CRUD, export, import, docs, and collection download.',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => [
                ['key' => 'baseUrl', 'value' => 'http://localhost'],
                ['key' => 'formBuilderId', 'value' => '1'],
                ['key' => 'formBuilderCode', 'value' => 'FORM_CUSTOMER'],
            ],
            'item' => [
                [
                    'name' => 'List Form Builder',
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder'],
                            'query' => [
                                ['key' => 'search', 'value' => 'customer'],
                                ['key' => 'page', 'value' => '1'],
                                ['key' => 'per_page', 'value' => '10'],
                            ],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'status' => 200,
                                'message' => 'Data retrieved successfully',
                                'data' => [],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Detail Form Builder',
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/{{formBuilderCode}}',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', '{{formBuilderCode}}'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'status' => 200,
                                'data' => [
                                    'id' => 1,
                                    'code' => 'FORM_CUSTOMER',
                                    'name' => 'Customer Form',
                                    'description' => 'Customer master form',
                                    'enabled' => true,
                                    'schema' => [
                                        ['name' => 'customer_name', 'type' => 'string'],
                                    ],
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Create Form Builder',
                    'request' => [
                        'method' => 'POST',
                        'header' => [
                            ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'],
                        ],
                        'body' => [
                            'mode' => 'raw',
                            'raw' => json_encode([
                                'code' => 'FORM_CUSTOMER',
                                'name' => 'Customer Form',
                                'description' => 'Customer master form',
                                'enabled' => true,
                                'schema' => [
                                    ['name' => 'customer_name', 'type' => 'string'],
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '201 Created',
                            'status' => 'Created',
                            'code' => 201,
                            'body' => json_encode([
                                'status' => 201,
                                'message' => 'Form created successfully',
                                'data' => [
                                    'id' => 1,
                                    'code' => 'FORM_CUSTOMER',
                                    'name' => 'Customer Form',
                                    'description' => 'Customer master form',
                                    'enabled' => true,
                                    'schema' => [
                                        ['name' => 'customer_name', 'type' => 'string'],
                                    ],
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Update Form Builder',
                    'request' => [
                        'method' => 'PUT',
                        'header' => [
                            ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'],
                        ],
                        'body' => [
                            'mode' => 'raw',
                            'raw' => json_encode([
                                'name' => 'Customer Form Updated',
                                'schema' => [
                                    ['name' => 'customer_name', 'type' => 'string'],
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/{{formBuilderId}}',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', '{{formBuilderId}}'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'status' => 200,
                                'message' => 'Form updated successfully',
                                'data' => [
                                    'id' => 1,
                                    'code' => 'FORM_CUSTOMER',
                                    'name' => 'Customer Form Updated',
                                    'description' => 'Customer master form',
                                    'enabled' => true,
                                    'schema' => [
                                        ['name' => 'customer_name', 'type' => 'string'],
                                    ],
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Delete Form Builder',
                    'request' => [
                        'method' => 'DELETE',
                        'header' => [],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/{{formBuilderId}}',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', '{{formBuilderId}}'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'status' => 200,
                                'message' => 'Form deleted successfully',
                                'data' => [],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Export Selected',
                    'request' => [
                        'method' => 'POST',
                        'header' => [
                            ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'],
                        ],
                        'body' => [
                            'mode' => 'raw',
                            'raw' => json_encode(['ids' => [1, 2, 3]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/export',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', 'export'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'version' => 1,
                                'exported_at' => '2026-06-22 10:00:00',
                                'items' => [],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Export All',
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/export-all',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', 'export-all'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'version' => 1,
                                'exported_at' => '2026-06-22 10:00:00',
                                'items' => [],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Import',
                    'request' => [
                        'method' => 'POST',
                        'header' => [],
                        'body' => [
                            'mode' => 'formdata',
                            'formdata' => [
                                ['key' => 'file', 'type' => 'file', 'src' => ''],
                                ['key' => 'mode', 'type' => 'text', 'value' => 'skip'],
                            ],
                        ],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/import',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', 'import'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'status' => 200,
                                'message' => 'Import completed.',
                                'mode' => 'skip',
                                'summary' => [
                                    'selected' => 1,
                                    'created' => 1,
                                    'updated' => 0,
                                    'skipped' => 0,
                                    'failed' => 0,
                                ],
                                'errors' => [],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Documentation',
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/docs',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', 'docs'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'status' => 200,
                                'data' => [
                                    'title' => 'Form Builder API Documentation',
                                ],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
                [
                    'name' => 'Download Postman Collection',
                    'request' => [
                        'method' => 'GET',
                        'header' => [],
                        'url' => [
                            'raw' => $baseUrl . '/api/form-builder/postman',
                            'host' => [$baseUrl],
                            'path' => ['api', 'form-builder', 'postman'],
                        ],
                    ],
                    'response' => [
                        [
                            'name' => '200 OK',
                            'status' => 'OK',
                            'code' => 200,
                            'body' => json_encode([
                                'info' => [
                                    'name' => 'Laravel Form Builder - Form Builder Management',
                                ],
                                'item' => [],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function isExportStructureValid(array $payload): bool
    {
        if (! array_key_exists('version', $payload) || (int) $payload['version'] !== self::EXPORT_VERSION) {
            return false;
        }

        if (! array_key_exists('exported_at', $payload) || ! is_string($payload['exported_at']) || trim($payload['exported_at']) === '') {
            return false;
        }

        return array_key_exists('items', $payload) && is_array($payload['items']) && $payload['items'] !== [];
    }

    protected function isSequentialArray(array $items): bool
    {
        return array_keys($items) === range(0, count($items) - 1);
    }

    protected function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    protected function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($filtered !== null) {
                return $filtered;
            }
        }

        return (bool) $value;
    }
}
