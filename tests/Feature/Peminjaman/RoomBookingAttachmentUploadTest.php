<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingAttachment;
use App\Models\RoomBookingAuditLog;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RoomBookingAttachmentUploadTest extends RoomBookingApiTestCase
{
    public function test_replacement_is_rejected_while_cancellation_request_is_pending(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $existing = $this->createSuratPeminjamanAttachment($booking, $student, 'lama.pdf');
        $this->actingAsUser($student);
        $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            [
                'reason' => 'Mohon dibatalkan.',
                'expected_workflow_version' => 1,
                'idempotency_key' => 'attach-pending-block-01',
            ],
        )->assertCreated();
        $versionAfterRequest = (int) $booking->fresh()->workflow_version;

        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('baru.pdf')],
        )->assertConflict()
            ->assertJsonPath('code', 'pending_cancellation_request');

        // Previous attachment (row and file) fully intact; no orphan file.
        $fresh = RoomBookingAttachment::query()->findOrFail($existing->id);
        $this->assertSame('lama.pdf', $fresh->original_name);
        $this->assertSame($existing->storage_path, $fresh->storage_path);
        Storage::disk('local')->assertExists($existing->storage_path);
        $this->assertSame(
            [$existing->storage_path],
            Storage::disk('local')->allFiles('room-booking-attachments/surat-peminjaman'),
        );
        $this->assertSame(1, RoomBookingAttachment::query()->count());
        $this->assertSame($versionAfterRequest, (int) $booking->fresh()->workflow_version);
        $this->assertSame(RoomBookingStatus::RevisionRequested, $booking->fresh()->status);
    }

    public function test_replacement_orderings_with_cancellation_are_deterministic(): void
    {
        // Replacement first, then the cancellation request: both succeed.
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $this->createSuratPeminjamanAttachment($booking, $student, 'awal.pdf');
        $this->actingAsUser($student);

        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('pengganti.pdf')],
        )->assertOk();
        $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            [
                'reason' => 'Batal setelah berkas diganti.',
                'expected_workflow_version' => 1,
                'idempotency_key' => 'attach-order-replace-first',
            ],
        )->assertCreated();

        // Final state reached first: replacement is rejected by the locked
        // guard even though the fast-path saw revision_requested is bypassed
        // here by driving the service directly with a stale route model.
        $finalState = $this->markRevisionRequested($this->classroom(), $student);
        $staleModel = RoomBookingRequest::query()->findOrFail($finalState->id);
        $finalState->forceFill(['status' => RoomBookingStatus::Cancelled])->save();

        try {
            app(RoomBookingAttachmentService::class)->storeSuratPeminjaman(
                $staleModel,
                $this->validPdfUpload('terlambat.pdf'),
                $student,
                'upload',
                null,
                lockedGuard: function (RoomBookingRequest $lockedBooking): void {
                    if ($lockedBooking->status !== RoomBookingStatus::RevisionRequested) {
                        throw new \App\Services\RoomBookingDomainException(
                            \App\Services\RoomBookingDomainException::INVALID_TRANSITION,
                            'Surat peminjaman hanya dapat diganti saat pengajuan berstatus revision_requested.',
                        );
                    }
                },
            );
            $this->fail('Expected the locked guard to refuse the replacement.');
        } catch (\App\Services\RoomBookingDomainException $exception) {
            // Domain semantics preserved (not wrapped as infrastructure).
            $this->assertSame(
                \App\Services\RoomBookingDomainException::INVALID_TRANSITION,
                $exception->reason,
            );
        }

        $this->assertSame(0, RoomBookingAttachment::query()
            ->where('room_booking_request_id', $finalState->id)
            ->count());
        $this->assertSame([], Storage::disk('local')
            ->allFiles('room-booking-attachments/surat-peminjaman/'.$finalState->id));
    }

    public function test_replacement_supports_expected_workflow_version(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $this->createSuratPeminjamanAttachment($booking, $student, 'asli.pdf');
        $this->actingAsUser($student);

        // Stale version: refused under lock, nothing changes.
        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            [
                'surat_peminjaman_pdf' => $this->validPdfUpload('gagal.pdf'),
                'expected_workflow_version' => 99,
            ],
        )->assertConflict()->assertJsonPath('code', 'stale_workflow_version');
        $this->assertSame('asli.pdf', RoomBookingAttachment::firstOrFail()->original_name);

        // Current version: succeeds. Omitted version stays compatible
        // (covered by the pre-existing replacement tests).
        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            [
                'surat_peminjaman_pdf' => $this->validPdfUpload('sukses.pdf'),
                'expected_workflow_version' => 1,
            ],
        )->assertOk();
        $this->assertSame('sukses.pdf', RoomBookingAttachment::firstOrFail()->original_name);
        // Replacement itself never bumps the workflow version.
        $this->assertSame(1, (int) $booking->fresh()->workflow_version);
    }

    public function test_unexpected_guard_failure_is_safe_and_preserves_previous_attachment(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $existing = $this->createSuratPeminjamanAttachment($booking, $student, 'tetap.pdf');

        try {
            app(RoomBookingAttachmentService::class)->storeSuratPeminjaman(
                $booking,
                $this->validPdfUpload('rusak.pdf'),
                $student,
                'replacement',
                null,
                lockedGuard: function (): void {
                    throw new RuntimeException('Simulated unexpected metadata failure.');
                },
            );
            $this->fail('Expected the wrapped infrastructure failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Failed to persist room booking attachment metadata.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString(
                'room-booking-attachments',
                $exception->getMessage(),
            );
        }

        // Old attachment preserved, new file cleaned, rows rolled back.
        Storage::disk('local')->assertExists($existing->storage_path);
        $this->assertSame('tetap.pdf', RoomBookingAttachment::firstOrFail()->original_name);
        $this->assertSame(
            [$existing->storage_path],
            Storage::disk('local')->allFiles('room-booking-attachments/surat-peminjaman'),
        );
    }

    public function test_old_rows_render_safe_empty_attachment_metadata(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $this->actingAsUser($student);

        $response = $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.surat_peminjaman_pdf.exists', false)
            ->assertJsonPath('data.surat_peminjaman_pdf.has_surat_peminjaman_pdf', false)
            ->assertJsonPath('data.surat_peminjaman_pdf.original_name', null)
            ->assertJsonPath('data.surat_peminjaman_pdf.size_bytes', null)
            ->assertJsonPath('data.surat_peminjaman_pdf.preview_url', null)
            ->assertJsonPath('data.surat_peminjaman_pdf.download_url', null);

        $this->assertNoStorageLeakage($response->json('data'));
    }

    public function test_revision_owner_can_create_first_attachment_for_old_row_and_replace_it(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $this->actingAsUser($student);

        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('first.pdf')],
        )
            ->assertOk()
            ->assertJsonPath('data.surat_peminjaman_pdf.exists', true)
            ->assertJsonPath('data.surat_peminjaman_pdf.original_name', 'first.pdf');

        $first = RoomBookingAttachment::firstOrFail();
        Storage::disk('local')->assertExists($first->storage_path);

        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('replacement.pdf')],
        )
            ->assertOk()
            ->assertJsonPath('data.surat_peminjaman_pdf.original_name', 'replacement.pdf');

        $replacement = RoomBookingAttachment::firstOrFail();
        $this->assertSame($first->id, $replacement->id);
        $this->assertNotSame($first->storage_path, $replacement->storage_path);
        Storage::disk('local')->assertMissing($first->storage_path);
        Storage::disk('local')->assertExists($replacement->storage_path);
        $this->assertDatabaseCount('room_booking_attachments', 1);
        $this->assertDatabaseHas('room_booking_audit_logs', [
            'room_booking_request_id' => $booking->id,
            'action' => 'upload',
        ]);
        $this->assertDatabaseHas('room_booking_audit_logs', [
            'room_booking_request_id' => $booking->id,
            'action' => 'replacement',
        ]);
    }

    public function test_replacement_is_limited_to_owner_and_revision_requested_status(): void
    {
        $owner = $this->student();
        $other = $this->student(['email' => 'other-replace@example.test']);
        $room = $this->classroom();
        $revision = $this->markRevisionRequested($room, $owner);
        $submitted = $this->roomBooking(
            $room,
            $owner,
            RoomBookingStatus::Submitted,
            '2026-06-21 10:00:00',
            '2026-06-21 12:00:00',
        );
        $approved = $this->roomBooking(
            $room,
            $owner,
            RoomBookingStatus::Approved,
            '2026-06-22 10:00:00',
            '2026-06-22 12:00:00',
        );
        $rejected = $this->roomBooking(
            $room,
            $owner,
            RoomBookingStatus::Rejected,
            '2026-06-23 10:00:00',
            '2026-06-23 12:00:00',
        );
        $cancelled = $this->roomBooking(
            $room,
            $owner,
            RoomBookingStatus::Cancelled,
            '2026-06-24 10:00:00',
            '2026-06-24 12:00:00',
        );

        $this->actingAsUser($other);
        $this->post(
            $this->mahasiswaUrl("/{$revision->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('other.pdf')],
        )->assertNotFound();

        $this->actingAsUser($owner);
        foreach ([$submitted, $approved, $rejected, $cancelled] as $booking) {
            $this->post(
                $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
                ['surat_peminjaman_pdf' => $this->validPdfUpload("{$booking->id}.pdf")],
            )
                ->assertConflict()
                ->assertJsonPath('code', 'invalid_transition');
        }
    }

    public function test_normal_put_edit_does_not_require_pdf(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $booking = $this->markRevisionRequested($room, $student);
        $this->actingAsUser($student);

        $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room, [
                'activity_name' => 'File-free edit remains valid',
                'start_at' => '2026-06-22T13:00:00+07:00',
                'end_at' => '2026-06-22T15:00:00+07:00',
            ]),
        )
            ->assertOk()
            ->assertJsonPath('data.activity_name', 'File-free edit remains valid');
    }

    public function test_preview_and_download_authorization_uses_existing_reviewer_resolver(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $booking = $this->roomBooking($room, $student);
        $this->createSuratPeminjamanAttachment($booking, $student);

        $previewUrl = "/api/peminjaman-ruangan/{$booking->id}/attachment/surat-peminjaman/preview";
        $downloadUrl = "/api/peminjaman-ruangan/{$booking->id}/attachment/surat-peminjaman/download";

        $this->getJson($previewUrl)->assertUnauthorized();

        $this->actingAsUser($this->student(['email' => 'not-owner@example.test']));
        $this->getJson($previewUrl)->assertNotFound();

        $this->actingAsUser($student);
        $ownerPreview = $this->get($previewUrl);
        $ownerPreview->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $ownerPreview->streamedContent());

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->get($previewUrl)->assertOk();

        $this->actingAsUser($this->persuratan());
        $this->getJson($previewUrl)->assertNotFound();

        $this->actingAsUser($this->superAdmin());
        $download = $this->get($downloadUrl);
        $download->assertOk();
        $this->assertStringContainsString(
            'attachment',
            strtolower((string) $download->headers->get('Content-Disposition')),
        );

        $laboratory = $this->bookingLaboratory('ATTACH');
        $labBooking = $this->roomBooking(
            $this->laboratoryRoom($laboratory),
            $student,
            startAt: '2026-06-25 10:00:00',
            endAt: '2026-06-25 12:00:00',
        );
        $this->createSuratPeminjamanAttachment($labBooking, $student);
        $this->actingAsUser($this->reviewerUser('laboran', $laboratory));
        $this->get("/api/peminjaman-ruangan/{$labBooking->id}/attachment/surat-peminjaman/preview")
            ->assertOk();
    }

    public function test_attachment_upload_rejects_invalid_files_and_sanitizes_download_filename(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $this->actingAsUser($student);
        $url = $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman");

        $this->post($url, ['surat_peminjaman_pdf' => $this->invalidContentPdfUpload()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('surat_peminjaman_pdf');

        $this->post($url, [
            'surat_peminjaman_pdf' => UploadedFile::fake()
                ->createWithContent('surat.txt', self::VALID_PDF_BYTES),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('surat_peminjaman_pdf');

        $this->post($url, [
            'surat_peminjaman_pdf' => UploadedFile::fake()
                ->create('too-large.pdf', RoomBookingAttachmentService::MAX_KB + 1, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('surat_peminjaman_pdf');

        $this->post($url, [
            'surat_peminjaman_pdf' => $this->validPdfUpload("..\\evil\r\nname.pdf"),
        ])->assertOk();

        $download = $this->get(
            "/api/peminjaman-ruangan/{$booking->id}/attachment/surat-peminjaman/download",
        );
        $disposition = (string) $download->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
    }

    public function test_service_deletes_new_file_when_database_transaction_fails(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $service = app(RoomBookingAttachmentService::class);

        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('forced transaction failure'));

        try {
            $service->storeSuratPeminjaman(
                $booking,
                $this->validPdfUpload('orphan-check.pdf'),
                $student,
                'upload',
            );
            $this->fail('Expected storage persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to persist', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles('room-booking-attachments'));
    }

    public function test_upload_and_replacement_audit_rows_are_written_synchronously(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $this->actingAsUser($student);

        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('audit-upload.pdf')],
        )->assertOk();

        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('audit-replacement.pdf')],
        )->assertOk();

        $this->assertSame(
            ['upload', 'replacement'],
            RoomBookingAuditLog::query()
                ->where('room_booking_request_id', $booking->id)
                ->orderBy('id')
                ->pluck('action')
                ->all(),
        );
        $this->assertNotNull(RoomBookingAuditLog::query()->latest('id')->value('storage_path_hash'));
    }

    public function test_attachment_RateLimiter_blocks_excessive_replacements(): void
    {
        $student = $this->student();
        $booking = $this->markRevisionRequested($this->classroom(), $student);
        $this->actingAsUser($student);

        for ($i = 1; $i <= 30; $i++) {
            $this->post(
                $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
                ['surat_peminjaman_pdf' => $this->validPdfUpload("rate-{$i}.pdf")],
            )->assertOk();
        }

        $this->post(
            $this->mahasiswaUrl("/{$booking->id}/attachment/surat-peminjaman"),
            ['surat_peminjaman_pdf' => $this->validPdfUpload('rate-blocked.pdf')],
        )->assertTooManyRequests();
    }

    private function invalidContentPdfUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'not-pdf-');
        file_put_contents($path, 'not a pdf body');

        return new UploadedFile($path, 'fake.pdf', 'application/pdf', null, true);
    }

    private function assertNoStorageLeakage(array $payload): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('room-booking-attachments', $encoded);
        $this->assertStringNotContainsString('storage_path', $encoded);
        $this->assertStringNotContainsString('storage_disk', $encoded);
        $this->assertStringNotContainsString('/storage', $encoded);
        $this->assertStringNotContainsString('/api/storage', $encoded);
    }
}
