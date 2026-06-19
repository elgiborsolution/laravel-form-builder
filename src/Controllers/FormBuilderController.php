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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class FormBuilderController extends Controller
{
    use AppliesSearchFilter;

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
}
