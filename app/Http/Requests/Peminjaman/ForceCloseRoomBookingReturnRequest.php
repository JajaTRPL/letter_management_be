<?php

namespace App\Http\Requests\Peminjaman;

use App\Http\Requests\Peminjaman\Concerns\ValidatesRoomBookingIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

class ForceCloseRoomBookingReturnRequest extends FormRequest
{
    use ValidatesRoomBookingIdempotencyKey;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return array_merge($this->idempotencyKeyRules(), [
            'expected_occurrence_version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
            'key_received_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
        ]);
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Catatan penyelesaian wajib diisi (misalnya: peminjam sudah dihubungi dan kunci telah diterima).',
        ];
    }
}
