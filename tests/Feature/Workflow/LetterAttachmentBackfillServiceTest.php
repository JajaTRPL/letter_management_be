<?php

namespace Tests\Feature\Workflow;

use App\Enums\LetterAttachmentBackfillClassification as State;
use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Services\LetterAttachmentBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D2C backfill planner coverage: classification correctness, checksum/size
 * computation, marker handling, conflict detection, and the (test-only)
 * execute path — all against fake storage and the test DB.
 */
class LetterAttachmentBackfillServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const PDF = "%PDF-1.4\nbackfill body\n%%EOF\n";

    private LetterAttachmentBackfillService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreRetiredAttachmentColumnsForLegacyFixtureTests();
        Storage::fake('local');
        Storage::fake('public');
        $this->service = $this->app->make(LetterAttachmentBackfillService::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredAttachmentColumnsForLegacyFixtureTests();
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string, \App\Services\LetterAttachmentBackfillPlanItem> keyed by "type:appId:docKey" */
    private function planIndexed(array $filters = []): array
    {
        $index = [];
        foreach ($this->service->plan($filters) as $item) {
            $index["{$item->letterType}:{$item->applicationId}:{$item->documentKey}"] = $item;
        }

        return $index;
    }

    public function test_valid_legacy_beasiswa_source_is_ready_to_copy_with_checksum(): void
    {
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF);
        $app = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
            'slip_gaji_ayah_path' => null,
            'slip_gaji_ibu_path' => null,
        ]);

        $item = $this->planIndexed(['application_id' => $app->id])[ScholarshipApplication::LETTER_TYPE . ":{$app->id}:transkrip_nilai"];

        $this->assertSame(State::READY_TO_COPY, $item->classification);
        $this->assertSame('application/pdf', $item->sourceMime);
        $this->assertSame(strlen(self::PDF), $item->sourceSizeBytes);
        $this->assertSame(hash('sha256', self::PDF), $item->sourceChecksumSha256);
        $this->assertSame('local', $item->targetDisk);
        $this->assertStringStartsWith('letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/', $item->targetPrefix);
        // Empty siblings classify as empty.
        $ayah = $this->planIndexed(['application_id' => $app->id])[ScholarshipApplication::LETTER_TYPE . ":{$app->id}:slip_gaji_ayah"];
        $this->assertSame(State::LEGACY_VALUE_EMPTY, $ayah->classification);
    }

    public function test_missing_source_file_is_classified(): void
    {
        $app = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/gone.pdf'),
        ]);

        $item = $this->planIndexed(['application_id' => $app->id])[ScholarshipApplication::LETTER_TYPE . ":{$app->id}:transkrip_nilai"];
        $this->assertSame(State::SOURCE_FILE_MISSING, $item->classification);
    }

    public function test_prefix_invalid_source_is_classified(): void
    {
        Storage::disk('public')->put('somewhere/else/file.pdf', self::PDF);
        $app = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('somewhere/else/file.pdf'),
        ]);

        $item = $this->planIndexed(['application_id' => $app->id])[ScholarshipApplication::LETTER_TYPE . ":{$app->id}:transkrip_nilai"];
        $this->assertSame(State::SOURCE_PREFIX_INVALID, $item->classification);
    }

    public function test_traversal_and_null_byte_sources_are_unsafe(): void
    {
        foreach ([
            'scholarships/transcripts/../../etc/passwd',
            "scholarships/transcripts/legit.pdf\0.png",
        ] as $unsafe) {
            $app = $this->scholarshipApplication(null, ['transkrip_nilai_path' => $unsafe]);
            $item = $this->planIndexed(['application_id' => $app->id])[ScholarshipApplication::LETTER_TYPE . ":{$app->id}:transkrip_nilai"];
            $this->assertSame(State::SOURCE_PATH_UNSAFE, $item->classification, "for: {$unsafe}");
        }
    }

    public function test_non_pdf_source_is_mime_invalid(): void
    {
        // PNG bytes stored under the valid prefix with a .pdf-looking name.
        Storage::disk('public')->put(
            'scholarships/transcripts/fake.pdf',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
        );
        $app = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/fake.pdf'),
        ]);

        $item = $this->planIndexed(['application_id' => $app->id])[ScholarshipApplication::LETTER_TYPE . ":{$app->id}:transkrip_nilai"];
        $this->assertSame(State::SOURCE_MIME_INVALID, $item->classification);
        $this->assertNotSame('application/pdf', $item->sourceMime);
    }

    public function test_marker_with_registry_row_is_ok_and_without_is_blocker(): void
    {
        // Marker + registry row → OK.
        $okApp = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => 'attachment://transkrip_nilai/file.pdf',
        ]);
        LetterApplicationAttachment::create($this->registryRow($okApp->id, 'transkrip_nilai'));

        // Marker without registry row → blocker.
        $blockedApp = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => 'attachment://transkrip_nilai/file.pdf',
        ]);

        $index = $this->planIndexed();
        $this->assertSame(State::MARKER_BACKED_REGISTRY_OK, $index[ScholarshipApplication::LETTER_TYPE . ":{$okApp->id}:transkrip_nilai"]->classification);
        $this->assertSame(State::MARKER_WITHOUT_REGISTRY_BLOCKER, $index[ScholarshipApplication::LETTER_TYPE . ":{$blockedApp->id}:transkrip_nilai"]->classification);
    }

    public function test_existing_registry_match_and_conflict(): void
    {
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF);

        // Matching checksum → ALREADY_BACKFILLED_MATCH.
        $matchApp = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);
        LetterApplicationAttachment::create($this->registryRow($matchApp->id, 'transkrip_nilai', hash('sha256', self::PDF)));

        // Different checksum → REGISTRY_CONFLICT.
        $conflictApp = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);
        LetterApplicationAttachment::create($this->registryRow($conflictApp->id, 'transkrip_nilai', str_repeat('f', 64)));

        $index = $this->planIndexed();
        $this->assertSame(State::ALREADY_BACKFILLED_MATCH, $index[ScholarshipApplication::LETTER_TYPE . ":{$matchApp->id}:transkrip_nilai"]->classification);
        $this->assertSame(State::REGISTRY_CONFLICT, $index[ScholarshipApplication::LETTER_TYPE . ":{$conflictApp->id}:transkrip_nilai"]->classification);
    }

    public function test_magang_and_surat_tugas_mappings_are_planned(): void
    {
        Storage::disk('public')->put('surat-pengantar-magang/proposals/p.pdf', self::PDF);
        $magang = $this->magangApplication(null, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/p.pdf'),
        ]);

        Storage::disk('local')->put('surat-tugas/supporting/proposals/a.pdf', self::PDF);
        Storage::disk('local')->put('surat-tugas/supporting/pengantar/b.pdf', self::PDF);
        $tugas = $this->suratTugasApplication(null, [
            'proposal_kegiatan_magang_path' => 'surat-tugas/supporting/proposals/a.pdf',
            'surat_pengantar_magang_path' => 'surat-tugas/supporting/pengantar/b.pdf',
        ]);

        $index = $this->planIndexed();
        $this->assertSame(State::READY_TO_COPY, $index[SuratPengantarMagangApplication::LETTER_TYPE . ":{$magang->id}:proposal"]->classification);
        $this->assertSame(State::READY_TO_COPY, $index[SuratTugasApplication::LETTER_TYPE . ":{$tugas->id}:proposal"]->classification);
        $this->assertSame(State::READY_TO_COPY, $index[SuratTugasApplication::LETTER_TYPE . ":{$tugas->id}:surat_pengantar_magang"]->classification);
    }

    public function test_ktm_is_never_planned_and_ska_pln_have_no_documents(): void
    {
        $this->scholarshipApplication();
        $this->aktifApplication();
        $this->prosesLuarNegeriApplication();

        foreach ($this->service->plan() as $item) {
            $this->assertNotSame('ktm', $item->documentKey);
            $this->assertNotSame('surat-keterangan-aktif', $item->letterType);
            $this->assertNotSame('proses-luar-negeri', $item->letterType);
        }
    }

    public function test_plan_is_pure_read_no_rows_no_files(): void
    {
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF);
        $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);

        $before = Storage::disk('local')->allFiles();
        $this->service->plan();

        $this->assertSame(0, LetterApplicationAttachment::query()->count());
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_execute_copies_private_verifies_checksum_persists_and_keeps_source(): void
    {
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF);
        $app = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
            'slip_gaji_ayah_path' => null,
            'slip_gaji_ibu_path' => null,
        ]);

        $results = $this->service->execute(['application_id' => $app->id]);

        $row = LetterApplicationAttachment::query()
            ->where('application_id', $app->id)->where('document_key', 'transkrip_nilai')->first();
        $this->assertNotNull($row);
        $this->assertSame('local', $row->storage_disk);
        $this->assertSame(hash('sha256', self::PDF), $row->checksum_sha256);
        $this->assertStringStartsWith('letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/', $row->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($row->storage_path));
        // Source legacy file untouched, no public write expansion.
        $this->assertTrue(Storage::disk('public')->exists('scholarships/transcripts/legacy.pdf'));
        // Legacy column unchanged by backfill.
        $this->assertSame(Storage::url('scholarships/transcripts/legacy.pdf'), $app->fresh()->transkrip_nilai_path);
    }

    public function test_execute_is_idempotent(): void
    {
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF);
        $app = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
            'slip_gaji_ayah_path' => null,
            'slip_gaji_ibu_path' => null,
        ]);

        $this->service->execute(['application_id' => $app->id]);
        $this->service->execute(['application_id' => $app->id]);

        $this->assertSame(1, LetterApplicationAttachment::query()
            ->where('application_id', $app->id)->where('document_key', 'transkrip_nilai')->count());
    }

    /** @return array<string, mixed> */
    private function registryRow(int $applicationId, string $documentKey, ?string $checksum = null): array
    {
        return [
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $applicationId,
            'document_key' => $documentKey,
            'original_filename' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'storage_disk' => 'local',
            'storage_path' => 'letter-application-attachments/surat-permohonan-beasiswa/transkrip-nilai/' . $applicationId . '/x.pdf',
            'checksum_sha256' => $checksum ?? str_repeat('a', 64),
            'uploaded_by' => null,
        ];
    }
}
