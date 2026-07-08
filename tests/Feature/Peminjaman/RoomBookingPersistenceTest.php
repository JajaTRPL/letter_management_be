<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class RoomBookingPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_room_booking_persistence_contract_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('rooms', [
            'id',
            'code',
            'name',
            'type',
            'capacity',
            'location',
            'description',
            'is_active',
            'owning_laboratory_id',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('room_booking_requests', [
            'id',
            'requester_id',
            'room_id',
            'activity_name',
            'purpose',
            'participant_count',
            'start_at',
            'end_at',
            'status',
            'reviewer_id',
            'reviewed_at',
            'revision_note',
            'rejection_reason',
            'cancellation_reason',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('room_booking_status_histories', [
            'id',
            'room_booking_request_id',
            'from_status',
            'to_status',
            'actor_id',
            'note',
            'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('room_booking_attachments', [
            'id',
            'room_booking_request_id',
            'document_type',
            'original_name',
            'mime_type',
            'size_bytes',
            'storage_disk',
            'storage_path',
            'checksum_sha256',
            'uploaded_by',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('room_booking_audit_logs', [
            'id',
            'room_booking_request_id',
            'room_booking_attachment_id',
            'actor_id',
            'action',
            'document_type',
            'original_name',
            'size_bytes',
            'checksum_sha256',
            'storage_path_hash',
            'ip_address',
            'user_agent',
            'created_at',
        ]));

        $this->assertSqliteIndexes('rooms', [
            'rooms_code_unique',
            'rooms_type_active_idx',
            'rooms_owning_lab_idx',
        ]);
        $this->assertSqliteIndexes('room_booking_requests', [
            'rbr_room_status_window_idx',
            'rbr_requester_status_idx',
            'rbr_status_created_idx',
            'rbr_room_start_idx',
        ]);
        $this->assertSqliteIndexes('room_booking_status_histories', [
            'rbsh_request_created_idx',
        ]);
        $this->assertSqliteIndexes('room_booking_attachments', [
            'rba_booking_document_unique',
            'rba_document_created_idx',
        ]);
        $this->assertSqliteIndexes('room_booking_audit_logs', [
            'rbal_booking_action_idx',
        ]);
    }

    public function test_room_can_be_created_with_classroom_type_and_active_default(): void
    {
        $room = Room::create([
            'code' => 'TEST-CLASS-01',
            'name' => 'Test Classroom',
            'type' => RoomType::Classroom,
            'capacity' => 30,
            'location' => 'Test Building',
        ]);

        $room->refresh();

        $this->assertSame(RoomType::Classroom, $room->type);
        $this->assertTrue($room->is_active);
        $this->assertNull($room->owning_laboratory_id);
    }

    public function test_laboratory_room_can_link_to_existing_laboratory(): void
    {
        $laboratory = $this->laboratory();
        $room = Room::factory()->laboratory($laboratory)->create();

        $this->assertSame(RoomType::Laboratory, $room->type);
        $this->assertTrue($room->owningLaboratory->is($laboratory));
        $this->assertTrue($laboratory->fresh()->rooms->contains($room));
    }

    public function test_room_code_must_be_unique(): void
    {
        Room::factory()->create(['code' => 'TEST-UNIQUE-01']);

        $this->expectException(QueryException::class);

        Room::factory()->create(['code' => 'TEST-UNIQUE-01']);
    }

    public function test_room_ownership_and_capacity_invariants_are_enforced(): void
    {
        $laboratory = $this->laboratory();

        try {
            Room::factory()->create([
                'type' => RoomType::Classroom,
                'owning_laboratory_id' => $laboratory->id,
            ]);
            $this->fail('A classroom with an owning laboratory should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('rooms', 0);
        }

        try {
            Room::factory()->create([
                'type' => RoomType::Laboratory,
                'owning_laboratory_id' => null,
            ]);
            $this->fail('A laboratory room without an owning laboratory should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('rooms', 0);
        }

        $this->expectException(InvalidArgumentException::class);

        Room::factory()->create(['capacity' => 0]);
    }

    public function test_booking_request_belongs_to_requester_room_and_optional_reviewer(): void
    {
        $requester = $this->user();
        $reviewer = $this->user([
            'role' => 'tendik',
            'tendik_role' => 'sarpras',
        ]);
        $room = Room::factory()->create();

        $booking = RoomBookingRequest::factory()
            ->for($requester, 'requester')
            ->for($room)
            ->reviewedBy($reviewer)
            ->create();

        $this->assertTrue($booking->requester->is($requester));
        $this->assertTrue($booking->room->is($room));
        $this->assertTrue($booking->reviewer->is($reviewer));
        $this->assertTrue($requester->fresh()->requestedRoomBookings->contains($booking));
        $this->assertTrue($reviewer->fresh()->reviewedRoomBookings->contains($booking));
        $this->assertTrue($room->fresh()->roomBookingRequests->contains($booking));
    }

    public function test_booking_statuses_accept_exact_required_enum_values(): void
    {
        $this->assertSame([
            'submitted',
            'revision_requested',
            'approved',
            'rejected',
            'cancelled',
        ], RoomBookingStatus::values());

        foreach (RoomBookingStatus::cases() as $status) {
            $booking = RoomBookingRequest::factory()->status($status)->create();
            $this->assertSame($status, $booking->status);
        }
    }

    public function test_status_history_records_transition_actor_note_and_relationships(): void
    {
        $actor = $this->user([
            'role' => 'tendik',
            'tendik_role' => 'sarpras',
        ]);
        $booking = RoomBookingRequest::factory()->create();

        $history = RoomBookingStatusHistory::create([
            'room_booking_request_id' => $booking->id,
            'from_status' => RoomBookingStatus::Submitted,
            'to_status' => RoomBookingStatus::Approved,
            'actor_id' => $actor->id,
            'note' => 'Test-only approval history.',
        ]);

        $this->assertSame(RoomBookingStatus::Submitted, $history->from_status);
        $this->assertSame(RoomBookingStatus::Approved, $history->to_status);
        $this->assertTrue($history->roomBookingRequest->is($booking));
        $this->assertTrue($history->actor->is($actor));
        $this->assertSame('Test-only approval history.', $history->note);
        $this->assertNotNull($history->created_at);
        $this->assertTrue($booking->fresh()->statusHistories->contains($history));
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
        ], $attributes));
    }

    private function laboratory(): Laboratory
    {
        return Laboratory::create([
            'name' => 'Test Laboratory',
            'code' => 'TEST-LAB-'.fake()->unique()->numerify('####'),
            'department_id' => null,
        ]);
    }

    private function assertSqliteIndexes(string $table, array $expected): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->all();

        foreach ($expected as $index) {
            $this->assertContains($index, $indexes);
        }
    }
}
