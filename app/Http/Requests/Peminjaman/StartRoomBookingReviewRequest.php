<?php

namespace App\Http\Requests\Peminjaman;

class StartRoomBookingReviewRequest extends RoomBookingIdempotentMutationRequest
{
    public function rules(): array
    {
        return $this->idempotencyRules();
    }
}
