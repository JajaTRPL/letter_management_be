<?php

namespace Tests\Feature\Workflow;

use App\Models\AcademicPeriod;
use App\Models\LetterDocumentArtifact;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AcademicSignatoryService;
use App\Services\LetterDocumentSourceHashService;
use App\Services\SuratKeteranganAktifDocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * The Prodi paraf on a letter is the paraf uploaded by the Kaprodi of the
 * applying student's own study program — even when the Sekprodi approved —
 * with the system paraf image as fallback until that Kaprodi uploads one.
 */
class KaprodiParafSourceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private ?string $tempParafPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'global_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->png(3, 3));
        config(['surat.global_paraf_path' => $this->tempParafPath]);
    }

    protected function tearDown(): void
    {
        if ($this->tempParafPath && is_file($this->tempParafPath)) {
            @unlink($this->tempParafPath);
        }

        parent::tearDown();
    }

    public function test_paraf_comes_from_the_kaprodi_of_each_students_own_program(): void
    {
        $department = $this->department();
        $programA = $this->studyProgram($department, ['name' => 'Prodi A']);
        $programB = $this->studyProgram($department, ['name' => 'Prodi B']);

        Storage::disk('public')->put('profiles/signatures/kaprodi-a.png', $this->png(4, 4));
        Storage::disk('public')->put('profiles/signatures/kaprodi-b.png', $this->png(5, 5));
        Storage::disk('public')->put('profiles/signatures/sekprodi-a.png', $this->png(6, 6));

        $this->akademik('kaprodi', ['study_program_id' => $programA->id, 'signature_path' => '/storage/profiles/signatures/kaprodi-a.png']);
        $this->akademik('kaprodi', ['study_program_id' => $programB->id, 'signature_path' => '/storage/profiles/signatures/kaprodi-b.png']);
        // Sekprodi of A also uploaded a paraf: it must never be used.
        $this->akademik('sekprodi', ['study_program_id' => $programA->id, 'signature_path' => '/storage/profiles/signatures/sekprodi-a.png']);

        $applicationA = $this->applicationIn($programA);
        $applicationB = $this->applicationIn($programB);

        $signatory = $this->app->make(AcademicSignatoryService::class);
        $this->assertSame([80, 40, 50], $this->inkColor(file_get_contents($signatory->prodiParafFilePath($applicationA))));
        $this->assertSame([100, 50, 50], $this->inkColor(file_get_contents($signatory->prodiParafFilePath($applicationB))));
    }

    public function test_no_fallback_paraf_when_kaprodi_has_not_uploaded_or_is_missing(): void
    {
        $department = $this->department();
        $withoutUpload = $this->studyProgram($department, ['name' => 'Prodi Tanpa Paraf']);
        $withoutKaprodi = $this->studyProgram($department, ['name' => 'Prodi Tanpa Kaprodi']);
        $this->akademik('kaprodi', ['study_program_id' => $withoutUpload->id, 'signature_path' => null]);

        // The system paraf file exists (setUp) but must never be used.
        $signatory = $this->app->make(AcademicSignatoryService::class);
        $this->assertNull($signatory->prodiParafFilePath($this->applicationIn($withoutUpload)));
        $this->assertNull($signatory->prodiParafFilePath($this->applicationIn($withoutKaprodi)));
    }

    public function test_ska_document_embeds_the_kaprodi_paraf(): void
    {
        $cachePath = config('surat.template_surat_keterangan_aktif_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath)) {
            $this->markTestSkipped('SKA template cache is not present in this environment.');
        }

        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $program = $this->studyProgram($department, ['name' => 'Teknologi Rekayasa Perangkat Lunak']);
        Storage::disk('public')->put('profiles/signatures/kaprodi-trpl.png', $this->png(7, 7));
        Storage::disk('public')->put('profiles/signatures/kadep.png', $this->png(2, 2));
        $this->akademik('kaprodi', ['study_program_id' => $program->id, 'signature_path' => '/storage/profiles/signatures/kaprodi-trpl.png']);
        $kadep = $this->akademik('kadep', ['department_id' => $department->id, 'signature_path' => '/storage/profiles/signatures/kadep.png']);
        $application = $this->applicationIn($program);

        $path = $this->app->make(SuratKeteranganAktifDocumentGenerationService::class)
            ->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, [
                'nomor_surat' => 'AKT/PENDING/001',
                'tanggal_surat' => '2026-05-21',
                'official_kadep' => $kadep,
            ]);

        $inkColors = array_map(fn (string $image) => $this->inkColor($image), $this->docxMedia(Storage::disk('local')->path($path)));
        $this->assertContains([140, 70, 50], $inkColors, 'Kaprodi paraf must be embedded.');
        $this->assertNotContains([60, 30, 50], $inkColors, 'System paraf must not be used.');
    }

    public function test_preview_cache_key_changes_when_the_kaprodi_paraf_changes(): void
    {
        $program = $this->studyProgram($this->department(), ['name' => 'Prodi Cache']);
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id, 'signature_path' => null]);
        $application = $this->applicationIn($program);
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false];
        $hashes = $this->app->make(LetterDocumentSourceHashService::class);

        $withSystemParaf = $hashes->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $flags);

        Storage::disk('public')->put('profiles/signatures/kaprodi-cache.png', $this->png(8, 8));
        $kaprodi->update(['signature_path' => '/storage/profiles/signatures/kaprodi-cache.png']);
        $withKaprodiParaf = $hashes->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $flags);

        $this->assertNotSame($withSystemParaf, $withKaprodiParaf);
    }

    private function applicationIn(StudyProgram $program)
    {
        $period = AcademicPeriod::query()->where('is_active', true)->first() ?? AcademicPeriod::create([
            'academic_year' => '2025/2026',
            'year_start' => 2025,
            'semester_type' => AcademicPeriod::SEMESTER_TYPE_GENAP,
            'semester_order' => AcademicPeriod::SEMESTER_ORDER_MAP[AcademicPeriod::SEMESTER_TYPE_GENAP],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);

        [$student] = $this->completeMahasiswa(
            ['name' => 'Mahasiswa ' . $program->name],
            ['nim' => sprintf('22/%06d/SV/%05d', random_int(1, 999999), random_int(1, 99999))],
            $program,
        );

        return $this->aktifApplication($student, [
            'nomor_surat' => null,
            'keperluan' => 'Keperluan beasiswa',
            'nama_orang_tua_wali' => 'Orang Tua',
            'pekerjaan_orang_tua_wali' => 'Pegawai Negeri',
            'nip_orang_tua_wali' => '197001012000011001',
            'pangkat_gol_orang_tua_wali' => 'IV/a',
            'instansi_orang_tua_wali' => 'Instansi',
            'submitted_at' => '2026-05-20 09:00:00',
        ]);
    }

    /** Distinct PNGs per size so each image's origin can be told apart. */
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $width * 20, $height * 10, 50));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * Color of the first visible (non-transparent) pixel, scanning from the
     * center: identifies whose paraf ended up in the normalized output.
     *
     * @return list<int>|null
     */
    private function inkColor(string $binary): ?array
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $color = imagecolorat($image, intdiv($width, 2), intdiv($height, 2));
        if ((($color >> 24) & 0x7F) >= 120) {
            return null;
        }

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    /** @return list<string> */
    private function docxMedia(string $absolutePath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($absolutePath) === true);
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/media/')) {
                $media[] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $media;
    }
}
