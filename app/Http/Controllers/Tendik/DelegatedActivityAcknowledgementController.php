<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Controller;
use App\Http\Resources\DelegatedActivityAcknowledgementResource;
use App\Models\DelegatedActivityAcknowledgement;
use App\Models\User;
use App\Services\DelegatedActivityAcknowledgementService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DelegatedActivityAcknowledgementController extends Controller
{
    public function __construct(
        private DelegatedActivityAcknowledgementService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizeKepalaLab($request);
        $filters = $this->validatedFilters($request, includeUserFilters: false);

        $query = DelegatedActivityAcknowledgement::query()
            ->with(['delegatedActor', 'accountableUser', 'acknowledgedBy'])
            ->forAccountableUser($user);

        $this->applyFilters($query, $filters);

        $paginator = $query
            ->orderByRaw('acknowledgement_due_at is null')
            ->orderBy('acknowledgement_due_at')
            ->orderByDesc('performed_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'message' => 'Daftar aktivitas delegasi berhasil diambil',
            'data' => DelegatedActivityAcknowledgementResource::collection($paginator->getCollection())->resolve($request),
            'meta' => array_merge(
                $this->paginationMeta($paginator),
                ['summary' => $this->service->buildSummaryForAccountable($user)],
            ),
        ]);
    }

    public function show(Request $request, int $acknowledgement): JsonResponse
    {
        $user = $this->authorizeKepalaLab($request);
        $task = $this->taskForAccountableUser($acknowledgement, $user);

        return response()->json([
            'message' => 'Detail aktivitas delegasi berhasil diambil',
            'data' => (new DelegatedActivityAcknowledgementResource($task))->resolve($request),
        ]);
    }

    public function acknowledge(Request $request, int $acknowledgement): JsonResponse
    {
        $user = $this->authorizeKepalaLab($request);
        $task = $this->taskForAccountableUser($acknowledgement, $user);
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $task = $this->service->acknowledge($task, $user, $validated['note'] ?? null);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Aktivitas delegasi berhasil dikonfirmasi sudah ditinjau',
            'data' => (new DelegatedActivityAcknowledgementResource($task))->resolve($request),
        ]);
    }

    private function authorizeKepalaLab(Request $request): User
    {
        $user = $request->user();

        abort_unless($user && $user->isKalab(), 403, 'Hanya Kepala Lab yang dapat mengakses peninjauan aktivitas delegasi.');

        return $user;
    }

    private function taskForAccountableUser(int $id, User $user): DelegatedActivityAcknowledgement
    {
        return DelegatedActivityAcknowledgement::query()
            ->with(['delegatedActor', 'accountableUser', 'acknowledgedBy'])
            ->forAccountableUser($user)
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request, bool $includeUserFilters): array
    {
        $rules = [
            'status' => ['sometimes', 'string', Rule::in(DelegatedActivityAcknowledgement::STATUSES)],
            'urgency' => ['sometimes', 'string', Rule::in(DelegatedActivityAcknowledgement::URGENCIES)],
            'overdue' => ['sometimes', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
            'domain_type' => ['sometimes', 'string', 'max:64'],
            'activity_type' => ['sometimes', 'string', 'max:96'],
            'represented_scope_type' => ['sometimes', 'string', 'max:64'],
            'represented_scope_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];

        if ($includeUserFilters) {
            $rules['accountable_user_id'] = ['sometimes', 'integer', 'min:1'];
            $rules['delegated_actor_id'] = ['sometimes', 'integer', 'min:1'];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['status', 'urgency', 'domain_type', 'activity_type'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['represented_scope_type'])) {
            $query->where('represented_scope_type', $filters['represented_scope_type']);
        }

        if (isset($filters['represented_scope_id'])) {
            $query->where('represented_scope_id', $filters['represented_scope_id']);
        }

        if (array_key_exists('overdue', $filters)) {
            $this->applyOverdueFilter($query, $this->booleanFilter($filters['overdue']));
        }
    }

    private function applyOverdueFilter(Builder $query, bool $overdue): void
    {
        if ($overdue) {
            $query->overdue();

            return;
        }

        $query->where(function (Builder $query) {
            $query
                ->where('status', '!=', DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW)
                ->orWhereNull('acknowledgement_due_at')
            ->orWhere('acknowledgement_due_at', '>=', now(config('app.timezone')));
        });
    }

    private function booleanFilter(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
