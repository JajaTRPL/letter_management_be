<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\CreateRoomBookingCancellationRequest;
use App\Http\Requests\Peminjaman\WithdrawRoomBookingCancellationRequest;
use App\Models\RoomBookingCancellationRequest;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingCancellationRequestService;
use App\Services\RoomBookingDomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class RoomBookingCancellationRequestController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingCancellationRequestService $cancellations,
    ) {}

    public function store(
        CreateRoomBookingCancellationRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        $this->assertOwned($request, $booking);

        try {
            $outcome = $this->cancellations->create(
                $booking,
                $request->user(),
                $request->validated('reason'),
                $request->integer('expected_workflow_version'),
                $request->validated('idempotency_key'),
                fn (array $result): array => $this->roomBookingMutationResponseBody($result),
            );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $booking);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
        }
    }

    public function show(Request $request, RoomBookingRequest $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);
        $cancellationRequest = $booking->cancellationRequests()
            ->latest('requested_at')
            ->first();

        return response()->json([
            'message' => 'Status permohonan pembatalan berhasil diambil',
            'data' => $this->bookingMutationData($booking, $cancellationRequest),
        ]);
    }

    public function withdraw(
        WithdrawRoomBookingCancellationRequest $request,
        RoomBookingRequest $booking,
        RoomBookingCancellationRequest $cancellationRequest,
    ): JsonResponse {
        $this->assertOwned($request, $booking);
        abort_unless(
            (int) $cancellationRequest->room_booking_request_id === (int) $booking->id
            && (int) $cancellationRequest->requested_by === (int) $request->user()->id,
            404,
        );

        try {
            $outcome = $this->cancellations->withdraw(
                $booking,
                $cancellationRequest,
                $request->user(),
                $request->validated('reason'),
                $request->integer('expected_workflow_version'),
                $request->validated('idempotency_key'),
                fn (array $result): array => $this->roomBookingMutationResponseBody($result),
            );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $booking);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
        }
    }

    private function assertOwned(Request $request, RoomBookingRequest $booking): void
    {
        abort_unless((int) $booking->requester_id === (int) $request->user()->id, 404);
    }
}
