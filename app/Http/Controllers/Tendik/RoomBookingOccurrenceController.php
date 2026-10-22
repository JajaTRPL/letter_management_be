<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\DecideRoomBookingReturnRequest;
use App\Http\Requests\Peminjaman\ForceCloseRoomBookingReturnRequest;
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
            'booking.requester.mahasiswaProfile:id,user_id,no_hp',
            'activeReturnRequest', 'acceptedReturnRequest', 'latestReturnRequest', 'returnRequests',
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
            // Needs follow-up: past due, or evidence rejected — either way the
            // key is still out until accepted or force-closed.
            'overdue' => $query->whereNotNull('key_issued_at')
                ->where(fn ($q) => $q->where('return_due_at', '<', $now)
                    ->orWhereHas('latestReturnRequest', fn ($r) => $r->where('status', 'rejected')))
                ->whereDoesntHave('acceptedReturnRequest'),
            default => null,
        };
        $items = $query->limit(200)->get()->map(function (RoomBookingOccurrence $occurrence) use ($actor): array {
            $payload = $this->occurrences->payload($occurrence, staff: true);
            $contact = $this->occurrences->applicantContact($occurrence);
            $payload['booking'] = [
                'id' => (int) $occurrence->booking->id,
                'activity_name' => $occurrence->booking->activity_name,
                'applicant_name' => $contact['name'],
                'applicant_phone' => $contact['phone'],
                'room' => $this->roomPayload($occurrence->booking->room),
            ];
            $payload['capabilities']['can_issue_key'] = $this->occurrences
                ->canIssueKey($actor, $occurrence);
            $payload['capabilities']['can_verify_return'] = $this->occurrences
                ->canVerifyReturn($actor, $occurrence);
            $payload['capabilities']['can_force_close'] = $this->occurrences
                ->canForceClose($actor, $occurrence);
            // The room's key is still with an earlier borrower: name who holds
            // it so staff can call them before this handover.
            $payload['key_blocked_by'] = null;
            if ($occurrence->key_issued_at === null && $occurrence->acceptedReturnRequest === null) {
                $previous = $this->occurrences->outstandingKeyBefore($occurrence);
                if ($previous) {
                    $previousContact = $this->occurrences->applicantContact($previous);
                    $payload['key_blocked_by'] = [
                        'occurrence_ref' => $previous->public_id,
                        'applicant_name' => $previousContact['name'],
                        'applicant_phone' => $previousContact['phone'],
                        'activity_name' => $previous->booking->activity_name,
                        'end_at' => $previous->end_at->toIso8601String(),
                    ];
                }
            }
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

    public function forceClose(ForceCloseRoomBookingReturnRequest $request, RoomBookingOccurrence $occurrence): JsonResponse
    {
        return $this->mutationResponse($occurrence, fn () => $this->returns->forceClose(
            $occurrence,
            $request->user(),
            $request->integer('expected_occurrence_version'),
            $request->validated('note'),
            $request->validated('key_received_at'),
            $request->validated('idempotency_key'),
            fn (array $result) => $this->roomBookingMutationResponseBody($result, includeRequester: true),
        ));
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
