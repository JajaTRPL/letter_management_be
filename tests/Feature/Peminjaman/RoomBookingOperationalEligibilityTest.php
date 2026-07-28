<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\Room;
use App\Models\RoomBookingOccurrence;
use App\Models\User;
use App\Services\RoomBookingOccurrenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Operational eligibility (C7R): an occurrence is a work item ONLY while its
 * parent booking is stored-status `approved`. Every booking owns occurrence rows
 * (written at submission, backfilled for legacy rows), so the queues must filter
 * on the booking status rather than assume an occurrence implies an approval.
 */
class RoomBookingOperationalEligibilityTest extends RoomBookingApiTestCase
{
    private const TABS = ['today', 'key_handover', 'returns', 'overdue', 'all'];

    public function test_approved_legacy_occurrence_appears_in_the_valid_operational_views(): void
    {
        // A legacy row: no occurrence until the compatibility projection runs.
        $booking = $this->roomBooking(
            $this->classroom(),
            $this->student(),
            RoomBookingStatus::Approved,
            '2026-06-18 10:00:00',
            '2026-06-18 12:00:00',
        );
        $occurrence = app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking);

        $this->actingAsUser($this->reviewerUser('sarpras'));
        foreach (['today', 'key_handover', 'all'] as $tab) {
            $this->getJson($this->operationsUrl($tab))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.occurrence_ref', $occurrence->public_id)
                ->assertJsonPath('data.0.capabilities.can_issue_key', true);
        }

        // It is not overdue and has no return in flight.
        $this->getJson($this->operationsUrl('returns'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->operationsUrl('overdue'))->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('nonApprovedStatuses')]
    public function test_non_approved_occurrence_appears_in_no_operational_tab(RoomBookingStatus $status): void
    {
        $occurrence = $this->occurrenceFor($this->classroom(), $status);

        $this->actingAsUser($this->reviewerUser('sarpras'));
        foreach (self::TABS as $tab) {
            $response = $this->getJson($this->operationsUrl($tab))->assertOk();
            $this->assertSame([], $response->json('data'), "tab={$tab} leaked a {$status->value} booking");
        }

        // The row itself survives for compatibility/audit — it is only invisible
        // to the operational queues, never deleted.
        $this->assertDatabaseHas('room_booking_occurrences', ['id' => $occurrence->id]);
    }

    #[DataProvider('nonApprovedStatuses')]
    public function test_can_issue_key_is_false_for_every_non_approved_booking(RoomBookingStatus $status): void
    {
        $occurrence = $this->occurrenceFor($this->classroom(), $status);
        $sarpras = $this->reviewerUser('sarpras');
        $occurrences = app(RoomBookingOccurrenceService::class);

        $this->assertFalse($occurrences->canIssueKey($sarpras, $occurrence));
        $this->assertFalse($occurrences->canVerifyReturn($sarpras, $occurrence));
        $this->assertFalse($occurrences->canSubmitReturn($occurrence));
        $this->assertFalse($occurrences->isOperationallyActionable($occurrence));

        // No misleading next-action copy: a non-approved occurrence is never
        // reported as "scheduled" for a handover that cannot happen.
        $expected = $status === RoomBookingStatus::Cancelled ? 'cancelled' : 'not_actionable';
        $this->assertSame($expected, $occurrences->operationalStatus($occurrence));
    }

    #[DataProvider('nonApprovedStatuses')]
    public function test_direct_key_issuance_mutation_is_still_rejected(RoomBookingStatus $status): void
    {
        $occurrence = $this->occurrenceFor($this->classroom(), $status);

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->postJson($this->issueKeyUrl($occurrence), [
            'expected_occurrence_version' => 1,
            'idempotency_key' => 'c7r-eligibility-'.$status->value,
        ])->assertConflict();

        $this->assertDatabaseHas('room_booking_occurrences', [
            'id' => $occurrence->id,
            'key_issued_at' => null,
        ]);
    }

