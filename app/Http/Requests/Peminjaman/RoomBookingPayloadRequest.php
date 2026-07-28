<?php

namespace App\Http\Requests\Peminjaman;

use App\Http\Requests\Peminjaman\Concerns\ValidatesRoomBookingSchedule;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class RoomBookingPayloadRequest extends FormRequest
{
    use ValidatesRoomBookingSchedule;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => [
                'required',
                'integer',
                Rule::exists('rooms', 'id')->where('is_active', true),
            ],
            'activity_name' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:5000'],
            'participant_count' => ['required', 'integer', 'min:1'],
            'start_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'end_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'booking_mode' => ['sometimes', Rule::in(['single_day', 'consecutive_days'])],
            'occurrence_end_date' => [
                Rule::requiredIf(fn () => $this->input('booking_mode', 'single_day') === 'consecutive_days'),
                'nullable',
                'date_format:Y-m-d',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $this->addScheduleValidation($validator);

        $validator->after(function ($validator) {
            if (
                $validator->errors()->has('room_id')
                || $validator->errors()->has('participant_count')
            ) {
                return;
            }

            $room = Room::find($this->integer('room_id'));
            $participantCount = $this->integer('participant_count');

            if ($room && $participantCount > $room->capacity) {
                $validator->errors()->add(
                    'participant_count',
                    'Jumlah peserta melebihi kapasitas ruangan.',
                );
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $timezone = config('app.timezone');
            $start = \Illuminate\Support\Carbon::parse($this->input('start_at'))->setTimezone($timezone);
            $lastDate = $this->input('booking_mode', 'single_day') === 'consecutive_days'
                ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', (string) $this->input('occurrence_end_date'), $timezone)
                : $start->copy()->startOfDay();
            $days = $start->copy()->startOfDay()->diffInDays($lastDate, false) + 1;
            $maximum = max(1, (int) config('room_booking.maximum_consecutive_days', 14));

            if ($days < 1) {
                $validator->errors()->add('occurrence_end_date', 'Tanggal selesai harus sama atau setelah tanggal mulai.');
            } elseif ($days > $maximum) {
                $validator->errors()->add('occurrence_end_date', "Rentang peminjaman maksimal {$maximum} hari berturut-turut.");
            }
        });
    }
}
