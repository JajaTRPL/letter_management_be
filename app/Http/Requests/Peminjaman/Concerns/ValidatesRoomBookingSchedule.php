<?php

namespace App\Http\Requests\Peminjaman\Concerns;

use Illuminate\Support\Carbon;

trait ValidatesRoomBookingSchedule
{
    protected function addScheduleValidation($validator): void
    {
        $validator->after(function ($validator) {
            if (
                $validator->errors()->has('start_at')
                || $validator->errors()->has('end_at')
            ) {
                return;
            }

            $timezone = config('app.timezone');
            $startAt = Carbon::parse($this->input('start_at'))->setTimezone($timezone);
            $endAt = Carbon::parse($this->input('end_at'))->setTimezone($timezone);

            if (! $startAt->lessThan($endAt)) {
                $validator->errors()->add('end_at', 'Jam selesai harus lebih dari jam mulai.');

                return;
            }

            if ($startAt->toDateString() !== $endAt->toDateString()) {
                $validator->errors()->add('end_at', 'Kegiatan harus selesai di hari yang sama. Untuk kegiatan yang melewati tengah malam, ajukan jadwal terpisah.');
            }

            if (! $startAt->greaterThan(Carbon::now($timezone))) {
                $validator->errors()->add('start_at', 'Jadwal peminjaman harus dimulai setelah waktu saat ini.');
            }
        });
    }
}
