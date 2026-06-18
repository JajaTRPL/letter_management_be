<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Services\LetterAttachmentAccessResult;
use App\Services\LetterAttachmentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LetterAttachmentAccessServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private LetterAttachmentAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restoreRetiredAttachmentColumnsForLegacyFixtureTests();
        Storage::fake('local');
        Storage::fake('public');
        $this->service = $this->app->make(LetterAttachmentAccessService::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredAttachmentColumnsForLegacyFixtureTests();
        } finally {
            parent::tearDown();
        }
    }

    public function test_registry_row_is_used_even_when_legacy_column_exists(): void
    {
        $application = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', '%PDF legacy');
        Storage::disk('local')->put($this->registryPath(), '%PDF registry');
        $this->attachment($application->id);

        $resolved = $this->service->resolve($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai');

        $this->assertNotNull($resolved);
        $this->assertSame(LetterAttachmentAccessResult::SOURCE_REGISTRY, $resolved->source());
        $this->assertSame('local', $resolved->disk());
        $this->assertSame($this->registryPath(), $resolved->path());
        $this->assertSame([
            'document_key' => 'transkrip_nilai',
            'filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
        ], $resolved->publicMetadata());
        $this->assertArrayNotHasKey('path', $resolved->publicMetadata());
        $this->assertArrayNotHasKey('disk', $resolved->publicMetadata());
    }

    public function test_missing_registry_row_returns_null_even_when_legacy_path_exists_for_each_letter(): void
    {
        $scholarship = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', '%PDF scholarship');

        $magang = $this->magangApplication(null, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/legacy.pdf'),
        ]);
        Storage::disk('public')->put('surat-pengantar-magang/proposals/legacy.pdf', '%PDF magang');

        $suratTugas = $this->suratTugasApplication();
        Storage::disk('local')->put($suratTugas->proposal_kegiatan_magang_path, '%PDF surat tugas');

        foreach ([
            [$scholarship, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai'],
            [$magang, SuratPengantarMagangApplication::LETTER_TYPE, 'proposal'],
            [$suratTugas, SuratTugasApplication::LETTER_TYPE, 'proposal'],
        ] as [$application, $letterType, $key]) {
            $this->assertNull($this->service->resolve($application, $letterType, $key));
        }
    }

    public function test_access_service_has_no_active_legacy_fallback_source(): void
    {
        $source = file_get_contents(app_path('Services/LetterAttachmentAccessService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('resolveLegacyAttachment', $source);
        $this->assertStringNotContainsString('SOURCE_LEGACY', $source);
    }

    public function test_invalid_key_and_zero_document_letters_are_rejected(): void
    {
        $scholarship = $this->scholarshipApplication();

        $this->assertFalse($this->service->supports(ScholarshipApplication::LETTER_TYPE, 'ktm'));
        $this->assertNull($this->service->resolve($scholarship, ScholarshipApplication::LETTER_TYPE, 'ktm'));
        $this->assertFalse($this->service->supports(SuratKeteranganAktifApplication::LETTER_TYPE, 'proposal'));
        $this->assertFalse($this->service->supports(ProsesLuarNegeriApplication::LETTER_TYPE, 'proposal'));
    }

    public function test_registry_path_outside_prefix_is_rejected_without_legacy_fallback(): void
    {
        $application = $this->scholarshipWithLegacy();
        Storage::disk('local')->put('letter-application-attachments/other/private.pdf', '%PDF invalid');
        $this->attachment($application->id, [
            'storage_path' => 'letter-application-attachments/other/private.pdf',
        ]);

        $this->assertNull(
            $this->service->resolve($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai')
        );
    }

    public function test_encoded_traversal_and_null_byte_paths_are_rejected(): void
    {
        foreach ([
            'letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/%2e%2e/private.pdf',
            "letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/private.pdf\0.pdf",
        ] as $path) {
            $application = $this->scholarshipWithLegacy();
            $this->attachment($application->id, ['storage_path' => $path]);

            $this->assertNull(
                $this->service->resolve($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai')
            );

            LetterApplicationAttachment::query()->delete();
        }
    }

    public function test_registry_disk_mismatch_and_missing_file_are_rejected(): void
    {
        $application = $this->scholarshipWithLegacy();
        Storage::disk('public')->put($this->registryPath(), '%PDF wrong disk');
        $this->attachment($application->id, ['storage_disk' => 'public']);

        $this->assertNull(
            $this->service->resolve($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai')
        );

        LetterApplicationAttachment::query()->delete();
        $this->attachment($application->id);

        $this->assertNull(
            $this->service->resolve($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai')
        );
    }

    private function scholarshipWithLegacy(): ScholarshipApplication
    {
        $application = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', '%PDF legacy');

        return $application;
    }

    private function registryPath(): string
    {
        return 'letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/private.pdf';
    }

    private function attachment(int $applicationId, array $attributes = []): LetterApplicationAttachment
    {
        return LetterApplicationAttachment::create(array_merge([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $applicationId,
            'document_key' => 'transkrip_nilai',
            'original_filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 123,
            'storage_disk' => 'local',
            'storage_path' => $this->registryPath(),
            'checksum_sha256' => str_repeat('a', 64),
            'uploaded_by' => null,
        ], $attributes));
    }
}
