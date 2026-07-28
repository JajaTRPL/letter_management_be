<?php

namespace App\Services\Analytics;

use App\Models\User;
use App\Services\AcademicRoutingService;
use App\Services\Notifications\WorkflowReviewSlaPolicyService as Sla;
use App\Support\Workflow\LetterReviewStageClock as LetterStage;
use App\Support\Workflow\RoomBookingReviewStageClock as BookingStage;

/**
 * The ONE place a review scope comes into existence.
 *
 * For the reviewer self-view this is the entire authorisation model: the request
 * carries no stage, no unit, and no scope — only a period — so there is nothing
 * for a caller to tamper with. Horizontal escalation is not "validated against"
 * here, it is structurally impossible: a Kaprodi cannot ask for another program's
 * numbers because there is no parameter in which to ask.
 *
 * Role predicates are delegated, never re-implemented: AcademicRoutingService
 * decides Prodi vs Departemen exactly as the letter workflow does, and the User
 * model's tendik predicates decide the booking stages exactly as
 * RoomBookingReviewerResolver does. If eligibility rules change there, this
 * follows automatically.
 */
final class ReviewScopeResolver
{
    public function __construct(private AcademicRoutingService $routing) {}

    /**
     * The stage this user personally reviews, or null when they review nothing.
     *
     * Null is a legitimate, expected answer — a Laboran works the room-booking
     * queue every day but never approves, and a Kepala Lab with no laboratory
     * assigned has no queue at all. Both get a hidden card, not an error.
     */
    public function forSelfView(User $user): ?ReviewScope
    {
        if ($this->routing->isProdiApprover($user)) {
            return ReviewScope::unit(
                Sla::SCOPE_LETTER,
                LetterStage::STAGE_PRODI,
                ReviewScope::DIMENSION_STUDY_PROGRAM,
                $this->intOrNull($user->study_program_id),
            );
        }

        if ($this->routing->isDepartmentApprover($user)) {
            return ReviewScope::unit(
                Sla::SCOPE_LETTER,
                LetterStage::STAGE_DEPARTEMEN,
                ReviewScope::DIMENSION_DEPARTMENT,
                $this->intOrNull($user->department_id),
            );
        }

        if ($user->isTendikPersuratan()) {
            return ReviewScope::wholeStage(
                Sla::SCOPE_LETTER,
                LetterStage::STAGE_PERSURATAN,
                ReviewScope::DIMENSION_GLOBAL,
            );
        }

        if ($user->isTendikSarpras()) {
            return ReviewScope::wholeStage(
                Sla::SCOPE_ROOM_BOOKING,
                BookingStage::STAGE_SARPRAS,
                ReviewScope::DIMENSION_GLOBAL,
            );
        }

        if ($user->isKalab()) {
            // Mirrors RoomBookingReviewerResolver: an unscoped Kepala Lab can
            // approve nothing, so there is nothing honest to report.
            $laboratoryId = $this->intOrNull($user->laboratory_id);

            return $laboratoryId === null
                ? null
                : ReviewScope::unit(
                    Sla::SCOPE_ROOM_BOOKING,
                    BookingStage::STAGE_KALAB,
                    ReviewScope::DIMENSION_LABORATORY,
                    $laboratoryId,
                );
        }

        // Laboran (queue access without approval authority), mahasiswa, and any
        // future role default to no self-view.
        return null;
    }

    /**
     * Why a self-view is unavailable, phrased for the person reading it rather
     * than for a log. Never implies they did something wrong.
     */
    public function ineligibleReason(User $user): string
    {
        if ($user->isKalab()) {
            return 'Ringkasan tersedia setelah akun Anda terhubung dengan laboratorium.';
        }
        if ($user->isLaboran()) {
            return 'Ringkasan ini tersedia untuk pemeriksa yang menyetujui pengajuan.';
        }

        return 'Ringkasan ini tersedia untuk pemeriksa pengajuan.';
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
