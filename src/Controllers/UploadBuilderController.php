<?php

namespace ESolution\DataSources\Controllers;

use App\Http\Controllers\Controller;
use ESolution\DataSources\Http\Requests\UploadBuilderStatusRequest;
use ESolution\DataSources\Http\Requests\UploadBuilderStoreRequest;
use ESolution\DataSources\Http\Requests\UploadBuilderUpdateRequest;
use ESolution\DataSources\Models\UploadConfig;
use ESolution\DataSources\Support\Concerns\AppliesSearchFilter;
use ESolution\DataSources\Support\UploadConfigResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class UploadBuilderController extends Controller
{
    use AppliesSearchFilter;

    public function __construct(
        protected UploadConfigResolver $resolver
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = UploadConfig::query()->orderBy('id');

        if (trim((string) $request->query('search', '')) !== '') {
            $query = $this->applySearchFilter($query, $request, ['code', 'name', 'description', 'endpoint', 'upload_path']);
        }

        $enabled = $request->query('enabled');
        if ($enabled !== null && $enabled !== '') {
            $query->where('enabled', filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 10) ?: 10);

        return response()->json($paginator);
    }

    public function export(Request $request)
    {
        $query = UploadConfig::query()->orderBy('id');

        $identifiers = $this->normalizeSelectedIdentifiers(
            $request->input('ids', $request->input('codes', []))
        );

        if ($identifiers !== []) {
            $query->where(function ($subQuery) use ($identifiers): void {
                $numericIds = array_values(array_filter($identifiers, static fn (mixed $value): bool => is_int($value)));
                $codes = array_values(array_filter($identifiers, static fn (mixed $value): bool => is_string($value)));

                if ($numericIds !== []) {
                    $subQuery->whereIn('id', $numericIds);
                }

                if ($codes !== []) {
                    if ($numericIds !== []) {
                        $subQuery->orWhereIn('code', $codes);
                    } else {
                        $subQuery->whereIn('code', $codes);
                    }
                }
            });
        }

        $payload = $query
            ->get()
            ->map(fn (UploadConfig $config): array => $this->serializeUploadConfig($config))
            ->values()
            ->all();

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return response()->json([
                'message' => 'Failed to generate export file.',
            ], 500);
        }

        $filename = 'upload-configs-' . now()->format('Y-m-d_His') . '.json';

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

    public function import(Request $request): JsonResponse
    {
        $rows = $this->resolveImportRowsFromRequest($request);

        if ($rows === null) {
            return response()->json([
                'message' => 'Invalid import format: rows must be array',
                'errors' => [
                    'rows' => ['Invalid import format: rows must be array'],
                ],
            ], 422);
        }

        $validator = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array'],
            'rows.*' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first('rows') ?: 'Invalid import format: rows must be array',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $summary = [
            'selected' => count($rows),
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $errors = [];
        $processedKeys = [];

        $connection = UploadConfig::query()->getConnection();
        $connection->beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $result = $this->importUploadConfigRow($row, $summary, $processedKeys, 'import.json', $index + 1);

                if ($result !== null && isset($result['error'])) {
                    $errors[] = $result['error'];
                }
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            return response()->json([
                'message' => 'Failed to import upload configurations.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        Cache::forget('upload-builder:defaults');

        return response()->json([
            'status' => 200,
            'message' => 'Import completed.',
            'selected' => $summary['selected'],
            'imported' => $summary['imported'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
            'summary' => $summary,
            'errors' => $errors,
        ], 200);
    }

    protected function normalizeSelectedIdentifiers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $item): int|string|null {
            if (! is_int($item) && ! is_string($item)) {
                return null;
            }

            $trimmed = trim((string) $item);

            if ($trimmed === '') {
                return null;
            }

            return ctype_digit($trimmed) ? (int) $trimmed : $trimmed;
        }, $value), static fn (mixed $item): bool => $item !== null));
    }

    public function defaults(): JsonResponse
    {
        $uploadStorage = $this->uploadStorageConfig();

        return response()->json([
            'data' => [
                'default_storage_disk' => $uploadStorage['disk'] ?? 'local',
                'default_base_path' => $uploadStorage['base_path'] ?? 'uploads/general',
                'default_upload_path' => $uploadStorage['base_path'] ?? 'uploads/general',
                'default_multiple' => false,
                'max_file_size_options' => [1024, 2048, 5120, 10240, 20480],
                'allowed_extensions_options' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xlsx'],
                'default_middlewares' => [],
                'storage_disk_options' => $this->availableStorageDisks(),
                'storage_disk_global' => $uploadStorage['disk'] ?? 'local',
                'base_path_global' => $uploadStorage['base_path'] ?? 'uploads/general',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, (new UploadBuilderStoreRequest())->rules());

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $payload = $this->normalizePayload($validated);
        $uploadConfig = UploadConfig::create($payload)->fresh();

        if ($uploadConfig instanceof UploadConfig) {
            $this->resolver->forget($uploadConfig->endpoint);
        }

        return response()->json([
            'status' => 201,
            'message' => 'Upload builder created successfully',
            'data' => $uploadConfig,
        ], 201);
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $uploadConfig = UploadConfig::query()->find($id) ?? UploadConfig::query()->where('code', $id)->first();

        if ($uploadConfig === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Upload builder not found',
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => $uploadConfig,
        ]);
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $uploadConfig = UploadConfig::query()->find($id);

        if ($uploadConfig === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Upload builder not found',
            ], 404);
        }

        $validated = $this->validatePayload($request, (new UploadBuilderUpdateRequest($uploadConfig))->rules());

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $originalEndpoint = $uploadConfig->endpoint;
        $uploadConfig->fill($this->normalizePayload($validated, false));
        $uploadConfig->save();
        $uploadConfig = $uploadConfig->fresh();

        $this->resolver->forget($originalEndpoint);
        if ($uploadConfig instanceof UploadConfig) {
            $this->resolver->forget($uploadConfig->endpoint);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Upload builder updated successfully',
            'data' => $uploadConfig,
        ]);
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $uploadConfig = UploadConfig::query()->find($id);

        if ($uploadConfig === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Upload builder not found',
            ], 404);
        }

        $this->resolver->forget($uploadConfig->endpoint);
        $uploadConfig->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Upload builder deleted successfully',
            'data' => [],
        ]);
    }

    public function updateStatus(Request $request, int|string $id): JsonResponse
    {
        $uploadConfig = UploadConfig::query()->find($id);

        if ($uploadConfig === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Upload builder not found',
            ], 404);
        }

        $validated = $this->validatePayload($request, (new UploadBuilderStatusRequest())->rules());

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $uploadConfig->update([
            'enabled' => (bool) $validated['enabled'],
        ]);
        $uploadConfig = $uploadConfig->fresh();
        $this->resolver->forget($uploadConfig->endpoint);

        return response()->json([
            'status' => 200,
            'message' => 'Status updated successfully',
            'data' => $uploadConfig,
        ]);
    }

    protected function validatePayload(Request $request, array $rules): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules, [], [
            'upload_path' => 'upload path',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        return $validator->validated();
    }

    protected function normalizePayload(array $payload, bool $isCreate = true): array
    {
        $endpoint = trim((string) ($payload['endpoint'] ?? ''));
        $endpoint = ltrim($endpoint, '/');

        if ($endpoint !== '' && ! str_starts_with($endpoint, 'upload/')) {
            $endpoint = 'upload/' . $endpoint;
        }

        $allowedExtensions = $this->normalizeExtensionArray($payload['allowed_extensions'] ?? null);
        $middlewares = $this->normalizeMiddlewareArray($payload['middlewares'] ?? null);
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
            $normalized['endpoint'] = $endpoint;
        }

        if ($isCreate || array_key_exists('upload_path', $payload)) {
            $normalized['upload_path'] = $this->normalizeUploadPath($payload['upload_path'] ?? null);
        }

        if ($isCreate || array_key_exists('max_file_size', $payload)) {
            $normalized['max_file_size'] = isset($payload['max_file_size']) && $payload['max_file_size'] !== ''
                ? (int) $payload['max_file_size']
                : null;
        }

        if ($isCreate || array_key_exists('allowed_extensions', $payload)) {
            $normalized['allowed_extensions'] = $allowedExtensions === [] ? null : $allowedExtensions;
        }

        if ($isCreate || array_key_exists('multiple', $payload)) {
            $normalized['multiple'] = $this->normalizeBoolean($payload['multiple'] ?? false);
        }

        if ($isCreate || array_key_exists('middlewares', $payload)) {
            $normalized['middlewares'] = $middlewares === [] ? null : $middlewares;
        }

        if ($isCreate || array_key_exists('enabled', $payload)) {
            $normalized['enabled'] = array_key_exists('enabled', $payload)
                ? $this->normalizeBoolean($payload['enabled'])
                : true;
        }

        return $normalized;
    }

    protected function serializeUploadConfig(UploadConfig $config): array
    {
        $uploadStorage = $this->uploadStorageConfig();

        return [
            'code' => $config->code,
            'name' => $config->name,
            'description' => $config->description,
            'endpoint' => $config->endpoint,
            'upload_path' => $config->upload_path,
            'storage_disk' => $uploadStorage['disk'] ?? 'local',
            'base_path' => $uploadStorage['base_path'] ?? 'uploads/general',
            'max_file_size' => $config->max_file_size,
            'allowed_extensions' => $config->allowed_extensions,
            'multiple' => (bool) $config->multiple,
            'middlewares' => $config->middlewares,
            'enabled' => (bool) $config->enabled,
        ];
    }

    protected function resolveImportRowsFromRequest(Request $request): ?array
    {
        $payload = null;

        foreach (['rows', 'data', 'payload'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && trim($value) !== '') {
                try {
                    $payload = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    return null;
                }

                break;
            }

            if (is_array($value)) {
                $payload = $value;
                break;
            }
        }

        if ($payload === null && $request->hasFile('file')) {
            $file = $request->file('file');
            $contents = @file_get_contents($file->getRealPath());

            if ($contents === false || trim($contents) === '') {
                return null;
            }

            try {
                $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                return null;
            }
        }

        if (! is_array($payload)) {
            return null;
        }

        if (array_key_exists('rows', $payload) && is_array($payload['rows'])) {
            $payload = $payload['rows'];
        }

        if ($payload === []) {
            return [];
        }

        if (! $this->isSequentialArray($payload)) {
            $payload = [$payload];
        }

        return $payload;
    }

    protected function importUploadConfigRow(array $row, array &$summary, array &$processedKeys, string $fileName, int $rowNumber): ?array
    {
        $payload = $this->normalizeImportedUploadPayload($row);

        if (! is_array($payload)) {
            $summary['failed']++;

            return [
                'error' => [
                    'file_name' => $fileName,
                    'row' => $rowNumber,
                    'message' => 'Row must be a JSON object.',
                ],
            ];
        }

        $validator = Validator::make($payload, [
            'code' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'endpoint' => ['required', 'string', 'max:255'],
            'upload_path' => ['required', 'string', 'max:255'],
            'max_file_size' => ['nullable', 'integer', 'min:1'],
            'allowed_extensions' => ['nullable', 'array'],
            'allowed_extensions.*' => ['nullable', 'string'],
            'multiple' => ['nullable', 'boolean'],
            'middlewares' => ['nullable', 'array'],
            'middlewares.*' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            $summary['failed']++;

            return [
                'error' => [
                    'file_name' => $fileName,
                    'row' => $rowNumber,
                    'message' => $validator->errors()->first(),
                ],
            ];
        }

        $code = trim((string) $payload['code']);
        $endpoint = $this->normalizeUploadEndpoint($payload['endpoint']);
        $duplicateKey = implode('|', [$code, $endpoint]);

        if (isset($processedKeys[$duplicateKey])) {
            $summary['skipped']++;

            return null;
        }

        $existing = UploadConfig::query()
            ->where('code', $code)
            ->orWhere('endpoint', $endpoint)
            ->exists();

        if ($existing) {
            $summary['skipped']++;
            $processedKeys[$duplicateKey] = true;

            return null;
        }

        $data = [
            'code' => $code,
            'name' => trim((string) $payload['name']),
            'description' => $this->nullableString($payload['description'] ?? null),
            'endpoint' => $endpoint,
            'upload_path' => $this->normalizeUploadPath($payload['upload_path'] ?? null),
            'max_file_size' => isset($payload['max_file_size']) && $payload['max_file_size'] !== ''
                ? (int) $payload['max_file_size']
                : null,
            'allowed_extensions' => $this->normalizeExtensionArray($payload['allowed_extensions'] ?? null),
            'multiple' => $this->normalizeBoolean($payload['multiple'] ?? false),
            'middlewares' => $this->normalizeMiddlewareArray($payload['middlewares'] ?? null),
            'enabled' => array_key_exists('enabled', $payload)
                ? $this->normalizeBoolean($payload['enabled'])
                : true,
        ];

        try {
            UploadConfig::create($data);
            $summary['imported']++;
            $processedKeys[$duplicateKey] = true;
        } catch (\Throwable $exception) {
            $summary['failed']++;

            return [
                'error' => [
                    'file_name' => $fileName,
                    'row' => $rowNumber,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return null;
    }

    protected function normalizeImportedUploadPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        return [
            'code' => $payload['code'] ?? null,
            'name' => $payload['name'] ?? null,
            'description' => $payload['description'] ?? null,
            'endpoint' => $payload['endpoint'] ?? null,
            'upload_path' => $payload['upload_path'] ?? null,
            'storage_disk' => $payload['storage_disk'] ?? null,
            'base_path' => $payload['base_path'] ?? null,
            'max_file_size' => $payload['max_file_size'] ?? null,
            'allowed_extensions' => $this->normalizeExtensionArray($payload['allowed_extensions'] ?? null),
            'multiple' => $payload['multiple'] ?? false,
            'middlewares' => $this->normalizeMiddlewareArray($payload['middlewares'] ?? null),
            'enabled' => $payload['enabled'] ?? true,
        ];
    }

    protected function normalizeExtensionArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = array_values(array_filter(array_map(static function (mixed $item): string {
            return strtolower(trim((string) $item));
        }, $value), static fn (string $item): bool => $item !== ''));

        return array_values(array_unique($normalized));
    }

    protected function normalizeMiddlewareArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = array_values(array_filter(array_map(static function (mixed $item): string {
            return trim((string) $item);
        }, $value), static fn (string $item): bool => $item !== ''));

        return array_values(array_unique($normalized));
    }

    protected function normalizeUploadPath(mixed $value): string
    {
        $path = trim((string) $value, "/ \t\n\r\0\x0B");

        return $path !== '' ? $path : 'uploads/general';
    }

    protected function normalizeUploadEndpoint(mixed $value): string
    {
        $endpoint = trim((string) $value);
        $endpoint = ltrim($endpoint, '/');

        if ($endpoint !== '' && ! str_starts_with($endpoint, 'upload/')) {
            $endpoint = 'upload/' . $endpoint;
        }

        return $endpoint;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function availableStorageDisks(): array
    {
        $disks = config('filesystems.disks', []);

        return is_array($disks) ? array_values(array_keys($disks)) : ['local'];
    }

    protected function uploadStorageConfig(): array
    {
        $config = config('datasources.upload_storage', []);

        return is_array($config) ? $config : [];
    }

    protected function isSequentialArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

}
