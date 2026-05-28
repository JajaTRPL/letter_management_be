<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Services\BeasiswaPhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeasiswaPhaseResolverTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function resolver(): BeasiswaPhaseResolver
    {
        return $this->app->make(BeasiswaPhaseResolver::class);
    }

    public function test_status_to_phase_mapping_matches_canonical_contract(): void
    {
        $resolver = $this->resolver();

        $expectations = [
            ScholarshipApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($expectations as $status => $expectedPhase) {
            $application = $this->scholarshipApplication(null, ['status' => $status]);
            $this->assertSame(
                $expectedPhase,
                $resolver->phaseFor($application),
                "Status {$status} should map to {$expectedPhase}",
            );
        }
    }

    public function test_revision_and_rejected_return_null_so_caller_can_fallback(): void
    {
        $resolver = $this->resolver();

        foreach ([ScholarshipApplication::STATUS_REVISION, ScholarshipApplication::STATUS_REJECTED, ScholarshipApplication::STATUS_DRAFT] as $status) {
            $application = $this->scholarshipApplication(null, ['status' => $status]);
            $this->assertNull(
                $resolver->phaseFor($application),
                "Status {$status} must not produce a current-phase override; caller falls back to latest ready artifact",
            );
        }
    }

    public function test_phase_flags_for_tendik_review_omit_paraf_and_signature(): void
    {
        $resolver = $this->resolver();
        $application = $this->scholarshipApplication(null, ['nomor_surat' => null]);

        $flags = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertFalse($flags['include_nomor_surat']);
        $this->assertFalse($flags['include_prodi_paraf']);
        $this->assertFalse($flags['include_kadep_signature']);
    }

    public function test_phase_flags_for_tendik_review_include_nomor_surat_when_already_set(): void
    {
        $resolver = $this->resolver();
        $application = $this->scholarshipApplication(null, ['nomor_surat' => 'NOMOR/123/2026']);

        $flags = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertTrue($flags['include_nomor_surat']);
        $this->assertFalse($flags['include_prodi_paraf']);
        $this->assertFalse($flags['include_kadep_signature']);
    }

    public function test_phase_flags_for_prodi_include_nomor_surat_only(): void
    {
        $resolver = $this->resolver();
        $application = $this->scholarshipApplication();

        $flags = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->assertTrue($flags['include_nomor_surat']);
        $this->assertFalse($flags['include_prodi_paraf']);
        $this->assertFalse($flags['include_kadep_signature']);
    }

    public function test_phase_flags_for_departemen_include_paraf_but_not_signature(): void
    {
        $resolver = $this->resolver();
        $application = $this->scholarshipApplication();

        $flags = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);
        $this->assertTrue($flags['include_nomor_surat']);
        $this->assertTrue($flags['include_prodi_paraf']);
        $this->assertFalse($flags['include_kadep_signature']);
    }

    public function test_phase_flags_for_mahasiswa_include_all_three(): void
    {
        $resolver = $this->resolver();
        $application = $this->scholarshipApplication();

        $flags = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        $this->assertTrue($flags['include_nomor_surat']);
        $this->assertTrue($flags['include_prodi_paraf']);
        $this->assertTrue($flags['include_kadep_signature']);
    }
}
