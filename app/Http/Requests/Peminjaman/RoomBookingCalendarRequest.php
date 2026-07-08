<?php

namespace App\Http\Requests\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RoomBookingCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_without:month', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_without:month', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(RoomBookingStatus::class)],
            'room_type' => ['nullable', Rule::enum(RoomType::class)],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('month') || $validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            if ($this->filled('month')) {
                return;
            }

            $from = Carbon::createFromFormat('Y-m-d', $this->string('from'));
            $to = Carbon::createFromFormat('Y-m-d', $this->string('to'));

            if ($from->diffInDays($to) + 1 > 62) {
                $validator->errors()->add('to', 'Rentang kalender maksimal 62 hari.');
            }
        });
    }
}
