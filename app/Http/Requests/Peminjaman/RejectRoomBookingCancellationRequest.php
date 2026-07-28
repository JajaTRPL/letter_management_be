<?php

namespace App\Http\Requests\Peminjaman;

class RejectRoomBookingCancellationRequest extends RoomBookingIdempotentMutationRequest
{
    public function rules(): array
    {
        return array_merge($this->idempotencyRules(), [
            'decision_note' => [
                'required',
                'string',
                'max:'.config('room_booking.decision_note_max_length', 2000),
            ],
        ]);
    }
}
