<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\LetterDocumentArtifact;
use App\Models\LetterRetentionAction;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Services\LetterRetentionOptions;
use App\Services\LetterRetentionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LetterRetentionServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-06-10 09:00:00');
        Carbon::setTestNow($this->now);
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('archive');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_non_completed_and_completed_without_completed_at_never_clean(): void
    {
        $submitted = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'completed_at' => null,
        ]);
        $submittedAttachment = $this->supportingDocument($submitted);

        $missingCompletedAt = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => null,
        ]);
        $missingCompletedAtAttachment = $this->supportingDocument($missingCompletedAt);

        $result = $this->retention()->run($this->retentionOptions(execute: true));

        $this->assertSame(0, $result->total());
        Storage::disk('local')->assertExists($submittedAttachment->storage_path);
        Storage::disk('local')->assertExists($missingCompletedAtAttachment->storage_path);
        $this->assertDatabaseCount('letter_retention_actions', 0);
    }

    public function test_supporting_document_day_13_is_retained(): void
    {
        $application = $this->completedScholarship(13);
        $attachment = $this->supportingDocument($application);

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
        ));

        $this->assertSame(0, $result->total());
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertNull($attachment->fresh()->retention_deleted_at);
    }

    public function test_supporting_document_day_14_is_deleted_on_execute(): void
    {
        $application = $this->completedScholarship(14);
        $attachment = $this->supportingDocument($application);

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
        ));

        $this->assertSame(1, $result->total());
        $this->assertSame(['completed' => 1], $result->countsByStatus());
        Storage::disk('local')->assertMissing($attachment->storage_path);
        $this->assertNotNull($attachment->fresh()->retention_deleted_at);
        $this->assertDatabaseHas('letter_retention_actions', [
            'category' => LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
            'action' => 'delete',
            'status' => 'completed',
            'subject_type' => 'attachment',
            'subject_id' => $attachment->id,
        ]);
    }

    public function test_intermediate_artifact_day_14_deletes_docx_and_pdf(): void
    {
        $application = $this->completedScholarship(14);
        $artifact = $this->artifact($application, ScholarshipApplication::LETTER_TYPE, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_INTERMEDIATE_ARTIFACT,
        ));

        $this->assertSame(1, $result->total());
        Storage::disk('local')->assertMissing($artifact->docx_path);
        Storage::disk('local')->assertMissing($artifact->pdf_path);
        $this->assertSame('deleted', $artifact->fresh()->retention_status);
    }

    public function test_final_pdf_day_29_is_retained(): void
    {
        $application = $this->completedScholarship(29);
        $artifact = $this->artifact($application, ScholarshipApplication::LETTER_TYPE, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_FINAL_OFFICIAL_PDF,
        ));

        $this->assertSame(0, $result->total());
        Storage::disk('local')->assertExists($artifact->pdf_path);
        $this->assertNull($artifact->fresh()->archived_at);
    }

    public function test_final_pdf_day_30_is_archived_verified_removed_from_active_storage_and_not_student_downloadable(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedScholarship(30, $student);
        $artifact = $this->artifact(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            body: '%PDF final official'
        );

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_FINAL_OFFICIAL_PDF,
        ));

        $this->assertSame(1, $result->total());
        $this->assertSame(['completed' => 1], $result->countsByStatus());

        $fresh = $artifact->fresh();
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame('archive', $fresh->archive_disk);
        $this->assertSame(hash('sha256', '%PDF final official'), $fresh->archive_checksum_sha256);
        Storage::disk('local')->assertMissing($artifact->pdf_path);
        Storage::disk('archive')->assertExists($fresh->archive_path);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/final-download")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');
    }

    public function test_archive_failure_keeps_active_final_pdf(): void
    {
        config(['letter_retention.archive.disk' => 'missing_archive_disk']);
        $application = $this->completedScholarship(30);
        $artifact = $this->artifact($application, ScholarshipApplication::LETTER_TYPE, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_FINAL_OFFICIAL_PDF,
        ));

        $this->assertSame(['blocked' => 1], $result->countsByStatus());
        Storage::disk('local')->assertExists($artifact->pdf_path);
        $this->assertNull($artifact->fresh()->archived_at);
        $this->assertDatabaseHas('letter_retention_actions', [
            'category' => LetterRetentionService::CATEGORY_FINAL_OFFICIAL_PDF,
            'action' => 'archive',
            'status' => 'blocked',
            'error_code' => 'archive_write_failed',
        ]);
    }

    public function test_archived_file_purges_after_configured_retention_and_keeps_audit_metadata(): void
    {
        $application = $this->completedScholarship(400);
        $artifact = $this->artifact($application, ScholarshipApplication::LETTER_TYPE, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        Storage::disk('local')->delete($artifact->pdf_path);
        $archivePath = 'final-pdfs/surat-permohonan-beasiswa/' . $application->id . '/' . $artifact->id . '/archived.pdf';
        Storage::disk('archive')->put($archivePath, '%PDF archived');
        $artifact->forceFill([
            'archive_disk' => 'archive',
            'archive_path' => $archivePath,
            'archive_checksum_sha256' => hash('sha256', '%PDF archived'),
            'archived_at' => $this->now->copy()->subDays(366),
            'retention_status' => 'archived',
        ])->save();

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_ARCHIVED_FINAL_PDF,
        ));

        $this->assertSame(['completed' => 1], $result->countsByStatus());
        Storage::disk('archive')->assertMissing($archivePath);
        $this->assertNotNull($artifact->fresh()->archive_purged_at);
        $this->assertDatabaseHas('letter_retention_actions', [
            'category' => LetterRetentionService::CATEGORY_ARCHIVED_FINAL_PDF,
            'action' => 'purge',
            'status' => 'completed',
            'checksum_sha256' => hash('sha256', '%PDF archived'),
        ]);
    }

    public function test_dry_run_makes_no_mutation(): void
    {
        $application = $this->completedScholarship(14);
        $attachment = $this->supportingDocument($application);

        $result = $this->retention()->run($this->retentionOptions(
            execute: false,
            category: LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
        ));

        $this->assertSame(['dry_run' => 1], $result->countsByStatus());
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertNull($attachment->fresh()->retention_deleted_at);
        $this->assertDatabaseCount('letter_retention_actions', 0);
    }

    public function test_missing_file_is_safe_and_repeat_execute_is_idempotent(): void
    {
        $application = $this->completedScholarship(14);
        $attachment = $this->supportingDocument($application);
        Storage::disk('local')->delete($attachment->storage_path);

        $first = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
        ));
        $second = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
        ));

        $this->assertSame(['already_missing' => 1], $first->countsByStatus());
        $this->assertSame(0, $second->total());
        $this->assertSame('already_missing', $attachment->fresh()->retention_status);
        $this->assertDatabaseCount('letter_retention_actions', 1);
    }

    public function test_checksum_mismatch_blocks_supporting_document_delete(): void
    {
        $application = $this->completedScholarship(14);
        $attachment = $this->supportingDocument($application, body: '%PDF current');
        $attachment->forceFill(['checksum_sha256' => hash('sha256', '%PDF expected')])->save();

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
        ));

        $this->assertSame(['blocked' => 1], $result->countsByStatus());
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertNull($attachment->fresh()->retention_deleted_at);
        $this->assertDatabaseHas('letter_retention_actions', [
            'category' => LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
            'status' => 'blocked',
            'error_code' => 'checksum_mismatch',
        ]);
    }

    public function test_ska_and_pln_artifacts_use_the_same_generic_retention_path(): void
    {
        $ska = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
            'completed_at' => $this->now->copy()->subDays(14),
            'student_reviewed_at' => $this->now->copy()->subDays(14),
        ]);
        $pln = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_COMPLETED,
            'completed_at' => $this->now->copy()->subDays(14),
            'student_reviewed_at' => $this->now->copy()->subDays(14),
        ]);
        $skaArtifact = $this->artifact($ska, SuratKeteranganAktifApplication::LETTER_TYPE, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $plnArtifact = $this->artifact($pln, ProsesLuarNegeriApplication::LETTER_TYPE, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $result = $this->retention()->run($this->retentionOptions(
            execute: true,
            category: LetterRetentionService::CATEGORY_INTERMEDIATE_ARTIFACT,
        ));

        $this->assertSame(['completed' => 2], $result->countsByStatus());
        Storage::disk('local')->assertMissing($skaArtifact->pdf_path);
        Storage::disk('local')->assertMissing($plnArtifact->pdf_path);
    }

    public function test_scheduler_task_registered_but_automation_off_by_default(): void
    {
        // The task is registered unconditionally; the DB automation flag gates it.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('letters:retention')
            ->assertExitCode(0);

        $this->assertFalse(app(\App\Services\LetterRetentionAutomationService::class)->isEnabled());
    }

    public function test_command_dry_run_has_no_raw_storage_path_output(): void
    {
        $application = $this->completedScholarship(14);
        $attachment = $this->supportingDocument($application);

        $this->artisan('letters:retention', [
            '--category' => LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
        ])
            ->expectsOutputToContain('letters:retention dry-run completed: 1 action(s).')
            ->doesntExpectOutputToContain($attachment->storage_path)
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertDatabaseCount('letter_retention_actions', 0);
    }

    private function retention(): LetterRetentionService
    {
        return $this->app->make(LetterRetentionService::class);
    }

    private function retentionOptions(bool $execute, ?string $category = null): LetterRetentionOptions
    {
        return new LetterRetentionOptions(
            execute: $execute,
            category: $category,
            batch: 100,
            now: $this->now,
        );
    }

    private function completedScholarship(int $daysAgo, mixed $student = null): ScholarshipApplication
    {
        return $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => $this->now->copy()->subDays($daysAgo),
            'student_reviewed_at' => $this->now->copy()->subDays($daysAgo),
        ]);
    }

    private function supportingDocument(ScholarshipApplication $application, string $body = '%PDF supporting'): LetterApplicationAttachment
    {
        Storage::disk('local')->put($this->supportingPath($application), $body);

        return LetterApplicationAttachment::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'document_key' => 'transkrip_nilai',
            'original_filename' => 'transkrip.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($body),
            'storage_disk' => 'local',
            'storage_path' => $this->supportingPath($application),
            'checksum_sha256' => hash('sha256', $body),
            'uploaded_by' => $application->user_id,
        ]);
    }

    private function supportingPath(ScholarshipApplication $application): string
    {
        return 'letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/' . $application->id . '/transkrip.pdf';
    }

    private function artifact(
        Model $application,
        string $letterType,
        string $phase,
        string $body = '%PDF artifact',
    ): LetterDocumentArtifact {
        $directory = 'letter-document-artifacts/' . $letterType . '/' . $application->getKey() . '/' . $phase;
        $docxPath = $directory . '/source.docx';
        $pdfPath = $directory . '/rendered.pdf';
        Storage::disk('local')->put($docxPath, 'docx body');
        Storage::disk('local')->put($pdfPath, $body);

        return LetterDocumentArtifact::create([
            'letter_type' => $letterType,
            'application_id' => $application->getKey(),
            'phase' => $phase,
            'version' => 1,
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $letterType . '|' . $application->getKey() . '|' . $phase),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'error_message' => null,
            'generated_by' => null,
            'generated_at' => $this->now->copy()->subDays(15),
        ]);
    }
}
