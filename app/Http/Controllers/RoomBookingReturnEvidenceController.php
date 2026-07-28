<?php

namespace App\Http\Controllers;

use App\Models\RoomBookingReturnRequest;
use App\Services\RoomBookingOccurrenceAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoomBookingReturnEvidenceController extends Controller
{
    public function __construct(private RoomBookingOccurrenceAuthorizationService $authorization) {}

    public function preview(Request $request, RoomBookingReturnRequest $returnRequest): StreamedResponse
    {
        return $this->serve($request, $returnRequest, inline: true);
    }

    public function download(Request $request, RoomBookingReturnRequest $returnRequest): StreamedResponse
    {
        return $this->serve($request, $returnRequest, inline: false);
    }

    private function serve(Request $request, RoomBookingReturnRequest $returnRequest, bool $inline): StreamedResponse
    {
        $returnRequest->loadMissing('occurrence.booking.room');
        abort_unless($this->authorization->canRead($request->user(), $returnRequest->occurrence), 404);
        $disk = Storage::disk($returnRequest->evidence_disk);
        abort_unless($disk->exists($returnRequest->evidence_path), 404);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $returnRequest->evidence_original_name)
            ?: 'bukti-pengembalian';

        return $disk->response($returnRequest->evidence_path, $name, [
            'Content-Type' => $returnRequest->evidence_mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
