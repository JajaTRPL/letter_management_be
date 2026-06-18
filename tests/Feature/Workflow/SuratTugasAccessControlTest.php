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
 * Role + scope access control for the Surat Tugas workflow endpoints. Reuses
 * the shared assignment service (Tendik) and academic routing (Akademik scope).
 */
class SuratTugasAccessControlTest extends TestCase
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

    public function test_owner_can_view_detail_but_other_mahasiswa_cannot(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$other] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($owner, ['status' => SuratTugasApplication::STATUS_SUBMITTED]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/mahasiswa/surat-tugas/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.id', $application->id);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/mahasiswa/surat-tugas/{$application->id}")
            ->assertForbidden();
    }

    public function test_assigned_tendik_can_view_reviewer_detail_but_unassigned_and_sarpras_cannot(): void
    {
        $assigned = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $unassigned = $this->tendikPersuratan([\App\Models\ScholarshipApplication::LETTER_TYPE]);
        $sarpras = $this->tendikSarpras();
        $application = $this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_SUBMITTED]);

        $this->actingAs($assigned, 'sanctum')
            ->getJson("/api/tendik/surat-tugas/{$application->id}")
            ->assertOk();

        $this->actingAs($unassigned, 'sanctum')
            ->getJson("/api/tendik/surat-tugas/{$application->id}")
            ->assertForbidden();

        $this->actingAs($sarpras, 'sanctum')
            ->getJson("/api/tendik/surat-tugas/{$application->id}")
            ->assertForbidden();
    }

    public function test_unassigned_tendik_cannot_approve(): void
    {
        $unassigned = $this->tendikPersuratan([\App\Models\ScholarshipApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_SUBMITTED]);

        $this->mockPreviewNever();

        $this->actingAs($unassigned, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/approve", ['nomor_surat_tugas' => 'ST/X'])
            ->assertForbidden();

        $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $application->fresh()->status);
        $this->assertNull($application->fresh()->nomor_surat_tugas);
    }

    public function test_akademik_detail_scope_is_enforced(): void
    {
        $department = $this->department(['name' => 'DTEDI']);
        $prodi = $this->studyProgram($department, ['name' => 'TRPL']);
        $otherProdi = $this->studyProgram($this->department(['name' => 'Other']), ['name' => 'Other Program']);

        [$inScopeStudent] = $this->completeMahasiswa([], [], $prodi);
        [$outScopeStudent] = $this->completeMahasiswa([], [], $otherProdi);

        $inScope = $this->suratTugasApplication($inScopeStudent, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $outScope = $this->suratTugasApplication($outScopeStudent, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $prodi->id]);

        $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-tugas/{$inScope->id}")
            ->assertOk();

        $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-tugas/{$outScope->id}")
            ->assertForbidden();
    }

    public function test_mahasiswa_cannot_reach_tendik_or_akademik_routes(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, ['status' => SuratTugasApplication::STATUS_SUBMITTED]);

        // role middleware blocks cross-role prefixes.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/tendik/surat-tugas/{$application->id}")
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/akademik/surat-tugas/{$application->id}")
            ->assertForbidden();
    }

    private function mockPreviewNever(): void
    {
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);
        // Silence unused import analyzer for LetterDocumentArtifact in strict setups.
        class_exists(LetterDocumentArtifact::class);
    }
}
