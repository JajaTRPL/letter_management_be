<?php

namespace Tests\Feature\Peminjaman;

use App\Models\Room;
use App\Models\RoomBookingIdempotencyRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RoomBookingInitialSubmissionIdempotencyTest extends RoomBookingApiTestCase
{
    public function test_initial_submission_requires_an_idempotency_key(): void
    {
        $this->actingAsUser($this->student());
        $payload = $this->validBookingPayloadWithPdf($this->classroom());
        unset($payload['idempotency_key']);

        $this->post($this->mahasiswaUrl('/requests'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('room_booking_idempotency_records', 0);
        $this->assertDatabaseCount('room_booking_requests', 0);
    }

    public function test_exact_replay_returns_the_original_successful_response(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $key = 'initial-exact-replay-001';
        $this->actingAsUser($student);

        $first = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, ['idempotency_key' => $key]),
        )->assertCreated()->assertHeader('Idempotent-Replay', 'false');
        $originalBody = $first->json();

        $replay = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, ['idempotency_key' => $key]),
        )->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($originalBody, $replay->json());
        $this->assertTrue(Str::isUuid($first->json('data.correlation_id')));
        $this->assertIsInt($first->json('data.id'));

        $record = RoomBookingIdempotencyRecord::query()->firstOrFail();
        $this->assertNotSame($key, $record->idempotency_key_hash);
        $this->assertSame(64, strlen($record->idempotency_key_hash));
        $this->assertSame(64, strlen($record->payload_hash));
        $this->assertSame($originalBody, $record->safe_response_body);
        $this->assertSame($first->json('data.id'), $record->room_booking_request_id);

        $encodedRecord = json_encode($record->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($key, $encodedRecord);
        $this->assertStringNotContainsString('room-booking-attachments/', $encodedRecord);
    }

    public function test_same_key_rejects_changed_business_payload_and_changed_pdf(): void
    {
        $student = $this->student();
        $this->actingAsUser($student);

        $payloadRoom = $this->classroom();
        $payloadKey = 'initial-changed-payload-001';
        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($payloadRoom, [
                'idempotency_key' => $payloadKey,
            ]),
        )->assertCreated();
        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($payloadRoom, [
                'idempotency_key' => $payloadKey,
                'purpose' => 'Changed purpose under the same key.',
            ]),
        )->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');

        $fileRoom = $this->classroom();
        $fileKey = 'initial-changed-file-001';
        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->payloadWithPdfBytes($fileRoom, $fileKey, self::VALID_PDF_BYTES),
        )->assertCreated();
        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->payloadWithPdfBytes(
                $fileRoom,
                $fileKey,
                self::VALID_PDF_BYTES."\n% changed content\n",
            ),
        )->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');

        $this->assertDatabaseCount('room_booking_requests', 2);
        $this->assertDatabaseCount('room_booking_attachments', 2);
        $this->assertDatabaseCount('room_booking_idempotency_records', 2);
    }

    public function test_failed_validation_does_not_consume_the_key(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $key = 'initial-validation-reuse-001';
        $this->actingAsUser($student);

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, [
                'idempotency_key' => $key,
                'purpose' => '',
            ]),
        )->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->assertDatabaseCount('room_booking_idempotency_records', 0);

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, ['idempotency_key' => $key]),
        )->assertCreated()->assertHeader('Idempotent-Replay', 'false');

        $this->assertDatabaseCount('room_booking_idempotency_records', 1);
        $this->assertDatabaseCount('room_booking_requests', 1);
    }

    public function test_deterministic_duplicate_race_creates_one_complete_aggregate(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $key = 'initial-race-single-winner-001';
        $this->actingAsUser($student);

        $winner = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, ['idempotency_key' => $key]),
        )->assertCreated()->assertHeader('Idempotent-Replay', 'false');
        $loser = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, ['idempotency_key' => $key]),
        )->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($winner->json('data.id'), $loser->json('data.id'));
        $this->assertDatabaseCount('room_booking_requests', 1);
        $this->assertDatabaseCount('room_booking_attachments', 1);
        $this->assertDatabaseCount('room_booking_audit_logs', 1);
        $this->assertDatabaseCount('room_booking_submission_snapshots', 1);
        $this->assertDatabaseCount('room_booking_status_histories', 1);
        $this->assertDatabaseCount('room_booking_workflow_events', 2);
        $this->assertDatabaseCount('room_booking_idempotency_records', 1);
    }

    public function test_same_content_with_a_different_filename_replays(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $key = 'initial-file-name-excluded-001';
        $this->actingAsUser($student);

        $first = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->payloadWithPdfBytes($room, $key, self::VALID_PDF_BYTES, 'first.pdf'),
        )->assertCreated();
        $replay = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->payloadWithPdfBytes($room, $key, self::VALID_PDF_BYTES, 'renamed.pdf'),
        )->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('room_booking_attachments', 1);
    }

    public function test_initial_submission_route_keeps_its_attachment_rate_limit(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/mahasiswa/peminjaman-ruangan/requests'
                && in_array('POST', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertContains('throttle:peminjaman-attachment', $route->gatherMiddleware());
    }

    /** @return array<string, mixed> */
    private function payloadWithPdfBytes(
        Room $room,
        string $key,
        string $bytes,
        string $name = 'surat-peminjaman.pdf',
    ): array {
        return array_merge(
            $this->validBookingPayload($room),
            [
                'idempotency_key' => $key,
                'surat_peminjaman_pdf' => UploadedFile::fake()->createWithContent(
                    $name,
                    $bytes,
                ),
            ],
        );
    }
}
