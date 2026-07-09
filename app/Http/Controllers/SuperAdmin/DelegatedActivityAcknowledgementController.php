<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DelegatedActivityAcknowledgementResource;
use App\Models\DelegatedActivityAcknowledgement;
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
        $filters = $this->validatedFilters($request);
        $query = DelegatedActivityAcknowledgement::query()
            ->visibleToSuperAdmin()
            ->with(['delegatedActor', 'accountableUser', 'acknowledgedBy']);

        $this->applyFilters($query, $filters);

        $paginator = $query
            ->orderByRaw('acknowledgement_due_at is null')
            ->orderBy('acknowledgement_due_at')
            ->orderByDesc('performed_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'message' => 'Daftar monitoring aktivitas delegasi berhasil diambil',
            'data' => DelegatedActivityAcknowledgementResource::collection($paginator->getCollection())->resolve($request),
            'meta' => array_merge(
                $this->paginationMeta($paginator),
                ['summary' => $this->service->buildSummaryForSuperAdmin($filters)],
            ),
        ]);
    }

    public function show(Request $request, int $acknowledgement): JsonResponse
    {
        $task = DelegatedActivityAcknowledgement::query()
            ->visibleToSuperAdmin()
            ->with(['delegatedActor', 'accountableUser', 'acknowledgedBy'])
            ->findOrFail($acknowledgement);

        return response()->json([
            'message' => 'Detail monitoring aktivitas delegasi berhasil diambil',
            'data' => (new DelegatedActivityAcknowledgementResource($task))->resolve($request),
        ]);
    }

    public function markEscalationSeen(Request $request, int $acknowledgement): JsonResponse
    {
        $task = DelegatedActivityAcknowledgement::query()
            ->visibleToSuperAdmin()
            ->findOrFail($acknowledgement);

        try {
            $task = $this->service->markEscalationSeen($task, $request->user());
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Eskalasi aktivitas delegasi berhasil ditandai sudah dilihat',
            'data' => (new DelegatedActivityAcknowledgementResource($task))->resolve($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', 'string', Rule::in(DelegatedActivityAcknowledgement::STATUSES)],
            'urgency' => ['sometimes', 'string', Rule::in(DelegatedActivityAcknowledgement::URGENCIES)],
            'overdue' => ['sometimes', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
            'domain_type' => ['sometimes', 'string', 'max:64'],
            'activity_type' => ['sometimes', 'string', 'max:96'],
            'accountable_user_id' => ['sometimes', 'integer', 'min:1'],
            'delegated_actor_id' => ['sometimes', 'integer', 'min:1'],
            'represented_scope_type' => ['sometimes', 'string', 'max:64'],
            'represented_scope_id' => ['sometimes', 'integer', 'min:1'],
            'due_before' => ['sometimes', 'date'],
            'due_after' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach ([
            'status',
            'urgency',
            'domain_type',
            'activity_type',
            'accountable_user_id',
            'delegated_actor_id',
            'represented_scope_type',
            'represented_scope_id',
        ] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['due_before'])) {
            $query->where('acknowledgement_due_at', '<=', $filters['due_before']);
        }

        if (isset($filters['due_after'])) {
            $query->where('acknowledgement_due_at', '>=', $filters['due_after']);
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
