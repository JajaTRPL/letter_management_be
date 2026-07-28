<?php

namespace Tests\Feature\Peminjaman;

use App\Models\Room;
use App\Models\User;
use App\Services\RoomBookingSubmissionSnapshotService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PDOException;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class RoomBookingInitialSubmissionTransientFailureTest extends TestCase
{
    use RoomBookingTestHelpers;
    use WorkflowTestHelpers;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        Carbon::setTestNow(Carbon::parse('2026-06-18 09:00:00', config('app.timezone')));
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_retryable_failure_after_file_write_is_clean_and_external_retry_is_fresh(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $key = 'initial-transient-external-retry-001';
        $realSnapshots = new RoomBookingSubmissionSnapshotService;
        $captureCalls = 0;
        $bookingObjects = [];
        Sanctum::actingAs($student);

        $this->partialMock(
            RoomBookingSubmissionSnapshotService::class,
            function (MockInterface $mock) use (
                $realSnapshots,
                &$captureCalls,
                &$bookingObjects,
            ): void {
                $mock->shouldReceive('capture')
                    ->twice()
                    ->andReturnUsing(function (...$arguments) use (
                        $realSnapshots,
                        &$captureCalls,
                        &$bookingObjects,
                    ) {
                        $captureCalls++;
                        $bookingObjects[] = spl_object_id($arguments[0]);

                        if ($captureCalls === 1) {
                            throw new PDOException('deadlock detected after attachment write');
                        }

                        return $realSnapshots->capture(...$arguments);
                    });
            },
        );

        $first = $this->post(
            '/api/mahasiswa/peminjaman-ruangan/requests',
            $this->payload($room, $key),
        )->assertStatus(500)
            ->assertJsonPath('code', 'infrastructure_error');

        $this->assertIsString($first->json('correlation_id'));
        $this->assertDatabaseCount('room_booking_requests', 0);
        $this->assertDatabaseCount('room_booking_attachments', 0);
        $this->assertDatabaseCount('room_booking_idempotency_records', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('room-booking-attachments'));

        $second = $this->post(
            '/api/mahasiswa/peminjaman-ruangan/requests',
            $this->payload($room, $key),
        )->assertCreated()->assertHeader('Idempotent-Replay', 'false');

        $this->assertNotSame($bookingObjects[0], $bookingObjects[1]);
        $this->assertDatabaseCount('room_booking_requests', 1);
        $this->assertDatabaseCount('room_booking_attachments', 1);
        $this->assertDatabaseCount('room_booking_status_histories', 1);
        $this->assertDatabaseCount('room_booking_submission_snapshots', 1);
        $this->assertDatabaseCount('room_booking_workflow_events', 2);
        $this->assertDatabaseCount('room_booking_audit_logs', 1);
        $this->assertDatabaseCount('room_booking_idempotency_records', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('room-booking-attachments'));
        $this->assertIsInt($second->json('data.id'));
    }

    private function student(): User
    {
        [$student] = $this->completeMahasiswa();

        return $student;
    }

    /** @return array<string, mixed> */
    private function payload(Room $room, string $key): array
    {
        return [
            'idempotency_key' => $key,
            'room_id' => $room->id,
            'activity_name' => 'Transient failure test',
            'purpose' => 'Prove clean external retry.',
            'participant_count' => 10,
            'start_at' => '2026-06-20T10:00:00+07:00',
            'end_at' => '2026-06-20T12:00:00+07:00',
            'surat_peminjaman_pdf' => UploadedFile::fake()->createWithContent(
                'surat-peminjaman.pdf',
                self::PDF,
            ),
        ];
    }
}
