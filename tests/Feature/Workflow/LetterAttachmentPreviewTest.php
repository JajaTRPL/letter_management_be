<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Services\LetterAttachmentAccessResult;
use App\Services\LetterAttachmentPreviewResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LetterAttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreRetiredAttachmentColumnsForLegacyFixtureTests();
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredAttachmentColumnsForLegacyFixtureTests();
        } finally {
            parent::tearDown();
        }
    }

    public function test_shared_response_is_inline_private_pdf_without_internal_path_leak(): void
    {
        Storage::fake('local');
        $path = 'letter-application-attachments/test/proposal/sample.pdf';
        Storage::disk('local')->put($path, '%PDF shared response');

        $response = TestResponse::fromBaseResponse(
            $this->app->make(LetterAttachmentPreviewResponseService::class)->make(
                new LetterAttachmentAccessResult('proposal', 'local', $path, 'application/pdf', 'sample.pdf', 'registry')
            )
        );
        $content = $response->streamedContent();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
        $this->assertSame('%PDF shared response', $content);
        $this->assertStringNotContainsString($path, $content);
    }

    public function test_existing_endpoint_uses_registry_row_even_when_legacy_column_exists(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$student, $profile] = $this->completeMahasiswa();
        $legacyPath = 'scholarships/transcripts/legacy.pdf';
        $registryPath = 'letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/private.pdf';
        Storage::disk('public')->put($legacyPath, '%PDF legacy body');
        Storage::disk('local')->put($registryPath, '%PDF registry body');

        $application = ScholarshipApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $profile->id,
            'scholarship_name' => 'Registry First',
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);
        $application->forceFill([
            'transkrip_nilai_path' => Storage::url($legacyPath),
        ])->save();

        LetterApplicationAttachment::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'document_key' => 'transkrip_nilai',
            'original_filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 18,
            'storage_disk' => 'local',
            'storage_path' => $registryPath,
            'checksum_sha256' => hash('sha256', '%PDF registry body'),
            'uploaded_by' => $student->id,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/scholarship/{$application->id}/supporting-documents/transkrip_nilai/preview");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $content = $response->streamedContent();
        $this->assertSame('%PDF registry body', $content);
        $this->assertStringNotContainsString($registryPath, $content);
    }

    public function test_existing_endpoint_returns_not_found_without_registry_row_even_when_legacy_column_exists(): void
    {
        Storage::fake('public');
        [$student, $profile] = $this->completeMahasiswa();
        $legacyPath = 'scholarships/transcripts/legacy-only.pdf';
        Storage::disk('public')->put($legacyPath, '%PDF legacy-only body');

        $application = ScholarshipApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $profile->id,
            'scholarship_name' => 'Legacy Only',
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);
        $application->forceFill([
            'transkrip_nilai_path' => Storage::url($legacyPath),
        ])->save();

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/scholarship/{$application->id}/supporting-documents/transkrip_nilai/preview");

        $response->assertNotFound();
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
    }

    public function test_registry_private_path_is_not_served_by_api_storage_compatibility_route(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/letter-application-attachments/test/private.pdf')
            ->assertForbidden();
    }
}
