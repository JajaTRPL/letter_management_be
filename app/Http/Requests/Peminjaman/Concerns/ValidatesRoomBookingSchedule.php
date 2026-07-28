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

            $mode = $this->input('booking_mode', 'single_day');
            $lastOccurrenceDate = $mode === 'consecutive_days'
                ? (string) $this->input('occurrence_end_date')
                : $startAt->toDateString();
            $dailyEndIsOvernight = $endAt->format('H:i:s') <= $startAt->format('H:i:s');
            $expectedEndDate = Carbon::parse($lastOccurrenceDate, $timezone)
                ->addDays($dailyEndIsOvernight ? 1 : 0)
                ->toDateString();

            if ($endAt->toDateString() !== $expectedEndDate) {
                $validator->errors()->add('end_at', 'Tanggal/jam selesai tidak sesuai dengan pola penggunaan harian.');
            }

            if (! $startAt->greaterThan(Carbon::now($timezone))) {
                $validator->errors()->add('start_at', 'Jadwal peminjaman harus dimulai setelah waktu saat ini.');
            }
        });
    }
}
