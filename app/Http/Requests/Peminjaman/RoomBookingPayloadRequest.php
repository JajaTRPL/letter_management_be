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
        });
    }
}
