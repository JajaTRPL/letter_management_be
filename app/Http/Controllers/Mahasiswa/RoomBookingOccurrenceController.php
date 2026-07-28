<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\SubmitRoomBookingReturnRequest;
use App\Http\Requests\Peminjaman\WithdrawRoomBookingReturnRequest;
use App\Models\RoomBookingOccurrence;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Throwable;

class RoomBookingOccurrenceController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(private RoomBookingReturnService $returns) {}

    public function submitReturn(
        SubmitRoomBookingReturnRequest $request,
        RoomBookingOccurrence $occurrence,
    ): JsonResponse {
        try {
            $file = $request->file('evidence');
            abort_unless($file instanceof UploadedFile, 422);
            $outcome = $this->returns->submit(
                $occurrence,
                $request->user(),
                $file,
                $request->integer('expected_occurrence_version'),
                $request->validated('idempotency_key'),
                fn (array $result) => $this->roomBookingMutationResponseBody($result),
            );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $occurrence->booking);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $occurrence->booking);
        }
    }

    public function withdrawReturn(
        WithdrawRoomBookingReturnRequest $request,
        RoomBookingOccurrence $occurrence,
    ): JsonResponse {
        try {
            $outcome = $this->returns->withdraw(
                $occurrence,
                $request->user(),
                $request->integer('expected_occurrence_version'),
                $request->integer('expected_return_version'),
                $request->validated('idempotency_key'),
                fn (array $result) => $this->roomBookingMutationResponseBody($result),
            );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $occurrence->booking);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $occurrence->booking);
        }
    }
}
