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

        return response()->json([
            'status' => 201,
            'message' => 'Form created successfully',
            'data' => $formBuilder?->toArray() ?? [],
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
        $formBuilder = FormBuilder::query()->where('code', $code)->first();

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

        $formBuilder->fill($payload);
        $formBuilder->save();
        $formBuilder = $formBuilder->fresh();

        return response()->json([
            'status' => 200,
            'message' => 'Form updated successfully',
            'data' => $formBuilder?->toArray() ?? [],
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

        return response()->json([
            'status' => 200,
            'message' => 'Status updated successfully',
            'data' => $formBuilder->fresh()?->toArray() ?? [],
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
}
