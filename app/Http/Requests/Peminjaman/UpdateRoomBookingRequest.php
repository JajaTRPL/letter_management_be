<?php

namespace App\Http\Requests\Peminjaman;

class UpdateRoomBookingRequest extends RoomBookingPayloadRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'expected_workflow_version' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
