<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingReturnStatus;
use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingReturnRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Services\RoomBookingOccurrenceEventService;
use App\Services\RoomBookingOccurrenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

class RoomBookingOccurrenceReturnApiTest extends RoomBookingApiTestCase
{
    public function test_single_multi_day_and_overnight_occurrences_are_generated(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);

        $single = $this->post($this->mahasiswaUrl('/requests'), $this->validBookingPayloadWithPdf($room, [
            'idempotency_key' => 'c7r-single-occurrence',
        ]))->assertCreated();
        $this->assertCount(1, $single->json('data.occurrences'));
        $this->assertSame('2026-06-20T12:30:00+07:00', $single->json('data.occurrences.0.return_due_at'));

        $multi = $this->post($this->mahasiswaUrl('/requests'), $this->validBookingPayloadWithPdf($room, [
            'idempotency_key' => 'c7r-multi-occurrence',
            'booking_mode' => 'consecutive_days',
            'occurrence_end_date' => '2026-06-22',
            'start_at' => '2026-06-20T09:00:00+07:00',
            'end_at' => '2026-06-22T12:00:00+07:00',
        ]))->assertCreated();
        $this->assertSame(['2026-06-20', '2026-06-21', '2026-06-22'], collect($multi->json('data.occurrences'))->pluck('date')->all());

