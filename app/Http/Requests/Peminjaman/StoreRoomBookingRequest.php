<?php

namespace App\Http\Requests\Peminjaman;

use App\Http\Requests\Peminjaman\Concerns\ValidatesRoomBookingIdempotencyKey;
use App\Services\RoomBookingAttachmentService;

class StoreRoomBookingRequest extends RoomBookingPayloadRequest
{
    use ValidatesRoomBookingIdempotencyKey;

    public function rules(): array
    {
        return array_merge(parent::rules(), $this->idempotencyKeyRules(), [
            RoomBookingAttachmentService::INPUT_SURAT_PEMINJAMAN => [
                'required',
                'file',
                'mimes:pdf',
                'mimetypes:application/pdf',
                'max:'.RoomBookingAttachmentService::MAX_KB,
            ],
        ]);
    }
}
