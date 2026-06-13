<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use App\Services\SuratPengantarMagangPhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuratPengantarMagangPhaseResolverTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function resolver(): SuratPengantarMagangPhaseResolver
    {
        return $this->app->make(SuratPengantarMagangPhaseResolver::class);
    }

    public function test_status_to_phase_mapping_matches_magang_contract(): void
    {
        $expectations = [
            SuratPengantarMagangApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            SuratPengantarMagangApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($expectations as $status => $phase) {
            $this->assertSame(
                $phase,
                $this->resolver()->phaseFor($this->magangApplication(null, ['status' => $status])),
                "Status {$status} should map to {$phase}.",
            );
        }
    }

    public function test_draft_revision_and_rejected_have_no_current_phase(): void
    {
        foreach ([
            SuratPengantarMagangApplication::STATUS_DRAFT,
            SuratPengantarMagangApplication::STATUS_REVISION,
            SuratPengantarMagangApplication::STATUS_REJECTED,
        ] as $status) {
            $this->assertNull($this->resolver()->phaseFor($this->magangApplication(null, ['status' => $status])));
        }
    }

    public function test_phase_flags_gate_pengantar_section_only_after_tugas_split(): void
    {
        // S1 (Magang standalone): all tugas-only flags are permanently false. The
        // Magang artifact renders the Pengantar section only; paraf/Kadep-TTD turn
        // on progressively for the Pengantar section across phases.
        $application = $this->magangApplication();

        $this->assertSame($this->allFalseFlags(), $this->resolver()->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW));
        $this->assertSame([
            'include_nomor_pengantar' => true,
            'include_paraf_pengantar' => false,
            'include_kadep_ttd_pengantar' => false,
        ], $this->resolver()->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW));
        $this->assertSame([
            'include_nomor_pengantar' => true,
            'include_paraf_pengantar' => true,
            'include_kadep_ttd_pengantar' => false,
        ], $this->resolver()->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW));
        $this->assertSame([
            'include_nomor_pengantar' => true,
            'include_paraf_pengantar' => true,
            'include_kadep_ttd_pengantar' => true,
        ], $this->resolver()->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW));
    }

    public function test_unknown_phase_returns_all_flags_false(): void
    {
        $this->assertSame($this->allFalseFlags(), $this->resolver()->phaseFlagsFor($this->magangApplication(), 'unknown_phase'));
    }

    private function allFalseFlags(): array
    {
        return [
            'include_nomor_pengantar' => false,
            'include_paraf_pengantar' => false,
            'include_kadep_ttd_pengantar' => false,
        ];
    }
}
