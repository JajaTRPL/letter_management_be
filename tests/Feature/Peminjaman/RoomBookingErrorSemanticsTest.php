<?php

namespace Tests\Feature\Peminjaman;

use App\Services\RoomBookingAttachmentService;
use App\Services\RoomBookingSubmissionSnapshotService;
use App\Services\RoomBookingTransitionService;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;

class RoomBookingErrorSemanticsTest extends RoomBookingApiTestCase
{
    public function test_unexpected_submission_failure_returns_safe_500_and_cleans_file(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);
        $this->mock(
            RoomBookingSubmissionSnapshotService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('capture')
                ->once()
                ->andThrow(new RuntimeException(
                    'Snapshot pipeline unavailable at C:\\private\\artifact.pdf',
                )),
        );

        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        )->assertStatus(500)
            ->assertJsonPath('code', 'infrastructure_error')
            ->assertJsonPath('message', 'Terjadi gangguan saat memproses peminjaman ruangan. Silakan coba lagi.');

        $this->assertIsString($response->json('correlation_id'));
        $this->assertStringNotContainsString('Snapshot pipeline unavailable', $response->getContent());
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertDatabaseCount('room_booking_requests', 0);
        $this->assertDatabaseCount('room_booking_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('room-booking-attachments'));
    }

    public function test_expected_missing_attachment_remains_validation_failure(): void
    {
        $student = $this->student();
        $this->actingAsUser($student);

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayload($this->classroom()),
        )->assertUnprocessable()
            ->assertJsonValidationErrors('surat_peminjaman_pdf');
    }

    public function test_unexpected_attachment_metadata_failure_returns_safe_500(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);
        $this->mock(
            RoomBookingAttachmentService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('storeSuratPeminjaman')
                ->once()
                ->andThrow(new RuntimeException(
                    'Metadata failed at D:\\private\\room-booking.pdf',
                )),
        );

        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        )->assertStatus(500)->assertJsonPath('code', 'infrastructure_error');

        $this->assertStringNotContainsString('Metadata failed', $response->getContent());
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertDatabaseCount('room_booking_requests', 0);
        $this->assertDatabaseCount('room_booking_attachments', 0);
    }

    public function test_unexpected_reviewer_transition_failure_returns_safe_500(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->mock(
            RoomBookingTransitionService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('approve')
                ->once()
                ->andThrow(new RuntimeException(
                    'SQLSTATE internal reviewer failure at /srv/private.sql',
                )),
        );

        $response = $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertStatus(500)
            ->assertJsonPath('code', 'infrastructure_error');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('private.sql', $response->getContent());
        $this->assertSame(1, $booking->fresh()->workflow_version);
        $this->assertDatabaseCount('room_booking_workflow_events', 0);
    }

    public function test_unexpected_legacy_cancel_failure_returns_safe_500(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $this->actingAsUser($student);
        $this->mock(
            RoomBookingTransitionService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('cancel')
                ->once()
                ->andThrow(new RuntimeException('Unexpected legacy transition internals.')),
        );

        $response = $this->patchJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancel"),
            ['reason' => 'Legacy cancellation.'],
        )->assertStatus(500)->assertJsonPath('code', 'infrastructure_error');

        $this->assertStringNotContainsString('internals', $response->getContent());
        $this->assertSame(1, $booking->fresh()->workflow_version);
    }
}
