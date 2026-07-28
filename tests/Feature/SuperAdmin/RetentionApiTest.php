<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\LetterApplicationAttachment;
use App\Models\LetterDocumentArtifact;
use App\Models\LetterRetentionAction;
use App\Models\ScholarshipApplication;
use App\Services\LetterRetentionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class RetentionApiTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-06-11 09:00:00');
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

    public function test_non_super_admin_denied_and_super_admin_allowed(): void
    {
        Sanctum::actingAs($this->tendikPersuratan());
        $this->getJson('/api/super-admin/retention/overview')->assertForbidden();

        Sanctum::actingAs($this->primarySuperAdmin());
        $this->getJson('/api/super-admin/retention/overview')
            ->assertOk()
            ->assertJsonPath('data.scheduler.enabled', false)
            ->assertJsonPath('data.scheduler.api_managed', false);
    }

    public function test_policy_update_is_validated_and_global_only(): void
    {
        Sanctum::actingAs($this->primarySuperAdmin());

        $this->putJson('/api/super-admin/retention/policy', [
            'supporting_document_retention_days' => 0,
        ])->assertUnprocessable();

        $this->putJson('/api/super-admin/retention/policy', [
            'supporting_document_retention_days' => 21,
            'intermediate_artifact_retention_days' => 22,
            'final_pdf_active_days' => 45,
            'archive_retention_days' => 730,
        ])
            ->assertOk()
            ->assertJsonPath('data.scope', 'global')
            ->assertJsonPath('data.values.supporting_document_retention_days', 21)
            ->assertJsonPath('data.values.archive_retention_days', 730);

        $this->assertDatabaseHas('letter_retention_policies', [
            'scope' => 'global',
            'supporting_document_retention_days' => 21,
            'archive_retention_days' => 730,
        ]);
    }

    public function test_candidate_archive_and_action_lists_are_paginated_without_raw_paths(): void
    {
        $application = $this->completedScholarship(400);
        $attachment = $this->attachRegistryDocument($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai');
        $artifact = $this->archivedFinalArtifact($application, 366);
        LetterRetentionAction::create([
            'action_key' => hash('sha256', 'listed-action'),
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'subject_type' => 'artifact',
            'subject_id' => $artifact->id,
            'category' => LetterRetentionService::CATEGORY_FINAL_OFFICIAL_PDF,
            'action' => 'archive',
            'status' => 'completed',
            'storage_disk' => 'archive',
            'storage_path_hash' => hash('sha256', (string) $artifact->archive_path),
            'checksum_sha256' => $artifact->archive_checksum_sha256,
            'eligible_at' => $this->now->copy()->subDay(),
            'executed_at' => $this->now->copy()->subDay(),
            'metadata' => ['schema_version' => 1, 'trigger' => 'system'],
        ]);

        Sanctum::actingAs($this->primarySuperAdmin());

        foreach ([
            '/api/super-admin/retention/candidates?per_page=1',
            '/api/super-admin/retention/archives?per_page=1',
            '/api/super-admin/retention/actions?per_page=1',
        ] as $url) {
            $response = $this->getJson($url)
                ->assertOk()
                ->assertJsonStructure(['message', 'data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']])
                ->assertJsonPath('data.0.verification_state', 'verified')
                ->assertJsonMissingPath('data.0.checksum_sha256')
                ->assertJsonMissingPath('data.0.archive_checksum_sha256');

            $content = $response->getContent();
            $this->assertStringNotContainsString((string) $attachment->storage_path, $content);
            $this->assertStringNotContainsString((string) $artifact->archive_path, $content);
            $this->assertStringNotContainsString((string) $artifact->archive_checksum_sha256, $content);
            $this->assertStringNotContainsString('storage_disk', $content);
            $this->assertStringNotContainsString('archive_path', $content);
            $this->assertStringNotContainsString('pdf_path', $content);
            $this->assertStringNotContainsString('checksum_sha256', $content);
            $this->assertStringNotContainsString('archive_checksum_sha256', $content);
            $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/i', $content);
        }
    }

    public function test_manual_dry_run_has_no_mutation(): void
    {
        $application = $this->completedScholarship(14);
        $attachment = $this->attachRegistryDocument($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai');

        Sanctum::actingAs($this->primarySuperAdmin());

        $this->postJson('/api/super-admin/retention/dry-run', [
            'category' => LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
            'subject_type' => 'attachment',
            'subject_id' => $attachment->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.counts_by_status.dry_run', 1)
            ->assertJsonPath('data.actions.0.verification_state', 'verified')
            ->assertJsonMissingPath('data.actions.0.checksum_sha256')
            ->assertJsonMissingPath('data.actions.0.archive_checksum_sha256');

        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertNull($attachment->fresh()->retention_deleted_at);
        $this->assertDatabaseCount('letter_retention_actions', 0);
    }

    public function test_manual_execute_rejects_ineligible_active_workflow_row(): void
    {
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'completed_at' => null,
        ]);
        $attachment = $this->attachRegistryDocument($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai');

        Sanctum::actingAs($this->primarySuperAdmin());

        $this->postJson('/api/super-admin/retention/execute', [
            'category' => LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
            'subject_type' => 'attachment',
            'subject_id' => $attachment->id,
            'reason' => 'Validate active workflow safety.',
        ])
            ->assertStatus(422);

        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertDatabaseCount('letter_retention_actions', 0);
    }

    public function test_manual_execute_deletes_eligible_item_and_writes_audit(): void
    {
        $application = $this->completedScholarship(14);
        $attachment = $this->attachRegistryDocument($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai');
        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/retention/execute', [
            'category' => LetterRetentionService::CATEGORY_SUPPORTING_DOCUMENT,
            'subject_type' => 'attachment',
            'subject_id' => $attachment->id,
            'reason' => 'Manual validation cleanup for eligible fixture.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.verification_state', 'verified')
            ->assertJsonMissingPath('data.storage_disk')
            ->assertJsonMissingPath('data.storage_path_hash')
            ->assertJsonMissingPath('data.checksum_sha256');

        Storage::disk('local')->assertMissing($attachment->storage_path);
        $action = LetterRetentionAction::query()->firstOrFail();
        $this->assertSame('manual', $action->metadata['trigger']);
        $this->assertSame($admin->id, $action->metadata['actor_id']);
        $this->assertSame('Manual validation cleanup for eligible fixture.', $action->metadata['reason']);
    }

    public function test_archive_restore_requires_reason_and_verifies_checksum(): void
    {
        $application = $this->completedScholarship(400);
        $artifact = $this->archivedFinalArtifact($application, 10);
        $artifact->forceFill(['archive_checksum_sha256' => hash('sha256', '%PDF expected')])->save();

        Sanctum::actingAs($this->primarySuperAdmin());

        $this->postJson("/api/super-admin/retention/archives/{$artifact->id}/restore")
            ->assertUnprocessable();

        $this->postJson("/api/super-admin/retention/archives/{$artifact->id}/restore", [
            'reason' => 'Restore after archive checksum mismatch test.',
        ])
            ->assertStatus(409)
            ->assertJsonPath('data.error_code', 'archive_checksum_mismatch')
            ->assertJsonPath('data.verification_state', 'verification_failed')
            ->assertJsonMissingPath('data.checksum_sha256')
            ->assertJsonMissingPath('data.archive_checksum_sha256');

        Storage::disk('local')->assertMissing($artifact->pdf_path);

        $artifact->forceFill(['archive_checksum_sha256' => hash('sha256', '%PDF archived')])->save();
        $this->postJson("/api/super-admin/retention/archives/{$artifact->id}/restore", [
            'reason' => 'Restore verified archive for user request.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.verification_state', 'verified')
            ->assertJsonMissingPath('data.storage_disk')
            ->assertJsonMissingPath('data.checksum_sha256');

        Storage::disk('local')->assertExists($artifact->pdf_path);
        $this->assertSame('restored', $artifact->fresh()->retention_status);
        $this->assertDatabaseHas('letter_retention_actions', [
            'action' => 'restore',
            'status' => 'completed',
            'subject_id' => $artifact->id,
        ]);
    }

    public function test_archive_purge_requires_reason_and_eligibility(): void
    {
        $application = $this->completedScholarship(400);
        $notDue = $this->archivedFinalArtifact($application, 30);
        $due = $this->archivedFinalArtifact($application, 366, version: 2);

        Sanctum::actingAs($this->primarySuperAdmin());

        $this->postJson("/api/super-admin/retention/archives/{$due->id}/purge")
            ->assertUnprocessable();

        $this->postJson("/api/super-admin/retention/archives/{$notDue->id}/purge", [
            'reason' => 'Attempt not due purge for guard test.',
        ])
            ->assertStatus(422);

        $this->postJson("/api/super-admin/retention/archives/{$due->id}/purge", [
            'reason' => 'Purge archived fixture after TTL.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.verification_state', 'verified')
            ->assertJsonMissingPath('data.checksum_sha256')
            ->assertJsonMissingPath('data.archive_checksum_sha256');

        Storage::disk('archive')->assertMissing($due->archive_path);
        Storage::disk('archive')->assertExists($notDue->archive_path);
        $this->assertNotNull($due->fresh()->archive_purged_at);
        $this->assertDatabaseHas('letter_retention_actions', [
            'action' => 'purge',
            'status' => 'completed',
            'subject_id' => $due->id,
        ]);
    }

    public function test_retention_task_is_registered_but_automation_off_by_default(): void
    {
        // The scheduled task is registered unconditionally so the UI switch is
        // the real control; the DB automation flag (default OFF) gates execution.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('letters:retention')
            ->assertExitCode(0);

        Sanctum::actingAs($this->primarySuperAdmin());
        $this->getJson('/api/super-admin/retention/automation')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.health_status', 'disabled')
            ->assertJsonPath('data.schedule_registered', true);
    }

    private function completedScholarship(int $daysAgo): ScholarshipApplication
    {
        return $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => $this->now->copy()->subDays($daysAgo),
            'student_reviewed_at' => $this->now->copy()->subDays($daysAgo),
        ]);
    }

    private function archivedFinalArtifact(ScholarshipApplication $application, int $archivedDaysAgo, int $version = 1): LetterDocumentArtifact
    {
        $artifact = $this->artifact(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            version: $version,
        );
        Storage::disk('local')->delete($artifact->pdf_path);

        $archivePath = 'final-pdfs/surat-permohonan-beasiswa/' . $application->id . '/' . $artifact->id . '/archived-v' . $version . '.pdf';
        Storage::disk('archive')->put($archivePath, '%PDF archived');

        $artifact->forceFill([
            'archive_disk' => 'archive',
            'archive_path' => $archivePath,
            'archive_checksum_sha256' => hash('sha256', '%PDF archived'),
            'archived_at' => $this->now->copy()->subDays($archivedDaysAgo),
            'retention_status' => 'archived',
        ])->save();

        return $artifact;
    }

    private function artifact(
        Model $application,
        string $letterType,
        string $phase,
        int $version = 1,
        string $body = '%PDF artifact',
    ): LetterDocumentArtifact {
        $directory = 'letter-document-artifacts/' . $letterType . '/' . $application->getKey() . '/' . $phase;
        $docxPath = $directory . '/source-v' . $version . '.docx';
        $pdfPath = $directory . '/rendered-v' . $version . '.pdf';
        Storage::disk('local')->put($docxPath, 'docx body');
        Storage::disk('local')->put($pdfPath, $body);

        return LetterDocumentArtifact::create([
            'letter_type' => $letterType,
            'application_id' => $application->getKey(),
            'phase' => $phase,
            'version' => $version,
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $letterType . '|' . $application->getKey() . '|' . $phase . '|' . $version),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'error_message' => null,
            'generated_by' => null,
            'generated_at' => $this->now->copy()->subDays(30),
        ]);
    }
}
