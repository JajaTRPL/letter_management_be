<?php

namespace App\Http\Requests\Peminjaman;

class WithdrawRoomBookingCancellationRequest extends RoomBookingIdempotentMutationRequest
{
    public function rules(): array
    {
        return array_merge($this->idempotencyRules(), [
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
