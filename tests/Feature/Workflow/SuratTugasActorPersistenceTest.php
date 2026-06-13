<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use App\Services\SuratTugasPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Surat Tugas actor + timestamp persistence across revise/reject and the
 * approval transitions. Reason/note text and assigned_to backfill follow the
 * canonical letters exactly.
 */
class SuratTugasActorPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-25 09:10:20'));
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_tendik_revise_persists_actor_note_and_backfills_assignment(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => null,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/revise", ['note' => 'Lengkapi proposal.'])
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_REVISION, $fresh->status);
        $this->assertSame('Lengkapi proposal.', $fresh->revision_note);
        $this->assertNull($fresh->rejection_reason);
        $this->assertSame($tendik->id, $fresh->revised_by);
        $this->assertNotNull($fresh->revised_at);
        $this->assertSame($tendik->id, $fresh->assigned_to);
    }

    public function test_tendik_reject_persists_actor_and_reason(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/reject", ['reason' => 'Tidak memenuhi syarat.'])
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Tidak memenuhi syarat.', $fresh->rejection_reason);
        $this->assertNull($fresh->revision_note);
        $this->assertSame($tendik->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_revise_and_reject_require_text(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/revise", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $application->fresh()->status);
    }

    public function test_akademik_prodi_approve_persists_kaprodi_actor_timestamp(): void
    {
        $this->mockPreviewAlwaysReady();
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat_tugas' => 'ST/ACTOR/001',
            'tendik_approved_at' => Carbon::parse('2026-05-24 08:00:00'),
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve")
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by);
        $this->assertNotNull($fresh->kaprodi_approved_at);
        // Tendik's number survives the Prodi transition.
        $this->assertSame('ST/ACTOR/001', $fresh->nomor_surat_tugas);
    }

    public function test_akademik_departemen_approve_persists_kadep_actor_timestamp(): void
    {
        $this->mockPreviewAlwaysReady();
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat_tugas' => 'ST/ACTOR/002',
            'tendik_approved_at' => Carbon::parse('2026-05-24 08:00:00'),
            'kaprodi_approved_at' => Carbon::parse('2026-05-24 09:00:00'),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve")
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame($kadep->id, $fresh->kadep_approved_by);
        $this->assertNotNull($fresh->kadep_approved_at);
    }

    private function mockPreviewAlwaysReady(): void
    {
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn ($application, string $phase) => LetterDocumentArtifact::make([
                'letter_type' => SuratTugasApplication::LETTER_TYPE,
                'application_id' => $application->getKey(),
                'phase' => $phase,
                'status' => LetterDocumentArtifact::STATUS_READY,
            ]));
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);
    }
}