    public function test_applicant_capabilities_are_all_false_once_the_booking_is_cancelled(): void
    {
        // Approved → key issued → return pending, then the booking is cancelled.
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Approved);
        $occurrence = app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking);
        $this->issueKey($occurrence);

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:05:00', config('app.timezone')));
        $this->actingAsUser($student);
        $this->post($this->mahasiswaUrl("/occurrences/{$occurrence->public_id}/return"), [
            'expected_occurrence_version' => 2,
            'idempotency_key' => 'c7r-eligibility-return',
            'evidence' => UploadedFile::fake()->image('bukti-kunci.jpg', 80, 80),
        ])->assertOk();

        $booking->forceFill(['status' => RoomBookingStatus::Cancelled])->save();

        $detail = $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}"))->assertOk();
        $capabilities = $detail->json('data.occurrences.0.capabilities');
        $this->assertSame(
            ['can_submit_return' => false, 'can_withdraw_return' => false, 'can_resubmit_return' => false],
            $capabilities,
        );
        $this->assertSame('cancelled', $detail->json('data.occurrences.0.operational_status'));

        // The verifier loses the work item and the capability with it.
        $sarpras = $this->reviewerUser('sarpras');
        $this->assertFalse(app(RoomBookingOccurrenceService::class)
            ->canVerifyReturn($sarpras, $occurrence->fresh()->load('booking.room', 'activeReturnRequest')));
        $this->actingAsUser($sarpras);
        $this->getJson($this->operationsUrl('returns'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->operationsUrl('all'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_approved_completed_occurrence_stays_visible_as_non_actionable_history(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Approved);
        $occurrence = app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking);
        $this->issueKey($occurrence);

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:05:00', config('app.timezone')));
        $this->actingAsUser($student);
        $submit = $this->post($this->mahasiswaUrl("/occurrences/{$occurrence->public_id}/return"), [
            'expected_occurrence_version' => 2,
            'idempotency_key' => 'c7r-eligibility-history-return',
            'evidence' => UploadedFile::fake()->image('bukti-kunci.jpg', 80, 80),
        ])->assertOk();

        $sarpras = $this->reviewerUser('sarpras');
        $this->actingAsUser($sarpras);
        $this->postJson(
            "/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/return/accept",
            [
                'expected_occurrence_version' => 3,
                'expected_return_version' => $submit->json('data.booking.occurrences.0.return.version'),
                'idempotency_key' => 'c7r-eligibility-accept',
            ],
        )->assertOk();

        $response = $this->getJson($this->operationsUrl('all'))->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('returned_on_time', $response->json('data.0.operational_status'));
        // Visible history, but nothing left to act on.
        $this->assertFalse($response->json('data.0.capabilities.can_issue_key'));
        $this->assertFalse($response->json('data.0.capabilities.can_verify_return'));
        $this->assertFalse($response->json('data.0.capabilities.can_submit_return'));
        // A settled return is no longer an open verification item.
        $this->getJson($this->operationsUrl('returns'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_role_scope_still_separates_classroom_and_laboratory_queues(): void
    {
        $lab = $this->bookingLaboratory('C7R-ELIG');
        $classroomOccurrence = $this->occurrenceFor($this->classroom(), RoomBookingStatus::Approved);
        $labOccurrence = $this->occurrenceFor($this->laboratoryRoom($lab), RoomBookingStatus::Approved);
        // A rejected booking in each scope must stay invisible to both roles.
        $this->occurrenceFor($this->classroom(), RoomBookingStatus::Rejected);
        $this->occurrenceFor($this->laboratoryRoom($lab), RoomBookingStatus::Rejected);

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->getJson($this->operationsUrl('all'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.occurrence_ref', $classroomOccurrence->public_id)
            ->assertJsonPath('data.0.capabilities.can_issue_key', true);

        $this->actingAsUser($this->reviewerUser('laboran', $lab));
        $this->getJson($this->operationsUrl('all'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.occurrence_ref', $labOccurrence->public_id)
            ->assertJsonPath('data.0.capabilities.can_issue_key', true);

        // Kepala Lab keeps read-only oversight: it sees the queue, acts on nothing.
        $this->actingAsUser($this->reviewerUser('kepala_lab', $lab));
        $this->getJson($this->operationsUrl('all'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.capabilities.can_issue_key', false)
            ->assertJsonPath('data.0.capabilities.can_verify_return', false);
    }

    /** @return array<string, array{0:RoomBookingStatus}> */
    public static function nonApprovedStatuses(): array
    {
        return [
            'submitted' => [RoomBookingStatus::Submitted],
            'revision_requested' => [RoomBookingStatus::RevisionRequested],
            'rejected' => [RoomBookingStatus::Rejected],
            'cancelled' => [RoomBookingStatus::Cancelled],
        ];
    }

    private function occurrenceFor(Room $room, RoomBookingStatus $status, ?User $student = null): RoomBookingOccurrence
    {
        $booking = $this->roomBooking($room, $student ?? $this->student(), $status);
        $occurrence = app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking);
        $occurrence->setRelation('booking', $booking->load('room'));

        return $occurrence;
    }

    private function issueKey(RoomBookingOccurrence $occurrence): void
    {
        $booking = $occurrence->booking()->with('room.owningLaboratory')->first();
        $role = $booking->room->type->value === 'classroom' ? 'sarpras' : 'laboran';
        $this->actingAsUser($this->reviewerUser($role, $booking->room->owningLaboratory));
        $this->postJson($this->issueKeyUrl($occurrence), [
            'expected_occurrence_version' => 1,
            'idempotency_key' => 'c7r-eligibility-issue-'.substr($occurrence->public_id, 0, 16),
        ])->assertOk();
    }

    private function operationsUrl(string $tab): string
    {
        return "/api/tendik/peminjaman-ruangan/operations?tab={$tab}";
    }

    private function issueKeyUrl(RoomBookingOccurrence $occurrence): string
    {
        return "/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/issue-key";
    }
}
