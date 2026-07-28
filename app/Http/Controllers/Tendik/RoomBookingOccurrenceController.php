<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\DecideRoomBookingReturnRequest;
use App\Http\Requests\Peminjaman\IssueRoomBookingKeyRequest;
use App\Models\RoomBookingOccurrence;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingKeyService;
use App\Services\RoomBookingOccurrenceAuthorizationService;
use App\Services\RoomBookingOccurrenceService;
use App\Services\RoomBookingReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RoomBookingOccurrenceController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingOccurrenceAuthorizationService $authorization,
        private RoomBookingOccurrenceService $occurrences,
        private RoomBookingKeyService $keys,
        private RoomBookingReturnService $returns,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tab = $request->string('tab', 'today')->toString();
        abort_unless(in_array($tab, ['today', 'key_handover', 'returns', 'overdue', 'all'], true), 422);
        $actor = $request->user();
        $query = RoomBookingOccurrence::query()->with([
            'booking.room.owningLaboratory:id,code,name',
            'booking.requester:id,name',
            'activeReturnRequest', 'acceptedReturnRequest', 'returnRequests',
        ])->orderBy('start_at');
        // Operational eligibility is a property of EVERY tab, not just the key
        // handover queue: occurrences of submitted/revision_requested/rejected/
        // cancelled bookings (including the legacy backfilled rows) are audit
        // records, never work items. "Semua" means all approved operational
        // occurrences — completed return history included — not all bookings.
        $query->operationallyActionable();
        $this->authorization->scopeOperational($query, $actor);
        $now = now(config('app.timezone'));
        match ($tab) {
            'today' => $query->whereDate('occurrence_date', $now->toDateString()),
            'key_handover' => $query->whereNull('key_issued_at'),
            'returns' => $query->whereHas('activeReturnRequest'),
            'overdue' => $query->where('return_due_at', '<', $now)
                ->whereDoesntHave('acceptedReturnRequest'),
            default => null,
        };
        $items = $query->limit(200)->get()->map(function (RoomBookingOccurrence $occurrence) use ($actor): array {
            $payload = $this->occurrences->payload($occurrence, staff: true);
            $payload['booking'] = [
                'id' => (int) $occurrence->booking->id,
                'activity_name' => $occurrence->booking->activity_name,
                'applicant_name' => $occurrence->booking->requester?->name,
                'room' => $this->roomPayload($occurrence->booking->room),
            ];
            $payload['capabilities']['can_issue_key'] = $this->occurrences
                ->canIssueKey($actor, $occurrence);
            $payload['capabilities']['can_verify_return'] = $this->occurrences
                ->canVerifyReturn($actor, $occurrence);
            // Who owns this step when the viewer does not. A Kepala Lab may read
            // their lab's occurrences but can never issue a key or verify a
            // return, so the UI must name the responsible party instead of
            // rendering an empty action area they will hunt through.
            $payload['responsible_label'] = $this->occurrences->responsibleLabelFor($occurrence);

            return $payload;
        });

        return response()->json(['message' => 'Daftar operasional penggunaan ruangan berhasil diambil.', 'data' => $items]);
    }

    public function issueKey(
        IssueRoomBookingKeyRequest $request,
        RoomBookingOccurrence $occurrence,
    ): JsonResponse {
        return $this->mutationResponse($occurrence, fn () => $this->keys->issue(
            $occurrence,
            $request->user(),
            $request->integer('expected_occurrence_version'),
            $request->validated('note'),
            $request->validated('idempotency_key'),
            fn (array $result) => $this->roomBookingMutationResponseBody($result, includeRequester: true),
        ));
    }

    public function accept(DecideRoomBookingReturnRequest $request, RoomBookingOccurrence $occurrence): JsonResponse
    {
        return $this->decision($request, $occurrence, 'accept');
    }

    public function revise(DecideRoomBookingReturnRequest $request, RoomBookingOccurrence $occurrence): JsonResponse
    {
        return $this->decision($request, $occurrence, 'revise');
    }

    public function reject(DecideRoomBookingReturnRequest $request, RoomBookingOccurrence $occurrence): JsonResponse
    {
        return $this->decision($request, $occurrence, 'reject');
    }

    private function decision(DecideRoomBookingReturnRequest $request, RoomBookingOccurrence $occurrence, string $decision): JsonResponse
    {
        return $this->mutationResponse($occurrence, fn () => $this->returns->decide(
            $occurrence,
            $request->user(),
            $decision,
            $request->integer('expected_occurrence_version'),
            $request->integer('expected_return_version'),
            $request->validated('note'),
            $request->validated('key_received_at'),
            $request->validated('received_time_change_reason'),
            $request->validated('idempotency_key'),
            fn (array $result) => $this->roomBookingMutationResponseBody($result, includeRequester: true),
        ));
    }

    private function mutationResponse(RoomBookingOccurrence $occurrence, callable $mutation): JsonResponse
    {
        try {
            return $this->roomBookingOutcomeResponse($mutation());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $occurrence->booking, includeRequester: true);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $occurrence->booking);
        }
    }
}
