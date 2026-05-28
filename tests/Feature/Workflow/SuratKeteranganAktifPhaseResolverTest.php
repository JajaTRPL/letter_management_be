<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\SuratKeteranganAktifPhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuratKeteranganAktifPhaseResolverTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function resolver(): SuratKeteranganAktifPhaseResolver
    {
        return $this->app->make(SuratKeteranganAktifPhaseResolver::class);
    }

    public function test_status_to_phase_mapping_matches_canonical_ska_contract(): void
    {
        $resolver = $this->resolver();

        $expectations = [
            SuratKeteranganAktifApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            SuratKeteranganAktifApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($expectations as $status => $expectedPhase) {
            $application = $this->aktifApplication(null, ['status' => $status]);

            $this->assertSame(
                $expectedPhase,
                $resolver->phaseFor($application),
                "Status {$status} should map to {$expectedPhase}",
            );
        }
    }

    public function test_unavailable_statuses_return_null_for_later_endpoint_fallback(): void
    {
        $resolver = $this->resolver();

        foreach ([
            SuratKeteranganAktifApplication::STATUS_DRAFT,
            SuratKeteranganAktifApplication::STATUS_REVISION,
            SuratKeteranganAktifApplication::STATUS_REJECTED,
        ] as $status) {
            $application = $this->aktifApplication(null, ['status' => $status]);

            $this->assertNull(
                $resolver->phaseFor($application),
                "Status {$status} must not produce a current SKA artifact phase.",
            );
        }
    }

    public function test_tendik_review_flags_exclude_number_unless_already_present(): void
    {
        $resolver = $this->resolver();

        $withoutNumber = $this->aktifApplication(null, ['nomor_surat' => null]);
        $flagsWithoutNumber = $resolver->phaseFlagsFor($withoutNumber, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertFalse($flagsWithoutNumber['include_nomor_surat']);
        $this->assertFalse($flagsWithoutNumber['include_prodi_paraf']);
        $this->assertFalse($flagsWithoutNumber['include_kadep_signature']);

        $withNumber = $this->aktifApplication(null, ['nomor_surat' => 'AKT/001/2026']);
        $flagsWithNumber = $resolver->phaseFlagsFor($withNumber, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertTrue($flagsWithNumber['include_nomor_surat']);
        $this->assertFalse($flagsWithNumber['include_prodi_paraf']);
        $this->assertFalse($flagsWithNumber['include_kadep_signature']);
    }

    public function test_phase_flags_match_ska_review_surfaces(): void
    {
        $resolver = $this->resolver();
        $application = $this->aktifApplication();

        $prodi = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->assertTrue($prodi['include_nomor_surat']);
        $this->assertFalse($prodi['include_prodi_paraf']);
        $this->assertFalse($prodi['include_kadep_signature']);

        $departemen = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);
        $this->assertTrue($departemen['include_nomor_surat']);
        $this->assertTrue($departemen['include_prodi_paraf']);
        $this->assertFalse($departemen['include_kadep_signature']);

        $mahasiswa = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        $this->assertTrue($mahasiswa['include_nomor_surat']);
        $this->assertTrue($mahasiswa['include_prodi_paraf']);
        $this->assertTrue($mahasiswa['include_kadep_signature']);
    }

    public function test_unknown_phase_returns_all_flags_false(): void
    {
        $flags = $this->resolver()->phaseFlagsFor($this->aktifApplication(), 'unknown_phase');

        $this->assertSame([
            'include_nomor_surat' => false,
            'include_prodi_paraf' => false,
            'include_kadep_signature' => false,
        ], $flags);
    }
}
