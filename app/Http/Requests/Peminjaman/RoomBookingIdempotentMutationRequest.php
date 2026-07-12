<?php

namespace App\Http\Requests\Peminjaman;

use Illuminate\Foundation\Http\FormRequest;

abstract class RoomBookingIdempotentMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function idempotencyRules(): array
    {
        return [
            'expected_workflow_version' => ['required', 'integer', 'min:1'],
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
