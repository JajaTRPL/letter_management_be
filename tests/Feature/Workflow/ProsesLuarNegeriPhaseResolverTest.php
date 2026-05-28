<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Services\ProsesLuarNegeriPhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesLuarNegeriPhaseResolverTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function resolver(): ProsesLuarNegeriPhaseResolver
    {
        return $this->app->make(ProsesLuarNegeriPhaseResolver::class);
    }

    public function test_status_to_phase_mapping_matches_canonical_pln_contract(): void
    {
        $resolver = $this->resolver();

        $expectations = [
            ProsesLuarNegeriApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ProsesLuarNegeriApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($expectations as $status => $expectedPhase) {
            $application = $this->prosesLuarNegeriApplication(null, ['status' => $status]);

            $this->assertSame(
                $expectedPhase,
                $resolver->phaseFor($application),
                "Status {$status} should map to {$expectedPhase}",
            );
        }
    }

    public function test_unavailable_statuses_return_null(): void
    {
        $resolver = $this->resolver();

        foreach ([
            ProsesLuarNegeriApplication::STATUS_DRAFT,
            ProsesLuarNegeriApplication::STATUS_REVISION,
            ProsesLuarNegeriApplication::STATUS_REJECTED,
        ] as $status) {
            $application = $this->prosesLuarNegeriApplication(null, ['status' => $status]);

            $this->assertNull(
                $resolver->phaseFor($application),
                "Status {$status} must not produce a current PLN artifact phase.",
            );
        }
    }

    public function test_phase_flags_match_pln_review_surfaces(): void
    {
        $resolver = $this->resolver();
        $application = $this->prosesLuarNegeriApplication();

        $tendik = $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertFalse($tendik['include_nomor_surat']);
        $this->assertFalse($tendik['include_prodi_paraf']);
        $this->assertFalse($tendik['include_kadep_signature']);

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
        $flags = $this->resolver()->phaseFlagsFor($this->prosesLuarNegeriApplication(), 'unknown_phase');

        $this->assertSame([
            'include_nomor_surat' => false,
            'include_prodi_paraf' => false,
            'include_kadep_signature' => false,
        ], $flags);
    }
}
