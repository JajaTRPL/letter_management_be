<?php

namespace App\Http\Requests\Peminjaman;

use App\Http\Requests\Peminjaman\Concerns\ValidatesRoomBookingIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

class DecideRoomBookingReturnRequest extends FormRequest
{
    use ValidatesRoomBookingIdempotencyKey;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return array_merge($this->idempotencyKeyRules(), [
            'expected_occurrence_version' => ['required', 'integer', 'min:1'],
            'expected_return_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
            'key_received_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'received_time_change_reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
