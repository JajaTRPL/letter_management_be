<?php

namespace App\Http\Requests\Peminjaman\Concerns;

use Illuminate\Support\Carbon;

trait ValidatesRoomBookingSchedule
{
    /**
     * Rooms may only be booked for use between 07:00 and 22:00 local time,
     * same-day (no wrapping past midnight). This does not restrict when a
     * request may be *submitted* — only the requested start_at/end_at
     * time-of-day, which is what actually occupies the room.
     */
    private const OPERATIONAL_START_TIME = '07:00:00';
    private const OPERATIONAL_END_TIME = '22:00:00';

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

            $operationalHoursLabel = 'jam operasional ruangan (07:00-22:00)';
            $startTimeOfDay = $startAt->format('H:i:s');
            $endTimeOfDay = $endAt->format('H:i:s');
            $hasOperationalHourError = false;

            if ($startTimeOfDay < self::OPERATIONAL_START_TIME || $startTimeOfDay > self::OPERATIONAL_END_TIME) {
                $validator->errors()->add('start_at', "Waktu mulai peminjaman harus berada dalam {$operationalHoursLabel}.");
                $hasOperationalHourError = true;
            }

            if ($endTimeOfDay < self::OPERATIONAL_START_TIME || $endTimeOfDay > self::OPERATIONAL_END_TIME) {
                $validator->errors()->add('end_at', "Waktu selesai peminjaman harus berada dalam {$operationalHoursLabel}.");
                $hasOperationalHourError = true;
            } elseif ($endTimeOfDay <= $startTimeOfDay) {
                // Both times individually fall inside 07:00-22:00, but end
                // <= start means the booking would wrap past midnight into
                // the next day — outside operating hours either way.
                $validator->errors()->add('end_at', "Peminjaman tidak boleh melewati tengah malam; waktu selesai harus pada hari yang sama dan dalam {$operationalHoursLabel}.");
                $hasOperationalHourError = true;
            }

            if ($hasOperationalHourError) {
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
