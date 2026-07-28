<?php

namespace App\Http\Requests\Peminjaman;

class CreateRoomBookingCancellationRequest extends RoomBookingIdempotentMutationRequest
{
    public function rules(): array
    {
        return array_merge($this->idempotencyRules(), [
            'reason' => [
                'required',
                'string',
                'max:'.config('room_booking.reason_max_length', 2000),
            ],
        ]);
    }
}
