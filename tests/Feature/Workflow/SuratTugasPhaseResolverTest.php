<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use App\Services\SuratTugasPhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuratTugasPhaseResolverTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function resolver(): SuratTugasPhaseResolver
    {
        return $this->app->make(SuratTugasPhaseResolver::class);
    }

    public function test_status_maps_to_expected_phase(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            $resolver->phaseFor($this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_SUBMITTED])),
        );
        $this->assertSame(
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            $resolver->phaseFor($this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK])),
        );
        $this->assertSame(
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            $resolver->phaseFor($this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI])),
        );
        $this->assertSame(
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            $resolver->phaseFor($this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW])),
        );
        $this->assertSame(
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            $resolver->phaseFor($this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_COMPLETED])),
        );
        $this->assertNull(
            $resolver->phaseFor($this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_DRAFT])),
        );
    }

    public function test_phase_flags_gate_tugas_section_progressively(): void
    {
        $application = $this->suratTugasApplication();
        $resolver = $this->resolver();

        $this->assertSame([
            'include_nomor_tugas' => false,
            'include_paraf_tugas' => false,
            'include_kadep_ttd_tugas' => false,
        ], $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW));

        $this->assertSame([
            'include_nomor_tugas' => true,
            'include_paraf_tugas' => false,
            'include_kadep_ttd_tugas' => false,
        ], $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW));

        $this->assertSame([
            'include_nomor_tugas' => true,
            'include_paraf_tugas' => true,
            'include_kadep_ttd_tugas' => false,
        ], $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW));

        $this->assertSame([
            'include_nomor_tugas' => true,
            'include_paraf_tugas' => true,
            'include_kadep_ttd_tugas' => true,
        ], $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW));
    }

    public function test_unknown_phase_returns_all_flags_false(): void
    {
        $this->assertSame([
            'include_nomor_tugas' => false,
            'include_paraf_tugas' => false,
            'include_kadep_ttd_tugas' => false,
        ], $this->resolver()->phaseFlagsFor($this->suratTugasApplication(), 'unknown_phase'));
    }
}
