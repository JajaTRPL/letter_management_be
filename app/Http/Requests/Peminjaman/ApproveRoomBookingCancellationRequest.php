<?php

namespace App\Http\Requests\Peminjaman;

class ApproveRoomBookingCancellationRequest extends RoomBookingIdempotentMutationRequest
{
    public function rules(): array
    {
        return array_merge($this->idempotencyRules(), [
            'decision_note' => [
                'nullable',
                'string',
                'max:'.config('room_booking.decision_note_max_length', 2000),
            ],
        ]);
    }
}
