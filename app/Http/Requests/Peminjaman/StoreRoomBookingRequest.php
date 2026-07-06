<?php

namespace App\Http\Requests\Peminjaman;

use App\Services\RoomBookingAttachmentService;

class StoreRoomBookingRequest extends RoomBookingPayloadRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
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
