<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\RoomBookingStatus;
use App\Models\AppNotification;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\User;
use App\Models\WorkflowReviewSlaPolicy;
use App\Services\Notifications\RoomBookingReviewSlaScanner;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
use App\Services\RoomBookingTransitionService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * C10 review-SLA governance: disabled-by-default safety, phase timing, warning→
 * overdue supersession, SuperAdmin escalation, downtime catch-up, state-gating +
 * resolution on transition, policy validation, and the SuperAdmin API.
 */
class RoomBookingReviewSlaTest extends RoomBookingApiTestCase
{
    private const SCOPE = WorkflowReviewSlaPolicyService::SCOPE_ROOM_BOOKING;

    private RoomBookingReviewSlaScanner $scanner;

    private Carbon $submittedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = app(RoomBookingReviewSlaScanner::class);
        $this->submittedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
    }

    // ── scanner: safety + phases ────────────────────────────────────────────

    public function test_disabled_policy_emits_nothing(): void
    {
        $this->reviewerUser('sarpras');
        $this->submittedBooking($this->student());

        $result = $this->scanner->scan($this->at(10 * 24 * 60)); // 10 days later
        $this->assertFalse($result['enabled']);
        $this->assertSame(0, AppNotification::count());
    }

    public function test_warning_phase_reminds_the_reviewer_and_is_idempotent(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $this->enablePolicy();
        $this->submittedBooking($this->student());

        $this->scanner->scan($this->at(90)); // 90 min: inside [60, 120) warning band
        $this->scanner->scan($this->at(95)); // re-run must not duplicate

        $warnings = AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-warning:%')->get();
        $this->assertCount(1, $warnings);
        $this->assertSame(NotificationCategory::Reminder, $warnings->first()->category);
    }

    public function test_overdue_supersedes_the_warning_for_the_reviewer(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $this->enablePolicy();
        $this->submittedBooking($this->student());

        $this->scanner->scan($this->at(90));  // warning
        $this->scanner->scan($this->at(150)); // 150 min: overdue band [120, 180)

        $overdue = AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-overdue:%')->first();
        $this->assertNotNull($overdue);
        $this->assertSame(NotificationCategory::ActionRequired, $overdue->category);
        $this->assertNull($overdue->resolved_at);

        // The soft warning was superseded — the reviewer sees one live obligation.
        $warning = AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-warning:%')->first();
        $this->assertNotNull($warning->superseded_by_id);
    }

    public function test_escalation_reaches_superadmin_while_reviewer_keeps_the_overdue(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $admin = $this->superAdmin();
        $this->enablePolicy();
        $this->submittedBooking($this->student());

        $this->scanner->scan($this->at(200)); // >= 180 escalation

        $this->assertSame(1, AppNotification::where('recipient_user_id', $admin->id)
            ->where('dedup_key', 'like', 'review-sla-escalation:%')
            ->where('category', NotificationCategory::System->value)->count());
        $this->assertSame(1, AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-overdue:%')->count());
    }

    public function test_downtime_catch_up_emits_current_phase_not_a_backlog_of_warnings(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $this->superAdmin();
        $this->enablePolicy();
        $this->submittedBooking($this->student());

        // Scanner was down through the warning window; first run is deep overdue.
        $this->scanner->scan($this->at(500));

        $this->assertSame(0, AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-warning:%')->count());
        $this->assertSame(1, AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-overdue:%')->count());
    }

    public function test_review_sla_notifications_are_resolved_when_the_booking_leaves_review(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $booking = $this->submittedBooking($this->student());
        $this->enablePolicy();

        $this->scanner->scan($this->at(150)); // overdue exists, unresolved
        $this->assertNull(AppNotification::where('dedup_key', 'like', 'review-sla-overdue:%')
            ->first()?->resolved_at);

        // A decision retires the review obligation — its SLA notification resolves.
        app(RoomBookingTransitionService::class)->reject($booking->fresh(), $sarpras, 'Ruang tidak tersedia.');

        $this->assertNotNull(AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-overdue:%')->first()->resolved_at);
    }

    public function test_only_submitted_bookings_are_scanned(): void
    {
        $this->reviewerUser('sarpras');
        $this->enablePolicy();
        $this->submittedBooking($this->student(), RoomBookingStatus::Approved);

        $result = $this->scanner->scan($this->at(500));
        $this->assertSame(0, $result['scanned']);
        $this->assertSame(0, AppNotification::count());
    }

    // ── policy service: defaults + validation ───────────────────────────────

    public function test_policy_defaults_are_disabled(): void
    {
        $policy = app(WorkflowReviewSlaPolicyService::class)->current(self::SCOPE);
        $this->assertFalse($policy['enabled']);
    }

    public function test_update_persists_and_audits_and_rejects_bad_ordering(): void
    {
        $service = app(WorkflowReviewSlaPolicyService::class);
        $admin = $this->superAdmin();

        $model = $service->update(self::SCOPE, [
            'enabled' => true, 'warning_minutes' => 60, 'overdue_minutes' => 120, 'escalation_minutes' => 180,
        ], $admin);
        $this->assertTrue($model->enabled);
        $this->assertSame($admin->id, $model->updated_by);
        $this->assertNotNull($model->enabled_at);
        $this->assertSame($admin->id, $model->enabled_updated_by);

        $this->expectException(InvalidArgumentException::class);
        $service->update(self::SCOPE, [
            'warning_minutes' => 200, 'overdue_minutes' => 100, 'escalation_minutes' => 300,
        ], $admin);
    }

    // ── SuperAdmin API: authorization + validation + audit ──────────────────

    public function test_api_requires_superadmin(): void
    {
        $this->actingAsUser($this->student());
        $this->getJson($this->slaUrl())->assertForbidden();
    }

    public function test_api_returns_human_language_policy_and_persists_valid_update(): void
    {
        $admin = $this->superAdmin();
        $this->actingAsUser($admin);

        $this->getJson($this->slaUrl())
            ->assertOk()
            ->assertJsonPath('data.scope_label', 'Peminjaman Ruangan')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.explanation.subject', 'Permohonan yang belum diperiksa');

        $this->putJson($this->slaUrl(), [
            'enabled' => true, 'warning_minutes' => 1440, 'overdue_minutes' => 2880, 'escalation_minutes' => 4320,
        ])->assertOk()->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('workflow_review_sla_policies', [
            'scope' => self::SCOPE, 'enabled' => true, 'updated_by' => $admin->id,
        ]);
    }

    public function test_api_rejects_invalid_ordering_and_unknown_scope(): void
    {
        $this->actingAsUser($this->superAdmin());

        $this->putJson($this->slaUrl(), [
            'enabled' => true, 'warning_minutes' => 4320, 'overdue_minutes' => 2880, 'escalation_minutes' => 1440,
        ])->assertStatus(422);

        $this->getJson('/api/super-admin/review-sla/nonexistent_scope')->assertNotFound();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function slaUrl(): string
    {
        return '/api/super-admin/review-sla/'.self::SCOPE;
    }

    private function at(int $minutesAfterSubmission): Carbon
    {
        return $this->submittedAt->copy()->addMinutes($minutesAfterSubmission);
    }

    private function enablePolicy(int $warning = 60, int $overdue = 120, int $escalation = 180): void
    {
        WorkflowReviewSlaPolicy::create([
            'scope' => self::SCOPE,
            'enabled' => true,
            'warning_minutes' => $warning,
            'overdue_minutes' => $overdue,
            'escalation_minutes' => $escalation,
        ]);
    }

    private function submittedBooking(
        User $requester,
        RoomBookingStatus $status = RoomBookingStatus::Submitted,
        ?Room $room = null,
    ): RoomBookingRequest {
        // Freeze "now" at submission so the booking's created_at (the review clock
        // fallback) is deterministic relative to the scan times.
        Carbon::setTestNow($this->submittedAt);
        $booking = $this->roomBooking(
            $room ?? $this->classroom(),
            $requester,
            $status,
            '2026-08-20 10:00:00',
            '2026-08-20 12:00:00',
        );
        Carbon::setTestNow();

        return $booking;
    }
}
