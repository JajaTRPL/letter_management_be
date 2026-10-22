<?php

namespace Tests\Feature\Peminjaman;

use App\Models\AppNotification;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingReturnRequest;
use Tests\TestCase;

/**
 * PostgreSQL returns timestamptz values with the session offset (UTC on the
 * server), while the app writes naive WIB wall-clock strings. A 16:25 WIB
 * booking must read back as 16:25 WIB, never 23:25.
 */
class WallClockTimestampReadTest extends TestCase
{
    public function test_postgres_offset_values_read_back_as_the_stored_wib_wall_clock(): void
    {
        foreach (['2026-10-22 16:25:00+00', '2026-10-22 16:25:00+07', '2026-10-22 16:25:00.123456+00', '2026-10-22T16:25:00Z'] as $raw) {
            $occurrence = new RoomBookingOccurrence;
            $occurrence->setRawAttributes(['start_at' => $raw]);

            $this->assertSame('2026-10-22T16:25:00+07:00', $occurrence->start_at->toIso8601String(), $raw);
        }
    }

    public function test_naive_values_are_unchanged_and_other_models_share_the_rule(): void
    {
        $occurrence = new RoomBookingOccurrence;
        $occurrence->setRawAttributes(['end_at' => '2026-10-22 16:30:00']);
        $this->assertSame('2026-10-22T16:30:00+07:00', $occurrence->end_at->toIso8601String());

        $return = new RoomBookingReturnRequest;
        $return->setRawAttributes(['key_received_at' => '2026-10-22 17:10:00+00']);
        $this->assertSame('17:10', $return->key_received_at->format('H:i'));

        $notification = new AppNotification;
        $notification->setRawAttributes(['occurred_at' => '2026-10-22 16:00:00+00']);
        $this->assertSame('16:00', $notification->occurred_at->format('H:i'));
    }
}
