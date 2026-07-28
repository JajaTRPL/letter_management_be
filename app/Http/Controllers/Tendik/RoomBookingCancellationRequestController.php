<?php

namespace App\Http\Controllers\Tendik;

use App\Enums\RoomBookingCancellationStatus;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\ApproveRoomBookingCancellationRequest;
use App\Http\Requests\Peminjaman\RejectRoomBookingCancellationRequest;
use App\Models\RoomBookingCancellationRequest;
use App\Services\RoomBookingCancellationRequestService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingReviewerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class RoomBookingCancellationRequestController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingCancellationRequestService $cancellations,
        private RoomBookingReviewerResolver $reviewerResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $this->reviewerResolver->canAccessCancellationDecisionQueue($request->user()),
            403,
        );
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(RoomBookingCancellationStatus::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = RoomBookingCancellationRequest::query()
            ->with([
                'booking.room.owningLaboratory:id,code,name',
                'booking.requester:id,name,email',
                'booking.activeCancellationRequest',
            ])
            ->orderByDesc('requested_at');
        $this->reviewerResolver->scopeCancellationRequests($query, $request->user());
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'message' => 'Daftar permohonan pembatalan berhasil diambil',
            'data' => collect($paginator->items())->map(fn ($item) => [
                'cancellation_request' => $this->cancellationRequestPayload($item, $item->booking),
                'booking' => $this->bookingPayload($item->booking, includeRequester: true),
            ])->all(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function show(
        Request $request,
        RoomBookingCancellationRequest $cancellationRequest,
    ): JsonResponse {
        $booking = $cancellationRequest->booking()->with('room')->firstOrFail();
        abort_unless(
            $this->reviewerResolver->canActAsApprover($request->user(), $booking),
            404,
        );

        return response()->json([
            'message' => 'Detail permohonan pembatalan berhasil diambil',
            'data' => $this->bookingMutationData(
                $booking,
                $cancellationRequest,
                includeRequester: true,
            ),
        ]);
    }

    public function approve(
        ApproveRoomBookingCancellationRequest $request,
        RoomBookingCancellationRequest $cancellationRequest,
    ): JsonResponse {
        return $this->decisionResponse($request, $cancellationRequest, approve: true);
    }

    public function reject(
        RejectRoomBookingCancellationRequest $request,
        RoomBookingCancellationRequest $cancellationRequest,
    ): JsonResponse {
        return $this->decisionResponse($request, $cancellationRequest, approve: false);
    }

    private function decisionResponse(
        Request $request,
        RoomBookingCancellationRequest $cancellationRequest,
        bool $approve,
    ): JsonResponse {
        $booking = $cancellationRequest->booking()->with('room')->firstOrFail();
        abort_unless(
            $this->reviewerResolver->canActAsApprover($request->user(), $booking),
            404,
        );

        try {
            $outcome = $approve
                ? $this->cancellations->approve(
                    $cancellationRequest,
                    $request->user(),
                    $request->input('decision_note'),
                    $request->integer('expected_workflow_version'),
                    $request->input('idempotency_key'),
                    fn (array $result): array => $this->roomBookingMutationResponseBody(
                        $result,
                        includeRequester: true,
                    ),
                )
                : $this->cancellations->reject(
                    $cancellationRequest,
                    $request->user(),
                    $request->input('decision_note'),
                    $request->integer('expected_workflow_version'),
                    $request->input('idempotency_key'),
                    fn (array $result): array => $this->roomBookingMutationResponseBody(
                        $result,
                        includeRequester: true,
                    ),
                );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse(
                $exception,
                $booking,
                includeRequester: true,
            );
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
        }
    }
}