        $overnight = $this->post($this->mahasiswaUrl('/requests'), $this->validBookingPayloadWithPdf($room, [
            'idempotency_key' => 'c7r-overnight-occurrence',
            'start_at' => '2026-06-20T20:00:00+07:00',
            'end_at' => '2026-06-21T02:00:00+07:00',
        ]))->assertCreated();
        $this->assertCount(1, $overnight->json('data.occurrences'));
        $this->assertSame('2026-06-21T02:00:00+07:00', $overnight->json('data.occurrences.0.end_at'));
    }

    public function test_multi_day_conflict_on_any_date_blocks_but_pending_demand_does_not(): void
    {
        $room = $this->classroom();
        $this->roomBooking($room, status: RoomBookingStatus::Approved, startAt: '2026-06-21 10:00:00', endAt: '2026-06-21 11:00:00');
        $this->actingAsUser($this->student());
        $payload = $this->validBookingPayloadWithPdf($room, [
            'idempotency_key' => 'c7r-conflict-any-day',
            'booking_mode' => 'consecutive_days',
            'occurrence_end_date' => '2026-06-22',
            'start_at' => '2026-06-20T09:00:00+07:00',
            'end_at' => '2026-06-22T12:00:00+07:00',
        ]);
        $this->post($this->mahasiswaUrl('/requests'), $payload)
            ->assertConflict()->assertJsonPath('code', 'booking_conflict');

        $pendingRoom = $this->classroom();
        $this->roomBooking($pendingRoom, status: RoomBookingStatus::Submitted, startAt: '2026-06-21 10:00:00', endAt: '2026-06-21 11:00:00');
        $this->post($this->mahasiswaUrl('/requests'), $this->validBookingPayloadWithPdf($pendingRoom, array_merge($payload, [
            'room_id' => $pendingRoom->id,
            'idempotency_key' => 'c7r-pending-non-blocking',
        ])))->assertCreated();
    }

    public function test_existing_booking_is_projected_as_one_compatible_occurrence(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Approved);
        $this->actingAsUser($student);

        $response = $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}"))->assertOk();
        $this->assertCount(1, $response->json('data.occurrences'));
        $this->assertDatabaseCount('room_booking_occurrences', 1);
    }

    public function test_key_issuance_is_versioned_idempotent_and_role_scoped(): void
    {
        [$booking, $occurrence] = $this->approvedOccurrence($this->classroom());
        $sarpras = $this->reviewerUser('sarpras');
        $payload = ['expected_occurrence_version' => 1, 'idempotency_key' => 'c7r-key-issue'];
        $this->actingAsUser($sarpras);
        $first = $this->postJson("/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/issue-key", $payload)
            ->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $replay = $this->postJson("/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/issue-key", $payload)
            ->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('room_booking_idempotency_records', 1);
        $this->assertDatabaseHas('room_booking_workflow_events', ['event_type' => 'key_issued']);

        [$otherBooking, $otherOccurrence] = $this->approvedOccurrence($this->classroom());
        $this->actingAsUser($this->reviewerUser('laboran', $this->bookingLaboratory('WRONG-ROLE')));
        $this->postJson("/api/tendik/peminjaman-ruangan/operations/{$otherOccurrence->public_id}/issue-key", [
            'expected_occurrence_version' => 1, 'idempotency_key' => 'c7r-key-wrong-role',
        ])->assertNotFound();
    }

    public function test_laboran_operates_only_own_lab_and_kepala_lab_is_oversight_only(): void
    {
        $lab = $this->bookingLaboratory('C7R-A');
        $otherLab = $this->bookingLaboratory('C7R-B');
        [, $ownOccurrence] = $this->approvedOccurrence($this->laboratoryRoom($lab));
        [, $otherOccurrence] = $this->approvedOccurrence($this->laboratoryRoom($otherLab));
        $laboran = $this->reviewerUser('laboran', $lab);
        $this->actingAsUser($laboran);
        $this->getJson('/api/tendik/peminjaman-ruangan/operations?tab=all')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.occurrence_ref', $ownOccurrence->public_id);
        $this->postJson("/api/tendik/peminjaman-ruangan/operations/{$otherOccurrence->public_id}/issue-key", [
            'expected_occurrence_version' => 1, 'idempotency_key' => 'c7r-cross-lab',
        ])->assertNotFound();

        $this->actingAsUser($this->reviewerUser('kepala_lab', $lab));
        $this->getJson('/api/tendik/peminjaman-ruangan/operations?tab=all')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/tendik/peminjaman-ruangan/operations/{$ownOccurrence->public_id}/issue-key", [
            'expected_occurrence_version' => 1, 'idempotency_key' => 'c7r-kalab-no-issue',
        ])->assertNotFound();
    }

    public function test_issue_before_approval_and_return_before_end_or_without_key_are_blocked(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $occurrence = app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking);
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->postJson("/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/issue-key", [
            'expected_occurrence_version' => 1, 'idempotency_key' => 'c7r-unapproved-key',
        ])->assertConflict();

        [$approved, $noKey] = $this->approvedOccurrence($this->classroom(), $student);
        $this->actingAsUser($student);
        $this->post($this->returnUrl($noKey), $this->returnPayload(1, 'c7r-no-key'))
            ->assertConflict()->assertJsonPath('code', 'key_not_issued');
        $this->issueKey($noKey);
        $this->actingAsUser($student);
        $this->post($this->returnUrl($noKey->fresh()), $this->returnPayload(2, 'c7r-before-end'))
            ->assertConflict()->assertJsonPath('code', 'occurrence_not_ready');
    }

    public function test_return_submission_is_private_idempotent_and_malformed_files_are_rejected(): void
    {
        $student = $this->student();
        [, $occurrence] = $this->approvedOccurrence($this->classroom(), $student);
        $this->issueKey($occurrence);
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:05:00', config('app.timezone')));
        $this->actingAsUser($student);
        $payload = $this->returnPayload(2, 'c7r-return-submit');
        $first = $this->post($this->returnUrl($occurrence), $payload)
            ->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $replay = $this->post(
            $this->returnUrl($occurrence),
            $this->returnPayload(2, 'c7r-return-submit'),
        )->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('room_booking_return_requests', 1);
        $returnRef = $first->json('data.booking.occurrences.0.return.return_ref');
        $this->assertNotNull($returnRef);
        $this->assertStringNotContainsString('evidence_path', $first->getContent());
        $this->get("/api/peminjaman-ruangan/returns/{$returnRef}/evidence/preview")->assertOk();
        $this->actingAsUser($this->student());
        $this->get("/api/peminjaman-ruangan/returns/{$returnRef}/evidence/preview")->assertNotFound();

        $this->actingAsUser($student);
        $bad = $this->returnPayload(3, 'c7r-invalid-image');
        $bad['evidence'] = UploadedFile::fake()->createWithContent('fake.jpg', 'not-an-image');
        $this->post($this->returnUrl($occurrence), $bad)->assertUnprocessable();
    }

    public function test_revision_resubmission_preserves_history_and_acceptance_calculates_on_time_or_late(): void
    {
        $student = $this->student();
        [, $occurrence] = $this->approvedOccurrence($this->classroom(), $student);
        $this->issueKey($occurrence);
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:05:00', config('app.timezone')));
        $this->actingAsUser($student);
        $submit = $this->post($this->returnUrl($occurrence), $this->returnPayload(2, 'c7r-return-revision-submit'))->assertOk();
        $occurrenceData = $submit->json('data.booking.occurrences.0');

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->postJson($this->decisionUrl($occurrence, 'revise'), [
            'expected_occurrence_version' => 3,
            'expected_return_version' => 1,
            'note' => 'Foto kunci perlu lebih jelas.',
            'idempotency_key' => 'c7r-return-revise',
        ])->assertOk();

        $this->actingAsUser($student);
        $resubmit = $this->post($this->returnUrl($occurrence), $this->returnPayload(4, 'c7r-return-resubmit'))->assertOk();
        $this->assertDatabaseCount('room_booking_return_requests', 2);
        $newReturn = $resubmit->json('data.booking.occurrences.0.return');

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:40:00', config('app.timezone')));
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $accepted = $this->postJson($this->decisionUrl($occurrence, 'accept'), [
            'expected_occurrence_version' => 5,
            'expected_return_version' => $newReturn['version'],
            'key_received_at' => '2026-06-20T12:20:00+07:00',
            'received_time_change_reason' => 'Dicatat dari buku serah terima.',
            'idempotency_key' => 'c7r-return-accept',
        ]);
        $this->assertSame(200, $accepted->status(), $accepted->getContent());
        $this->assertSame('returned_on_time', $accepted->json('data.booking.occurrences.0.operational_status'));
        $this->assertCount(2, $accepted->json('data.booking.occurrences.0.return_history'));
        $this->assertDatabaseHas('room_booking_workflow_events', ['event_type' => 'key_received_time_adjusted']);
    }

    public function test_overdue_and_late_states_use_key_received_time_not_staff_processing_time(): void
    {
        $student = $this->student();
        [, $occurrence] = $this->approvedOccurrence($this->classroom(), $student);
        $this->issueKey($occurrence);
        Carbon::setTestNow(Carbon::parse('2026-06-20 13:00:00', config('app.timezone')));
        $this->actingAsUser($student);
        $detail = $this->getJson($this->mahasiswaUrl("/requests/{$occurrence->booking->id}"))->assertOk();
        $this->assertSame('overdue', $detail->json('data.occurrences.0.operational_status'));
        $submit = $this->post($this->returnUrl($occurrence), $this->returnPayload(2, 'c7r-late-submit'))->assertOk();
        $returnVersion = $submit->json('data.booking.occurrences.0.return.version');
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $accepted = $this->postJson($this->decisionUrl($occurrence, 'accept'), [
            'expected_occurrence_version' => 3,
            'expected_return_version' => $returnVersion,
            'key_received_at' => '2026-06-20T12:45:00+07:00',
            'received_time_change_reason' => 'Berdasarkan log penerimaan fisik.',
            'idempotency_key' => 'c7r-late-accept',
        ])->assertOk();
        $this->assertSame('returned_late', $accepted->json('data.booking.occurrences.0.operational_status'));
    }

    public function test_stale_version_is_rejected_and_file_is_cleaned_when_transaction_fails(): void
    {
        $student = $this->student();
        [, $occurrence] = $this->approvedOccurrence($this->classroom(), $student);
        $this->issueKey($occurrence);
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:05:00', config('app.timezone')));
        $this->actingAsUser($student);
        $this->mock(RoomBookingOccurrenceEventService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('Injected event failure'));
        });
        $this->post($this->returnUrl($occurrence), $this->returnPayload(1, 'c7r-stale-return'))
            ->assertConflict()->assertJsonPath('code', 'stale_occurrence_version');

        $this->post($this->returnUrl($occurrence), $this->returnPayload(2, 'c7r-cleanup-failure'))
            ->assertInternalServerError();
        $this->assertDatabaseCount('room_booking_return_requests', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('room-booking-returns'));
    }

    /** @return array{0:\App\Models\RoomBookingRequest,1:RoomBookingOccurrence} */
    private function approvedOccurrence($room, $student = null): array
    {
        $student ??= $this->student();
        $booking = $this->roomBooking($room, $student, RoomBookingStatus::Approved);
        $occurrence = app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking);
        $occurrence->setRelation('booking', $booking->load('room'));

        return [$booking, $occurrence];
    }

    private function issueKey(RoomBookingOccurrence $occurrence): void
    {
        $role = $occurrence->booking->room->type->value === 'classroom' ? 'sarpras' : 'laboran';
        $lab = $occurrence->booking->room->owningLaboratory;
        $this->actingAsUser($this->reviewerUser($role, $lab));
        $this->postJson("/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/issue-key", [
            'expected_occurrence_version' => 1,
            'idempotency_key' => 'issue-'.substr($occurrence->public_id, 0, 20),
        ])->assertOk();
    }

    private function returnPayload(int $version, string $key): array
    {
        return [
            'expected_occurrence_version' => $version,
            'idempotency_key' => $key,
            'evidence' => UploadedFile::fake()->image('bukti-kunci.jpg', 80, 80),
        ];
    }

    private function returnUrl(RoomBookingOccurrence $occurrence): string
    {
        return $this->mahasiswaUrl("/occurrences/{$occurrence->public_id}/return");
    }

    private function decisionUrl(RoomBookingOccurrence $occurrence, string $decision): string
    {
        return "/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/return/{$decision}";
    }
}
