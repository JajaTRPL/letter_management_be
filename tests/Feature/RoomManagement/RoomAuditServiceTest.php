<?php

namespace Tests\Feature\RoomManagement;

use App\Enums\UserStatus;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Models\User;
use App\Services\RoomAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_audit_row_with_lab_inherited_from_room(): void
    {
        $laboratory = Laboratory::create(['name' => 'Lab RPL', 'code' => 'RPL-A']);
        $room = Room::factory()->laboratory($laboratory)->create();
        $actor = User::factory()->create([
            'role' => 'tendik',
            'tendik_role' => 'laboran',
            'laboratory_id' => $laboratory->id,
            'status' => UserStatus::Active,
        ]);

        $log = app(RoomAuditService::class)->record(
            $room,
            RoomAuditLog::SUBJECT_PHOTO,
            42,
            'uploaded',
            $actor,
            'Foto sampul baru diunggah.',
            '127.0.0.1',
        );

        $this->assertDatabaseHas('room_audit_logs', [
            'id' => $log->id,
            'room_id' => $room->id,
            'laboratory_id' => $laboratory->id,
            'subject_type' => RoomAuditLog::SUBJECT_PHOTO,
            'subject_id' => 42,
            'action' => 'uploaded',
            'actor_id' => $actor->id,
        ]);
        $this->assertNotNull($log->created_at);
    }

    public function test_accepts_null_room_and_actor_and_truncates_long_details(): void
    {
        $laboratory = Laboratory::create(['name' => 'Lab TAJ', 'code' => 'TAJ-A']);

        $log = app(RoomAuditService::class)->record(
            null,
            RoomAuditLog::SUBJECT_TEMPLATE,
            null,
            str_repeat('x', 64), // over the 32-char action column
            null,
            str_repeat('a', 700), // over the 500-char details column
            null,
            $laboratory,
        );

        $this->assertNull($log->room_id);
        $this->assertNull($log->actor_id);
        $this->assertSame($laboratory->id, $log->laboratory_id);
        $this->assertSame(32, mb_strlen($log->action));
        $this->assertSame(500, mb_strlen($log->details));
    }
}
