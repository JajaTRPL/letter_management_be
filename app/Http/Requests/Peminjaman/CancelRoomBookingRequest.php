<?php

namespace App\Http\Requests\Peminjaman;

use Illuminate\Foundation\Http\FormRequest;

class CancelRoomBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'max:'.config('room_booking.reason_max_length', 2000),
            ],
            'expected_workflow_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
