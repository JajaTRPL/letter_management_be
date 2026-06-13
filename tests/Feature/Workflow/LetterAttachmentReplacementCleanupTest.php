<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Services\LetterAttachmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D2B replacement-cleanup safety: only the replaced registry-managed PRIVATE
 * file is deleted after commit, the deletion is prefix-guarded, and legacy
 * public/private originals are never touched.
 */
class LetterAttachmentReplacementCleanupTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private LetterAttachmentUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->service = $this->app->make(LetterAttachmentUploadService::class);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$name}\n%%EOF\n");
    }

    public function test_replacement_deletes_only_previous_registry_managed_file(): void
    {
        $application = $this->scholarshipApplication();

        $old = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('old.pdf'),
            (int) $application->user_id,
        );
        $oldPath = $old->attachment()->storage_path;
        $this->assertTrue(Storage::disk('local')->exists($oldPath));

        $new = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('new.pdf'),
            (int) $application->user_id,
        );
        $newPath = $new->attachment()->storage_path;

        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertTrue(Storage::disk('local')->exists($newPath));
    }

    public function test_replacement_never_deletes_legacy_public_original(): void
    {
        // Simulate a pre-D2B Beasiswa application whose legacy public file exists.
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', '%PDF legacy public');
        $application = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);

        // First registry upload creates a registry row and preserves the legacy column.
        $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('first.pdf'),
            (int) $application->user_id,
        );

        // Replace it.
        $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('second.pdf'),
            (int) $application->user_id,
        );

        // The legacy public original is untouched throughout.
        $this->assertTrue(Storage::disk('public')->exists('scholarships/transcripts/legacy.pdf'));
    }

    public function test_cleanup_prefix_guard_ignores_foreign_storage_path(): void
    {
        $application = $this->scholarshipApplication();

        // Plant a registry row whose storage_path points outside the document's
        // managed prefix (e.g. a hand-tampered or unrelated path) plus a real
        // unrelated file that must never be deleted.
        Storage::disk('local')->put('unrelated/keep-me.pdf', '%PDF keep');
        LetterApplicationAttachment::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'document_key' => 'transkrip_nilai',
            'original_filename' => 'foreign.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'storage_disk' => 'local',
            'storage_path' => 'unrelated/keep-me.pdf',
            'checksum_sha256' => str_repeat('b', 64),
            'uploaded_by' => null,
        ]);

        // Replacing it must NOT delete the foreign path (prefix guard).
        $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('replacement.pdf'),
            (int) $application->user_id,
        );

        $this->assertTrue(Storage::disk('local')->exists('unrelated/keep-me.pdf'));
    }
}
