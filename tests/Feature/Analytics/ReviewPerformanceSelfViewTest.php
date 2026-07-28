<?php

namespace Tests\Feature\Analytics;

use App\Models\Laboratory;
use App\Models\User;
use App\Support\Workflow\LetterReviewStageClock as LetterStage;
use App\Support\Workflow\RoomBookingReviewStageClock as BookingStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * The reviewer self-view: what each role sees, and — more importantly — what no
 * role can reach.
 *
 * The crafted-parameter test is the one that matters. Because the endpoint takes
 * only `period`, a reviewer who appends someone else's scope to the query string
 * gets their OWN figures back, silently and correctly.
 */
class ReviewPerformanceSelfViewTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const AKADEMIK_URL = '/api/akademik/review-performance/me';

    private const TENDIK_URL = '/api/tendik/review-performance/me';

    public function test_a_kaprodi_sees_the_prodi_stage_scoped_to_their_own_program(): void
    {
        $program = $this->defaultStudyProgram();
        Sanctum::actingAs($this->akademik('kaprodi', ['study_program_id' => $program->id]));

        $data = $this->getJson(self::AKADEMIK_URL)->assertOk()->json('data');

        $this->assertTrue($data['eligible']);
        $this->assertSame(LetterStage::STAGE_PRODI, $data['stage']);
        $this->assertSame($program->code.' — '.$program->name, $data['unit_label']);
        $this->assertStringContainsString('bukan penilaian per orang', $data['note']);
    }

    public function test_a_crafted_scope_parameter_is_ignored_entirely(): void
    {
        $mine = $this->defaultStudyProgram();
        $theirs = $this->studyProgram(null, ['code' => 'XTI', 'name' => 'Program Studi Lain']);
        Sanctum::actingAs($this->akademik('kaprodi', ['study_program_id' => $mine->id]));

        $data = $this->getJson(
            self::AKADEMIK_URL
            ."?stage=departemen&scope=room_booking&unit_id={$theirs->id}"
            ."&study_program_id={$theirs->id}&department_id={$theirs->department_id}"
        )->assertOk()->json('data');

        // Every injected parameter is ignored: the scope came from the session.
        $this->assertSame(LetterStage::STAGE_PRODI, $data['stage']);
        $this->assertSame('letter', $data['scope']);
        $this->assertSame($mine->code.' — '.$mine->name, $data['unit_label']);
        $this->assertStringNotContainsString('Program Studi Lain', json_encode($data));
    }

    public function test_a_kadep_sees_the_departemen_stage_scoped_to_their_department(): void
    {
        Sanctum::actingAs($this->akademik('kadep'));

        $data = $this->getJson(self::AKADEMIK_URL)->assertOk()->json('data');

        $this->assertSame(LetterStage::STAGE_DEPARTEMEN, $data['stage']);
        $this->assertSame('departemen', $data['metric'] ? 'departemen' : '');
        $this->assertNotSame('Seluruh unit', $data['unit_label']);
    }

    public function test_tendik_persuratan_sees_the_letter_intake_stage(): void
    {
        Sanctum::actingAs($this->tendikPersuratan());

        $data = $this->getJson(self::TENDIK_URL)->assertOk()->json('data');

        $this->assertSame(LetterStage::STAGE_PERSURATAN, $data['stage']);
        $this->assertSame('letter', $data['scope']);
    }

    public function test_sarpras_sees_the_classroom_booking_stage(): void
    {
        Sanctum::actingAs($this->tendikSarpras());

        $data = $this->getJson(self::TENDIK_URL)->assertOk()->json('data');

        $this->assertSame(BookingStage::STAGE_SARPRAS, $data['stage']);
        $this->assertSame('room_booking', $data['scope']);
    }

    public function test_a_kepala_lab_sees_only_their_own_laboratory(): void
    {
        $lab = $this->laboratory('LAB-01', 'Lab Jaringan');
        $this->laboratory('LAB-02', 'Lab Kendali');
        Sanctum::actingAs($this->kalab($lab));

        $data = $this->getJson(self::TENDIK_URL)->assertOk()->json('data');

        $this->assertSame(BookingStage::STAGE_KALAB, $data['stage']);
        $this->assertSame('LAB-01 — Lab Jaringan', $data['unit_label']);
        $this->assertStringNotContainsString('Lab Kendali', json_encode($data));
    }

    public function test_a_laboran_is_ineligible_because_they_do_not_approve(): void
    {
        // A Laboran works the booking queue daily but cannot approve, so there is
        // no review duration that belongs to them.
        Sanctum::actingAs($this->activeUser([
            'role' => 'tendik',
            'tendik_role' => 'laboran',
            'laboratory_id' => $this->laboratory('LAB-09', 'Lab Uji')->id,
        ]));

        $data = $this->getJson(self::TENDIK_URL)->assertOk()->json('data');

        $this->assertFalse($data['eligible']);
        $this->assertArrayNotHasKey('metric', $data);
        $this->assertStringContainsString('pemeriksa', $data['reason_label']);
    }

    public function test_an_unscoped_kepala_lab_resolves_to_no_scope(): void
    {
        // Asserted at the resolver, not over HTTP: the profile-completion
        // middleware already blocks a lab-less Kalab from every tendik endpoint,
        // so this is defence in depth for a path the router never reaches.
        $resolver = app(\App\Services\Analytics\ReviewScopeResolver::class);

        $this->assertNull($resolver->forSelfView($this->kalab(null)));
        $this->assertStringContainsString('laboratorium', $resolver->ineligibleReason($this->kalab(null)));
    }

    public function test_a_student_cannot_reach_either_self_view(): void
    {
        [$student] = $this->completeMahasiswa();
        Sanctum::actingAs($student);

        $this->getJson(self::TENDIK_URL)->assertForbidden();
        $this->getJson(self::AKADEMIK_URL)->assertForbidden();
    }

    public function test_no_reviewer_name_appears_anywhere_in_the_payload(): void
    {
        $kaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $this->defaultStudyProgram()->id,
            'name' => 'Dr. Nama Yang Sangat Khas',
        ]);
        Sanctum::actingAs($kaprodi);

        $body = $this->getJson(self::AKADEMIK_URL)->assertOk()->getContent();

        // Not even the reader's own name: the card describes a stage, not a person.
        $this->assertStringNotContainsString('Dr. Nama Yang Sangat Khas', $body);
        foreach (['reviewer', 'approved_by', 'user_id', 'nip'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_the_payload_offers_an_action_rather_than_a_ranking(): void
    {
        Sanctum::actingAs($this->tendikPersuratan());

        $data = $this->getJson(self::TENDIK_URL)->assertOk()->json('data');

        $this->assertArrayHasKey('waiting_now', $data);
        $this->assertSame('Lihat Antrean', $data['waiting_now']['action_label']);
        // Nothing comparative is even representable in this response.
        $this->assertArrayNotHasKey('rank', $data);
        $this->assertArrayNotHasKey('peers', $data);
        $this->assertArrayNotHasKey('units', $data);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function laboratory(string $code, string $name): Laboratory
    {
        return Laboratory::create([
            'code' => $code,
            'name' => $name,
            'department_id' => $this->defaultStudyProgram()->department_id,
        ]);
    }

    private function kalab(?Laboratory $laboratory): User
    {
        return $this->activeUser([
            'role' => 'tendik',
            'tendik_role' => 'kepala_lab',
            'laboratory_id' => $laboratory?->id,
        ]);
    }
}
