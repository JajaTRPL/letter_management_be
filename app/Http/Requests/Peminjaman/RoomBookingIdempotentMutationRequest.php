<?php

namespace App\Http\Requests\Peminjaman;

use App\Http\Requests\Peminjaman\Concerns\ValidatesRoomBookingIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

abstract class RoomBookingIdempotentMutationRequest extends FormRequest
{
    use ValidatesRoomBookingIdempotencyKey;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function idempotencyRules(): array
    {
        return array_merge([
            'expected_workflow_version' => ['required', 'integer', 'min:1'],
        ], $this->idempotencyKeyRules());
    }
}
