<?php

namespace App\Http\Requests\Peminjaman\Concerns;

trait ValidatesRoomBookingIdempotencyKey
{
    /** @return array<string, mixed> */
    protected function idempotencyKeyRules(): array
    {
        return [
            'idempotency_key' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'regex:/^[A-Za-z0-9._:-]+$/',
            ],
        ];
    }
}
