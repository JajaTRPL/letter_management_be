<?php

namespace App\Http\Requests\Peminjaman;

use App\Enums\RoomType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'type' => ['nullable', Rule::enum(RoomType::class)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            $from = Carbon::createFromFormat('Y-m-d', $this->string('from'));
            $to = Carbon::createFromFormat('Y-m-d', $this->string('to'));

            if ($from->diffInDays($to) + 1 > 62) {
                $validator->errors()->add('to', 'Rentang ketersediaan maksimal 62 hari.');
            }
        });
    }
}
