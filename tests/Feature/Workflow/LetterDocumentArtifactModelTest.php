<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LetterDocumentArtifactModelTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_can_persist_artifact_with_required_fields(): void
    {
        $application = $this->scholarshipApplication();

        $artifact = LetterDocumentArtifact::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'version' => 1,
            'docx_path' => null,
            'pdf_path' => null,
            'source_hash' => str_repeat('a', 64),
            'status' => LetterDocumentArtifact::STATUS_GENERATING,
            'error_message' => null,
            'generated_by' => null,
            'generated_at' => null,
        ]);

        $this->assertNotNull($artifact->id);
        $this->assertSame(1, $artifact->version);
        $this->assertSame(LetterDocumentArtifact::STATUS_GENERATING, $artifact->status);
        $this->assertSame(LetterDocumentArtifact::PHASE_TENDIK_REVIEW, $artifact->phase);
    }

    public function test_phase_and_status_constants_are_canonical(): void
    {
        $this->assertSame(
            ['tendik_review', 'prodi_review', 'departemen_review', 'mahasiswa_review'],
            LetterDocumentArtifact::PHASES,
        );
        $this->assertSame(['generating', 'ready', 'failed'], LetterDocumentArtifact::STATUSES);
    }

    public function test_ready_scope_filters_to_status_ready_only(): void
    {
        $application = $this->scholarshipApplication();
        $this->makeArtifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 1, LetterDocumentArtifact::STATUS_READY);
        $this->makeArtifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 2, LetterDocumentArtifact::STATUS_FAILED);
        $this->makeArtifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 3, LetterDocumentArtifact::STATUS_GENERATING);

        $ready = LetterDocumentArtifact::query()
            ->forApplication(ScholarshipApplication::LETTER_TYPE, $application->id)
            ->forPhase(LetterDocumentArtifact::PHASE_TENDIK_REVIEW)
            ->ready()
            ->get();

        $this->assertCount(1, $ready);
        $this->assertSame(1, $ready->first()->version);
    }

    public function test_scopes_filter_by_application_and_phase(): void
    {
        $a = $this->scholarshipApplication();
        $b = $this->scholarshipApplication();

        $this->makeArtifact($a, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 1, LetterDocumentArtifact::STATUS_READY);
        $this->makeArtifact($a, LetterDocumentArtifact::PHASE_PRODI_REVIEW, 1, LetterDocumentArtifact::STATUS_READY);
        $this->makeArtifact($b, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 1, LetterDocumentArtifact::STATUS_READY);

        $aTendik = LetterDocumentArtifact::query()
            ->forApplication(ScholarshipApplication::LETTER_TYPE, $a->id)
            ->forPhase(LetterDocumentArtifact::PHASE_TENDIK_REVIEW)
            ->get();
        $this->assertCount(1, $aTendik);

        $allForA = LetterDocumentArtifact::query()
            ->forApplication(ScholarshipApplication::LETTER_TYPE, $a->id)
            ->get();
        $this->assertCount(2, $allForA);
    }

    private function makeArtifact(ScholarshipApplication $application, string $phase, int $version, string $status): LetterDocumentArtifact
    {
        return LetterDocumentArtifact::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => $version,
            'docx_path' => null,
            'pdf_path' => $status === LetterDocumentArtifact::STATUS_READY ? "scholarships/previews/{$application->id}/{$phase}_{$version}.pdf" : null,
            'source_hash' => str_pad((string) $version, 64, 'a'),
            'status' => $status,
            'error_message' => null,
            'generated_by' => null,
            'generated_at' => $status === LetterDocumentArtifact::STATUS_READY ? now() : null,
        ]);
    }
}
