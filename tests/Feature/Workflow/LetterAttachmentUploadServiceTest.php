<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Models\SuratTugasApplication;
use App\Services\LetterAttachmentAccessResult;
use App\Services\LetterAttachmentAccessService;
use App\Services\LetterAttachmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * D2B shared private-write service unit coverage. Exercises the registry write,
 * checksum metadata, prefix safety, PDF policy, transactional create/replace,
 * orphan cleanup on failure, and after-commit replacement cleanup.
 */
class LetterAttachmentUploadServiceTest extends TestCase
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

    private function pdf(string $name = 'My Transkrip.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nbody\n%%EOF\n");
    }

    public function test_store_writes_private_local_only_with_registry_metadata(): void
    {
        $application = $this->scholarshipApplication();

        $result = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('My Transkrip.pdf'),
            (int) $application->user_id,
        );

        $row = $result->attachment();
        $this->assertSame('local', $row->storage_disk);
        $this->assertStringStartsWith(
            'letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/' . $application->id . '/',
            $row->storage_path,
        );
        $this->assertStringEndsWith('.pdf', $row->storage_path);
        $this->assertSame('My Transkrip.pdf', $row->original_filename);
        $this->assertSame('application/pdf', $row->mime_type);
        $this->assertSame(hash('sha256', "%PDF-1.4\nbody\n%%EOF\n"), $row->checksum_sha256);
        $this->assertSame(strlen("%PDF-1.4\nbody\n%%EOF\n"), $row->size_bytes);
        $this->assertSame((int) $application->user_id, $row->uploaded_by);

        // Private file exists; nothing written to the public disk.
        $this->assertTrue(Storage::disk('local')->exists($row->storage_path));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_store_does_not_mutate_legacy_application_column(): void
    {
        $application = $this->scholarshipApplication();

        $result = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('Transkrip Final.pdf'),
            (int) $application->user_id,
        );

        $this->assertSame('Transkrip Final.pdf', $result->attachment()->original_filename);
        $this->assertNull($application->fresh()->transkrip_nilai_path);
        $this->assertFalse(method_exists($result, 'legacyCompatibilityValue'));
    }

    public function test_replacement_is_transactional_and_old_file_deleted_after_commit(): void
    {
        $application = $this->scholarshipApplication();

        $first = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('first.pdf'),
            (int) $application->user_id,
        );
        $firstPath = $first->attachment()->storage_path;

        $second = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('second.pdf'),
            (int) $application->user_id,
        );
        $secondPath = $second->attachment()->storage_path;

        // Exactly one row (updateOrCreate keeps the unique key) pointing at the new file.
        $this->assertSame(1, LetterApplicationAttachment::query()
            ->where('application_id', $application->id)
            ->where('document_key', 'transkrip_nilai')
            ->count());
        $this->assertNotSame($firstPath, $secondPath);
        $this->assertTrue(Storage::disk('local')->exists($secondPath));
        // Replaced registry-managed private file removed after commit.
        $this->assertFalse(Storage::disk('local')->exists($firstPath));
    }

    public function test_db_persistence_failure_deletes_new_file_and_leaves_no_orphan(): void
    {
        $application = $this->scholarshipApplication();

        // Deterministically force DB persistence to fail by removing the registry
        // table so updateOrCreate throws. The service must delete the file it just
        // wrote (no orphan) and rethrow.
        Schema::drop('letter_application_attachments');

        try {
            $this->service->store(
                $application,
                ScholarshipApplication::LETTER_TYPE,
                'transkrip_nilai',
                $this->pdf('orphan.pdf'),
                (int) $application->user_id,
            );
            $this->fail('Expected persistence failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('persist', strtolower($exception->getMessage()));
        }

        // No orphaned private file remains under the document prefix.
        $remaining = Storage::disk('local')->allFiles(
            'letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai'
        );
        $this->assertEmpty($remaining);
    }

    public function test_non_pdf_is_rejected_and_no_file_or_row_is_written(): void
    {
        $application = $this->scholarshipApplication();
        $notPdf = UploadedFile::fake()->createWithContent('evil.exe', 'MZ binary');

        try {
            $this->service->store(
                $application,
                ScholarshipApplication::LETTER_TYPE,
                'transkrip_nilai',
                $notPdf,
                (int) $application->user_id,
            );
            $this->fail('Expected non-PDF upload to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('PDF', $exception->getMessage());
        }

        $this->assertSame(0, LetterApplicationAttachment::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_unknown_document_key_is_rejected(): void
    {
        $application = $this->scholarshipApplication();

        $this->expectException(RuntimeException::class);
        $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'ktm',
            $this->pdf(),
            (int) $application->user_id,
        );
    }

    public function test_written_file_resolves_registry_first_via_access_service(): void
    {
        $application = $this->scholarshipApplication();
        $access = $this->app->make(LetterAttachmentAccessService::class);

        $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('resolved.pdf'),
            (int) $application->user_id,
        );

        $resolved = $access->resolve($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai');
        $this->assertNotNull($resolved);
        $this->assertSame(LetterAttachmentAccessResult::SOURCE_REGISTRY, $resolved->source());
        $this->assertSame('local', $resolved->disk());
    }

    public function test_surat_tugas_two_keys_each_write_private_registry_rows(): void
    {
        $application = $this->suratTugasApplication();

        foreach (['proposal', 'surat_pengantar_magang'] as $key) {
            $result = $this->service->store(
                $application,
                SuratTugasApplication::LETTER_TYPE,
                $key,
                $this->pdf($key . '.pdf'),
                (int) $application->user_id,
            );
            $this->assertSame('local', $result->attachment()->storage_disk);
            $this->assertNotNull($result->attachment()->checksum_sha256);
        }

        $this->assertSame(2, LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_non_pdf_content_with_pdf_extension_is_rejected_by_content_check(): void
    {
        // Hardening: a file named .pdf whose CONTENT is not a PDF must be rejected
        // by the server-guessed (content/finfo-based) MIME check. UploadedFile::fake()
        // derives getMimeType() from the extension, so to actually exercise the
        // content check we build a REAL UploadedFile over a temp file with PNG
        // bytes but a .pdf name — getMimeType() then runs finfo on real content.
        $application = $this->scholarshipApplication();
        $tmp = tempnam(sys_get_temp_dir(), 'notpdf') . '.png';
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $realButNotPdf = new UploadedFile($tmp, 'looks-legit.pdf', null, null, true);

        try {
            $this->service->store(
                $application,
                ScholarshipApplication::LETTER_TYPE,
                'transkrip_nilai',
                $realButNotPdf,
                (int) $application->user_id,
            );
            $this->fail('Expected content-based MIME rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('PDF', $exception->getMessage());
        } finally {
            @unlink($tmp);
        }

        $this->assertSame(0, LetterApplicationAttachment::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_registry_filename_is_sanitized_for_url_structural_characters(): void
    {
        $application = $this->scholarshipApplication();

        $result = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf('we?ird#na/me.pdf'),
            (int) $application->user_id,
        );

        // basename strips the "na/" directory part; ? and # become underscores.
        $filename = $result->attachment()->original_filename;
        $this->assertStringNotContainsString('?', $filename);
        $this->assertStringNotContainsString('#', $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringEndsWith('.pdf', $filename);
    }

    public function test_overlong_filename_is_bounded_under_column_limit(): void
    {
        $application = $this->scholarshipApplication();
        $longName = str_repeat('a', 400) . '.pdf';

        $result = $this->service->store(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            $this->pdf($longName),
            (int) $application->user_id,
        );

        // The registry original_filename stays well under varchar(255), and the
        // .pdf suffix is preserved.
        $this->assertLessThanOrEqual(180, mb_strlen($result->attachment()->original_filename));
        $this->assertStringEndsWith('.pdf', $result->attachment()->original_filename);
    }
}
