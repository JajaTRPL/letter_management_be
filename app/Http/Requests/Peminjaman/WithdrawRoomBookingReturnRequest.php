<?php

namespace App\Http\Requests\Peminjaman;

use App\Http\Requests\Peminjaman\Concerns\ValidatesRoomBookingIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

class WithdrawRoomBookingReturnRequest extends FormRequest
{
    use ValidatesRoomBookingIdempotencyKey;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return array_merge($this->idempotencyKeyRules(), [
            'expected_occurrence_version' => ['required', 'integer', 'min:1'],
            'expected_return_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
