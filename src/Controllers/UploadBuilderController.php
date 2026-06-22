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

        return response()->json([
            'status' => 200,
            'message' => 'Data retrieved successfully',
            'data' => $query->get(),
        ]);
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

}
