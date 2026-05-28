<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Services\LetterDocumentArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LetterDocumentArtifactServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function service(): LetterDocumentArtifactService
    {
        return $this->app->make(LetterDocumentArtifactService::class);
    }

    public function test_next_version_starts_at_one_and_increments(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $this->assertSame(1, $service->nextVersion(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
        ));

        $service->createGenerating(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'hash-1',
        );

        $this->assertSame(2, $service->nextVersion(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
        ));

        $service->createGenerating(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'hash-2',
        );

        $this->assertSame(3, $service->nextVersion(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
        ));
    }

    public function test_next_version_is_scoped_per_phase(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $service->createGenerating(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'hash-tendik',
        );
        $this->assertSame(1, $service->nextVersion(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        ));
    }

    public function test_latest_ready_returns_highest_version_ready_only(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $v1 = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 'h1');
        $service->markReady($v1, 'scholarships/previews/' . $application->id . '/tendik_1.pdf');

        $v2 = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 'h2');
        $service->markFailed($v2, 'converter timeout');

        $v3 = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 'h3');
        $service->markReady($v3, 'scholarships/previews/' . $application->id . '/tendik_3.pdf');

        $latest = $service->latestReadyArtifact(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
        );

        $this->assertNotNull($latest);
        $this->assertSame($v3->id, $latest->id);
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $latest->status);
    }

    public function test_latest_ready_returns_null_when_only_failed_or_generating_exist(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_PRODI_REVIEW, 'h1');
        $failed = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_PRODI_REVIEW, 'h2');
        $service->markFailed($failed, 'boom');

        $this->assertNull($service->latestReadyArtifact(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        ));
    }

    public function test_find_ready_by_source_hash_is_idempotency_cache(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $first = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 'shared-hash');
        $service->markReady($first, 'scholarships/previews/' . $application->id . '/tendik_1.pdf');

        // A failed retry with the SAME hash must not satisfy the cache lookup.
        $retry = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 'shared-hash');
        $service->markFailed($retry, 'transient gotenberg outage');

        $hit = $service->findReadyBySourceHash(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'shared-hash',
        );
        $this->assertNotNull($hit);
        $this->assertSame($first->id, $hit->id);

        $miss = $service->findReadyBySourceHash(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'different-hash',
        );
        $this->assertNull($miss);
    }

    public function test_mark_ready_writes_pdf_path_and_generated_at(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $artifact = $service->createGenerating(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'h',
        );

        $this->assertNull($artifact->pdf_path);
        $this->assertNull($artifact->generated_at);

        $updated = $service->markReady($artifact, 'scholarships/previews/1/tendik_1.pdf', 'scholarships/previews/1/tendik_1.docx');

        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $updated->status);
        $this->assertSame('scholarships/previews/1/tendik_1.pdf', $updated->pdf_path);
        $this->assertSame('scholarships/previews/1/tendik_1.docx', $updated->docx_path);
        $this->assertNotNull($updated->generated_at);
        $this->assertNull($updated->error_message);
    }

    public function test_mark_failed_records_error_message_and_keeps_artifact(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $artifact = $service->createGenerating(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            'h',
        );

        $updated = $service->markFailed($artifact, 'Gotenberg returned 502');
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $updated->status);
        $this->assertSame('Gotenberg returned 502', $updated->error_message);
        $this->assertDatabaseHas('letter_document_artifacts', [
            'id' => $artifact->id,
            'status' => LetterDocumentArtifact::STATUS_FAILED,
        ]);
    }

    public function test_latest_artifact_returns_highest_version_regardless_of_status(): void
    {
        $application = $this->scholarshipApplication();
        $service = $this->service();

        $v1 = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 'h1');
        $service->markReady($v1, 'p1');

        $v2 = $service->createGenerating(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW, 'h2');
        $service->markFailed($v2, 'err');

        $latest = $service->latestArtifact(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
        );

        $this->assertSame($v2->id, $latest->id);
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $latest->status);
    }
}
