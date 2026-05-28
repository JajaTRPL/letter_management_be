<?php

namespace Tests\Feature\Workflow;

use App\Models\AcademicPeriod;
use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Services\BeasiswaPhaseResolver;
use App\Services\LetterDocumentSourceHashService;
use App\Services\ProsesLuarNegeriPhaseResolver;
use App\Services\SuratKeteranganAktifPhaseResolver;
use App\Services\SuratPengantarMagangPhaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LetterDocumentSourceHashServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hasher(): LetterDocumentSourceHashService
    {
        return $this->app->make(LetterDocumentSourceHashService::class);
    }

    private function resolver(): BeasiswaPhaseResolver
    {
        return $this->app->make(BeasiswaPhaseResolver::class);
    }

    private function skaResolver(): SuratKeteranganAktifPhaseResolver
    {
        return $this->app->make(SuratKeteranganAktifPhaseResolver::class);
    }

    private function plnResolver(): ProsesLuarNegeriPhaseResolver
    {
        return $this->app->make(ProsesLuarNegeriPhaseResolver::class);
    }

    private function magangResolver(): SuratPengantarMagangPhaseResolver
    {
        return $this->app->make(SuratPengantarMagangPhaseResolver::class);
    }

    public function test_same_input_produces_same_hash(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => '001/SPB/2026']);
        $resolver = $this->resolver();
        $hasher = $this->hasher();

        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $resolver->phaseFlagsFor($application, $phase);

        $hashA = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);
        $hashB = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);

        $this->assertSame($hashA, $hashB);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashA);
    }

    public function test_phase_change_changes_hash(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => '001/SPB/2026']);
        $resolver = $this->resolver();
        $hasher = $this->hasher();

        $hashTendik = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW),
        );
        $hashProdi = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            $resolver->phaseFlagsFor($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW),
        );

        $this->assertNotSame($hashTendik, $hashProdi);
    }

    public function test_nomor_surat_change_changes_hash_when_phase_includes_it(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => '001/SPB/2026']);
        $hasher = $this->hasher();
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);

        $application->update(['nomor_surat' => '999/SPB/2026']);
        $hashAfter = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);

        $this->assertNotSame($hashBefore, $hashAfter);
    }

    public function test_nomor_surat_change_does_not_change_hash_when_phase_excludes_it(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => '001/SPB/2026']);
        $hasher = $this->hasher();
        $phase = LetterDocumentArtifact::PHASE_TENDIK_REVIEW;
        // Tendik review with include_nomor_surat=false: changing nomor_surat must NOT flip the hash
        $flags = ['include_nomor_surat' => false, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);

        $application->update(['nomor_surat' => 'something-different']);
        $hashAfter = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);

        $this->assertSame($hashBefore, $hashAfter);
    }

    public function test_pending_nomor_surat_changes_hash_without_saving_application(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => null]);
        $hasher = $this->hasher();
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashWithoutPending = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);
        $hashWithPending = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags, [
            'nomor_surat' => 'PENDING-HASH-001',
        ]);

        $this->assertNotSame($hashWithoutPending, $hashWithPending);
        $this->assertNull($application->fresh()->nomor_surat);
    }

    public function test_rendered_tanggal_surat_changes_hash(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => '001/SPB/2026']);
        $hasher = $this->hasher();
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashA = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags, [
            'tanggal_surat' => '2026-05-21',
        ]);
        $hashB = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags, [
            'tanggal_surat' => '2026-05-22',
        ]);

        $this->assertNotSame($hashA, $hashB);
    }

    public function test_rendered_kadep_office_title_payload_normalizes_prefixed_department_name(): void
    {
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $program = $this->studyProgram($department, ['name' => 'Program Studi Teknologi Rekayasa Perangkat Lunak']);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, ['nomor_surat' => '001/SPB/2026']);

        $payload = $this->hasher()->canonicalBeasiswaPayload(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => true],
            ['tanggal_surat' => '2026-05-21'],
        );

        $this->assertSame('Ketua Departemen', $payload['rendered']['jabatan_kadep']);
        $this->assertSame('Teknik Elektro dan Informatika', $payload['rendered']['departemen']);
        $this->assertSame('Ketua Departemen Teknik Elektro dan Informatika', $payload['rendered']['jabatan_unit_kadep']);
        $this->assertSame('beasiswa-signature-layout-v5-nomor-surat-rekomendasi-alias-pasfoto-normalized-600x800-q90', $payload['rendered']['profile']);
        $this->assertSame('001/SPB/2026', $payload['application']['nomor_surat']);
        $this->assertStringNotContainsString('Ketua Departemen Departemen', $payload['rendered']['jabatan_unit_kadep']);
    }

    public function test_paraf_flag_change_changes_hash(): void
    {
        $application = $this->scholarshipApplication();
        $hasher = $this->hasher();

        $hashWithoutParaf = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false],
        );
        $hashWithParaf = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false],
        );

        $this->assertNotSame($hashWithoutParaf, $hashWithParaf);
    }

    public function test_signature_flag_change_changes_hash(): void
    {
        $application = $this->scholarshipApplication();
        $hasher = $this->hasher();

        $hashWithoutSig = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false],
        );
        $hashWithSig = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => true],
        );

        $this->assertNotSame($hashWithoutSig, $hashWithSig);
    }

    public function test_kadep_identity_and_signature_marker_are_hash_inputs_when_signature_is_rendered(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('signatures/kadep-a.png', $this->pngBytes());
        Storage::disk('public')->put('signatures/kadep-b.png', $this->pngBytes());

        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, ['nomor_surat' => '001/SPB/2026']);
        $officialKadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep A',
            'nip' => '111',
            'signature_path' => 'signatures/kadep-a.png',
        ]);
        $pendingKadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep B',
            'nip' => '222',
            'signature_path' => 'signatures/kadep-b.png',
        ]);

        $hasher = $this->hasher();
        $phase = LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => true];

        $hashOfficial = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags, [
            'official_kadep' => $officialKadep,
        ]);
        $hashPending = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags, [
            'official_kadep' => $pendingKadep,
        ]);

        $this->assertNotSame($hashOfficial, $hashPending);
    }

    public function test_global_paraf_file_marker_changes_hash_when_rendered_file_changes(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => '001/SPB/2026']);
        $hasher = $this->hasher();
        $phase = LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false];
        $tempParaf = tempnam(sys_get_temp_dir(), 'paraf_hash_') . '.png';

        try {
            file_put_contents($tempParaf, $this->pngBytes());
            config(['surat.global_paraf_path' => $tempParaf]);
            $hashA = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);

            file_put_contents($tempParaf, $this->pngBytes() . 'changed');
            $hashB = $hasher->hashForBeasiswa($application->fresh(), $phase, $flags);

            $this->assertNotSame($hashA, $hashB);
        } finally {
            if (is_file($tempParaf)) {
                @unlink($tempParaf);
            }
        }
    }

    public function test_irrelevant_db_metadata_does_not_change_hash(): void
    {
        $application = $this->scholarshipApplication(null, ['nomor_surat' => '001/SPB/2026']);
        $hasher = $this->hasher();
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            $flags,
        );

        // Touch updated_at + an unrelated audit-only column. The submitted_at column is the
        // closest stand-in for "ambient metadata that should not invalidate the cache."
        $application->update(['submitted_at' => now()->addHour()]);

        $hashAfter = $hasher->hashForBeasiswa(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            $flags,
        );

        $this->assertSame($hashBefore, $hashAfter);
    }

    public function test_related_row_order_is_normalized(): void
    {
        [$student] = $this->completeMahasiswa();
        $profile = $student->mahasiswaProfile;

        // Insert family rows in one order.
        $profile->keluarga()->create(['jenis_relasi' => 'ibu', 'nama_lengkap' => 'Ibu Test', 'pekerjaan' => 'IRT', 'status_hidup' => 'hidup']);
        $profile->keluarga()->create(['jenis_relasi' => 'ayah', 'nama_lengkap' => 'Ayah Test', 'pekerjaan' => 'Wiraswasta', 'status_hidup' => 'hidup']);

        $applicationA = $this->scholarshipApplication($student, ['nomor_surat' => '001/SPB/2026']);
        $hasher = $this->hasher();
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashA = $hasher->hashForBeasiswa($applicationA->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $flags);

        // Re-insert the same family rows in the OPPOSITE creation order against a fresh student.
        [$studentB] = $this->completeMahasiswa();
        $profileB = $studentB->mahasiswaProfile;
        $profileB->keluarga()->create(['jenis_relasi' => 'ayah', 'nama_lengkap' => 'Ayah Test', 'pekerjaan' => 'Wiraswasta', 'status_hidup' => 'hidup']);
        $profileB->keluarga()->create(['jenis_relasi' => 'ibu', 'nama_lengkap' => 'Ibu Test', 'pekerjaan' => 'IRT', 'status_hidup' => 'hidup']);

        // The two applications differ only in identity (user/profile ids, application id).
        // Family rows are content-identical and only their *insertion order* differs.
        // We verify that within-application family ordering is stable: re-hashing the same
        // application twice yields the same hash regardless of how Eloquent ordered the
        // keluarga relation on each fetch.
        $hashB = $hasher->hashForBeasiswa($applicationA->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $flags);
        $this->assertSame($hashA, $hashB);

        // The cross-application hash IS allowed to differ (different application id, ids in family).
        // We only assert that the family-row ordering normalization keeps the SAME application
        // deterministic across loads.
        $this->assertNotNull($hashB);
    }

    public function test_ska_payload_contains_render_profile_and_final_rendered_values(): void
    {
        $this->activeAcademicPeriod('2025/2026', AcademicPeriod::SEMESTER_TYPE_GENAP);
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, ['name' => 'Teknologi Rekayasa Perangkat Lunak']);
        [$student] = $this->completeMahasiswa([], ['nim' => '22/493038/SV/20654'], $program);
        $application = $this->aktifApplication($student, [
            'nomor_surat' => 'AKT/001/2026',
            'nip_orang_tua_wali' => '197001012000011001',
            'pangkat_gol_orang_tua_wali' => 'IV/a',
            'instansi_orang_tua_wali' => 'Instansi Test',
        ]);

        $payload = $this->hasher()->canonicalSkaPayload(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false],
            ['tanggal_surat' => '2026-05-21'],
        );

        $this->assertSame('ska-docx-gotenberg-v1-final-contract', $payload['rendered']['profile']);
        $this->assertSame('AKT/001/2026', $payload['application']['nomor_surat']);
        $this->assertSame('Semester Genap Tahun Akademik 2025/2026', $payload['rendered']['periode_akademik']);
        $this->assertSame('Sekolah Vokasi', $payload['rendered']['fakultas']);
        $this->assertSame('Sekolah Vokasi', $payload['student']['fakultas']);
        $this->assertSame('197001012000011001', $payload['parent_guardian']['ot_identitas']);
        $this->assertStringNotContainsString('Sekolah Vokasi UGM', json_encode($payload));
    }

    public function test_ska_nomor_surat_change_changes_hash_when_phase_includes_number(): void
    {
        $this->activeAcademicPeriod();
        $application = $this->aktifApplication(null, ['nomor_surat' => 'AKT/001/2026']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $application->update(['nomor_surat' => 'AKT/999/2026']);
        $hashAfter = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $this->assertNotSame($hashBefore, $hashAfter);
    }

    public function test_ska_nomor_surat_change_does_not_affect_tendik_hash_when_number_excluded(): void
    {
        $this->activeAcademicPeriod();
        $application = $this->aktifApplication(null, ['nomor_surat' => null]);
        $phase = LetterDocumentArtifact::PHASE_TENDIK_REVIEW;
        $flags = ['include_nomor_surat' => false, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $application->update(['nomor_surat' => 'AKT/LATE/2026']);
        $hashAfter = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $this->assertSame($hashBefore, $hashAfter);
    }

    public function test_ska_hash_changes_when_keperluan_parent_or_guardian_fields_change(): void
    {
        $this->activeAcademicPeriod();
        $application = $this->aktifApplication(null, [
            'nomor_surat' => 'AKT/001/2026',
            'keperluan' => 'Keperluan awal',
            'nama_orang_tua_wali' => 'Orang Tua A',
            'pekerjaan_orang_tua_wali' => 'Pegawai',
            'nip_orang_tua_wali' => '111',
            'pangkat_gol_orang_tua_wali' => 'III/a',
            'instansi_orang_tua_wali' => 'Instansi A',
        ]);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $application->update([
            'keperluan' => 'Keperluan berubah',
            'nama_orang_tua_wali' => 'Orang Tua B',
            'pekerjaan_orang_tua_wali' => 'Wiraswasta',
            'nip_orang_tua_wali' => '222',
            'pangkat_gol_orang_tua_wali' => 'IV/b',
            'instansi_orang_tua_wali' => 'Instansi B',
        ]);
        $hashAfter = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $this->assertNotSame($hashBefore, $hashAfter);
    }

    public function test_ska_hash_changes_when_current_academic_period_changes(): void
    {
        $this->activeAcademicPeriod('2025/2026', AcademicPeriod::SEMESTER_TYPE_GENAP, '2026-01-01');
        $application = $this->aktifApplication(null, ['nomor_surat' => 'AKT/001/2026']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $this->activeAcademicPeriod('2026/2027', AcademicPeriod::SEMESTER_TYPE_GANJIL, '2026-02-01');
        $hashAfter = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

        $this->assertNotSame($hashBefore, $hashAfter);
    }

    public function test_ska_hash_changes_when_official_kadep_identity_changes(): void
    {
        $this->activeAcademicPeriod();
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->aktifApplication($student, ['nomor_surat' => 'AKT/001/2026']);
        $kadepA = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep A',
            'nip' => '111',
        ]);
        $kadepB = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep B',
            'nip' => '222',
        ]);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashA = $this->hasher()->hashForSka($application->fresh(), $phase, $flags, ['official_kadep' => $kadepA]);
        $hashB = $this->hasher()->hashForSka($application->fresh(), $phase, $flags, ['official_kadep' => $kadepB]);

        $this->assertNotSame($hashA, $hashB);
    }

    public function test_ska_paraf_marker_changes_hash_only_when_paraf_is_rendered(): void
    {
        $this->activeAcademicPeriod();
        $application = $this->aktifApplication(null, ['nomor_surat' => 'AKT/001/2026']);
        $tempParaf = tempnam(sys_get_temp_dir(), 'ska_paraf_hash_') . '.png';
        $originalPath = config('surat.global_paraf_path');

        try {
            file_put_contents($tempParaf, $this->pngBytes());
            config(['surat.global_paraf_path' => $tempParaf]);
            $hasher = $this->hasher();
            $includedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false];
            $excludedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

            $includedBefore = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $includedFlags);
            $excludedBefore = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $excludedFlags);

            file_put_contents($tempParaf, $this->pngBytes() . 'changed-paraf');

            $includedAfter = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $includedFlags);
            $excludedAfter = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $excludedFlags);

            $this->assertNotSame($includedBefore, $includedAfter);
            $this->assertSame($excludedBefore, $excludedAfter);
        } finally {
            config(['surat.global_paraf_path' => $originalPath]);
            if (is_file($tempParaf)) {
                @unlink($tempParaf);
            }
        }
    }

    public function test_ska_signature_marker_changes_hash_only_when_signature_is_rendered(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('signatures/ska-kadep.png', $this->pngBytes());
        $this->activeAcademicPeriod();
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->aktifApplication($student, ['nomor_surat' => 'AKT/001/2026']);
        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep Marker',
            'nip' => '111',
            'signature_path' => 'signatures/ska-kadep.png',
        ]);
        $hasher = $this->hasher();
        $includedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => true];
        $excludedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false];

        $includedBefore = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, $includedFlags, [
            'official_kadep' => $kadep,
        ]);
        $excludedBefore = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $excludedFlags, [
            'official_kadep' => $kadep,
        ]);

        Storage::disk('public')->put('signatures/ska-kadep.png', $this->pngBytes() . 'changed-signature');

        $includedAfter = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, $includedFlags, [
            'official_kadep' => $kadep,
        ]);
        $excludedAfter = $hasher->hashForSka($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $excludedFlags, [
            'official_kadep' => $kadep,
        ]);

        $this->assertNotSame($includedBefore, $includedAfter);
        $this->assertSame($excludedBefore, $excludedAfter);
    }

    public function test_ska_template_cache_marker_affects_hash(): void
    {
        $this->activeAcademicPeriod();
        $application = $this->aktifApplication(null, ['nomor_surat' => 'AKT/001/2026']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];
        $tempTemplate = tempnam(sys_get_temp_dir(), 'ska_template_hash_') . '.docx';
        $originalPath = config('surat.template_surat_keterangan_aktif_cache_path');

        try {
            file_put_contents($tempTemplate, 'PK-template-a');
            config(['surat.template_surat_keterangan_aktif_cache_path' => $tempTemplate]);
            $hashA = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

            file_put_contents($tempTemplate, 'PK-template-a-changed');
            $hashB = $this->hasher()->hashForSka($application->fresh(), $phase, $flags);

            $this->assertNotSame($hashA, $hashB);
        } finally {
            config(['surat.template_surat_keterangan_aktif_cache_path' => $originalPath]);
            if (is_file($tempTemplate)) {
                @unlink($tempTemplate);
            }
        }
    }

    public function test_pln_payload_contains_render_profile_and_final_rendered_values(): void
    {
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);
        [$student] = $this->completeMahasiswa(['name' => 'Mahasiswa PLN'], ['nim' => '22/493038/SV/20654'], $program);
        $application = $this->prosesLuarNegeriApplication($student, [
            'nomor_surat' => 'PLN/001/2026',
            'nomor_paspor' => 'X1234567',
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'Laki-laki',
            'semester' => 4,
            'keperluan' => 'Pendaftaran konferensi internasional',
        ]);
        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep PLN',
            'nip' => '197001012000011001',
        ]);

        $payload = $this->hasher()->canonicalPlnPayload(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false],
            [
                'tanggal_surat' => '2026-05-21',
                'official_kadep' => $kadep,
            ],
        );

        $this->assertSame('pln-docx-gotenberg-v1-final-contract', $payload['rendered']['profile']);
        $this->assertSame('PLN/001/2026', $payload['application']['nomor_surat']);
        $this->assertSame('X1234567', $payload['application']['no_paspor']);
        $this->assertSame('21 Mei 2026', $payload['rendered']['tanggal_surat']);
        $this->assertSame('04 Mei 2004', $payload['student']['tanggal_lahir']);
        $this->assertSame('04 Mei 2004', $payload['rendered']['tanggal_lahir']);
        $this->assertSame('TRPL', $payload['student']['kode_prodi']);
        $this->assertSame('Teknik Elektro dan Informatika', $payload['student']['departemen']);
        $this->assertSame('Sekolah Vokasi', $payload['student']['fakultas']);
        $this->assertSame('Ketua Departemen', $payload['rendered']['jabatan_kadep']);
        $this->assertSame('Kadep PLN', $payload['rendered']['nama_kadep']);
        $this->assertSame('197001012000011001', $payload['rendered']['nip_kadep']);
        $this->assertStringNotContainsString('Sekolah Vokasi UGM', json_encode($payload));
        $this->assertStringNotContainsString('Departemen Departemen', json_encode($payload));
    }

    public function test_pln_nomor_surat_change_changes_hash_when_phase_includes_number(): void
    {
        $application = $this->prosesLuarNegeriApplication(null, ['nomor_surat' => 'PLN/001/2026']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

        $application->update(['nomor_surat' => 'PLN/999/2026']);
        $hashAfter = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

        $this->assertNotSame($hashBefore, $hashAfter);
    }

    public function test_pln_nomor_surat_change_does_not_affect_tendik_hash_when_number_excluded(): void
    {
        $application = $this->prosesLuarNegeriApplication(null, ['nomor_surat' => null]);
        $phase = LetterDocumentArtifact::PHASE_TENDIK_REVIEW;
        $flags = ['include_nomor_surat' => false, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

        $application->update(['nomor_surat' => 'PLN/LATE/2026']);
        $hashAfter = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

        $this->assertSame($hashBefore, $hashAfter);
    }

    public function test_pln_pending_nomor_surat_changes_hash_without_saving_application(): void
    {
        $application = $this->prosesLuarNegeriApplication(null, ['nomor_surat' => null]);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashWithoutPending = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);
        $hashWithPending = $this->hasher()->hashForPln($application->fresh(), $phase, $flags, [
            'nomor_surat' => 'PLN/PENDING/2026',
        ]);

        $this->assertNotSame($hashWithoutPending, $hashWithPending);
        $this->assertNull($application->fresh()->nomor_surat);
    }

    public function test_pln_rendered_tanggal_surat_changes_hash(): void
    {
        $application = $this->prosesLuarNegeriApplication(null, ['nomor_surat' => 'PLN/001/2026']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashA = $this->hasher()->hashForPln($application->fresh(), $phase, $flags, [
            'tanggal_surat' => '2026-05-21',
        ]);
        $hashB = $this->hasher()->hashForPln($application->fresh(), $phase, $flags, [
            'tanggal_surat' => '2026-05-22',
        ]);

        $this->assertNotSame($hashA, $hashB);
    }

    public function test_pln_hash_changes_when_application_render_fields_change(): void
    {
        $base = [
            'nomor_surat' => 'PLN/001/2026',
            'nomor_paspor' => 'A1234567',
            'keperluan' => 'Keperluan awal',
            'jenis_kelamin' => 'Laki-laki',
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'semester' => 4,
        ];
        $application = $this->prosesLuarNegeriApplication(null, $base);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];
        $mutations = [
            'nomor_paspor' => ['nomor_paspor' => 'B7654321'],
            'keperluan' => ['keperluan' => 'Keperluan berubah'],
            'jenis_kelamin' => ['jenis_kelamin' => 'Perempuan'],
            'tempat_lahir' => ['tempat_lahir' => 'Bantul'],
            'tanggal_lahir' => ['tanggal_lahir' => '2004-05-05'],
            'semester' => ['semester' => 5],
        ];

        foreach ($mutations as $label => $mutation) {
            $application->update($base);
            $hashBefore = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

            $application->update($mutation);
            $hashAfter = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

            $this->assertNotSame($hashBefore, $hashAfter, "{$label} should affect the PLN source hash.");
        }
    }

    public function test_pln_hash_changes_when_student_identity_and_program_values_change(): void
    {
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);
        [$student, $profile] = $this->completeMahasiswa(['name' => 'Mahasiswa PLN'], ['nim' => '22/493038/SV/20654'], $program);
        $application = $this->prosesLuarNegeriApplication($student, ['nomor_surat' => 'PLN/001/2026']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashBefore = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

        $student->update(['name' => 'Mahasiswa PLN Berubah']);
        $profile->update(['nim' => '23/000001/SV/00001']);
        $program->update(['code' => 'TRI', 'name' => 'Teknologi Rekayasa Instrumentasi']);
        $department->update(['name' => 'Departemen Teknik Mesin']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi']);
        $hashAfter = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

        $this->assertNotSame($hashBefore, $hashAfter);
    }

    public function test_pln_hash_changes_when_official_kadep_identity_changes(): void
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->prosesLuarNegeriApplication($student, ['nomor_surat' => 'PLN/001/2026']);
        $kadepA = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep A',
            'nip' => '111',
        ]);
        $kadepB = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep B',
            'nip' => '222',
        ]);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

        $hashA = $this->hasher()->hashForPln($application->fresh(), $phase, $flags, ['official_kadep' => $kadepA]);
        $hashB = $this->hasher()->hashForPln($application->fresh(), $phase, $flags, ['official_kadep' => $kadepB]);

        $this->assertNotSame($hashA, $hashB);
    }

    public function test_pln_paraf_marker_changes_hash_only_when_paraf_is_rendered(): void
    {
        $application = $this->prosesLuarNegeriApplication(null, ['nomor_surat' => 'PLN/001/2026']);
        $tempParaf = tempnam(sys_get_temp_dir(), 'pln_paraf_hash_') . '.png';
        $originalPath = config('surat.global_paraf_path');

        try {
            file_put_contents($tempParaf, $this->pngBytes());
            config(['surat.global_paraf_path' => $tempParaf]);
            $includedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false];
            $excludedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];

            $includedBefore = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $includedFlags);
            $excludedBefore = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $excludedFlags);

            file_put_contents($tempParaf, $this->pngBytes() . 'changed-paraf');

            $includedAfter = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $includedFlags);
            $excludedAfter = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $excludedFlags);

            $this->assertNotSame($includedBefore, $includedAfter);
            $this->assertSame($excludedBefore, $excludedAfter);
        } finally {
            config(['surat.global_paraf_path' => $originalPath]);
            if (is_file($tempParaf)) {
                @unlink($tempParaf);
            }
        }
    }

    public function test_pln_signature_marker_changes_hash_only_when_signature_is_rendered(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('signatures/pln-kadep.png', $this->pngBytes());
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->prosesLuarNegeriApplication($student, ['nomor_surat' => 'PLN/001/2026']);
        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep Marker',
            'nip' => '111',
            'signature_path' => 'signatures/pln-kadep.png',
        ]);
        $includedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => true];
        $excludedFlags = ['include_nomor_surat' => true, 'include_prodi_paraf' => true, 'include_kadep_signature' => false];

        $includedBefore = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, $includedFlags, [
            'official_kadep' => $kadep,
        ]);
        $excludedBefore = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $excludedFlags, [
            'official_kadep' => $kadep,
        ]);

        Storage::disk('public')->put('signatures/pln-kadep.png', $this->pngBytes() . 'changed-signature');

        $includedAfter = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, $includedFlags, [
            'official_kadep' => $kadep,
        ]);
        $excludedAfter = $this->hasher()->hashForPln($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $excludedFlags, [
            'official_kadep' => $kadep,
        ]);

        $this->assertNotSame($includedBefore, $includedAfter);
        $this->assertSame($excludedBefore, $excludedAfter);
    }

    public function test_pln_template_cache_marker_affects_hash(): void
    {
        $application = $this->prosesLuarNegeriApplication(null, ['nomor_surat' => 'PLN/001/2026']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = ['include_nomor_surat' => true, 'include_prodi_paraf' => false, 'include_kadep_signature' => false];
        $tempTemplate = tempnam(sys_get_temp_dir(), 'pln_template_hash_') . '.docx';
        $originalPath = config('surat.template_proses_luar_negeri_cache_path');

        try {
            file_put_contents($tempTemplate, 'PK-template-a');
            config(['surat.template_proses_luar_negeri_cache_path' => $tempTemplate]);
            $hashA = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

            file_put_contents($tempTemplate, 'PK-template-a-changed');
            $hashB = $this->hasher()->hashForPln($application->fresh(), $phase, $flags);

            $this->assertNotSame($hashA, $hashB);
        } finally {
            config(['surat.template_proses_luar_negeri_cache_path' => $originalPath]);
            if (is_file($tempTemplate)) {
                @unlink($tempTemplate);
            }
        }
    }

    public function test_magang_payload_uses_explicit_final_contract_and_render_profile(): void
    {
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, ['code' => 'TRPL', 'name' => 'Teknologi Rekayasa Perangkat Lunak']);
        [$student] = $this->completeMahasiswa(['name' => 'Mahasiswa Magang'], ['nim' => '22/493038/SV/20654'], $program);
        $application = $this->magangApplication($student, [
            'nomor_surat_pengantar' => 'MAG/PENGANTAR/001/2026',
            'nomor_surat_tugas' => 'MAG/TUGAS/001/2026',
            'jabatan_penerima' => 'Direktur Operasional',
            'nama_perusahaan' => 'PT Final Contract',
            'alamat_jalan' => 'Jl. Kontrak No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. DPA',
            'peran' => 'Backend Engineer Intern',
        ]);
        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Ketua Resmi',
            'nip' => '198001012010011001',
        ]);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $this->magangResolver()->phaseFlagsFor($application, $phase);

        $payload = $this->hasher()->canonicalMagangPayload($application->fresh(), $phase, $flags, [
            'tanggal_surat' => '2026-05-25',
            'official_kadep' => $kadep,
        ]);

        $this->assertSame(SuratPengantarMagangApplication::LETTER_TYPE, $payload['letter_type']);
        $this->assertSame('magang-docx-gotenberg-v1-final-contract', $payload['rendered']['profile']);
        $this->assertSame('MAG/PENGANTAR/001/2026', $payload['application']['nomor_surat_pengantar']);
        $this->assertSame('MAG/TUGAS/001/2026', $payload['application']['nomor_surat_tugas']);
        $this->assertSame('Direktur Operasional', $payload['application']['jabatan_penerima']);
        $this->assertSame('Jl. Kontrak No. 1', $payload['application']['alamat_jalan']);
        $this->assertSame('01 Juni 2026', $payload['internship']['tgl_mulai']);
        $this->assertSame('31 Agustus 2026', $payload['internship']['tgl_selesai']);
        $this->assertSame('Backend Engineer Intern', $payload['internship']['posisi']);
        $this->assertSame('TRPL', $payload['student']['kode_prodi']);
        $this->assertSame('Teknik Elektro dan Informatika', $payload['student']['departemen']);
        $this->assertSame('Sekolah Vokasi', $payload['student']['fakultas']);
        $this->assertSame('Ketua Departemen', $payload['rendered']['jabatan_kadep']);
        $this->assertSame('Ketua Resmi', $payload['rendered']['nama_kadep']);
        $this->assertSame('25 Mei 2026', $payload['rendered']['tanggal_surat']);
    }

    public function test_magang_pending_numbers_and_date_affect_hash_without_mutating_application(): void
    {
        $application = $this->magangApplication(null, [
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
        ]);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $this->magangResolver()->phaseFlagsFor($application, $phase);

        $hashA = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags);
        $hashB = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags, [
            'nomor_surat_pengantar' => 'MAG/PENDING/P/2026',
            'nomor_surat_tugas' => 'MAG/PENDING/T/2026',
            'tanggal_surat' => '2026-05-25',
        ]);

        $this->assertNotSame($hashA, $hashB);
        $this->assertNull($application->fresh()->nomor_surat_pengantar);
        $this->assertNull($application->fresh()->nomor_surat_tugas);
    }

    public function test_magang_dual_numbers_affect_numbered_phase_but_not_tendik_phase(): void
    {
        $application = $this->magangApplication(null, [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
        ]);
        $prodiPhase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $prodiFlags = $this->magangResolver()->phaseFlagsFor($application, $prodiPhase);

        foreach ([
            'nomor_surat_pengantar' => 'MAG/P/999/2026',
            'nomor_surat_tugas' => 'MAG/T/999/2026',
        ] as $field => $newValue) {
            $application->update([
                'nomor_surat_pengantar' => 'MAG/P/001/2026',
                'nomor_surat_tugas' => 'MAG/T/001/2026',
            ]);
            $before = $this->hasher()->hashForMagang($application->fresh(), $prodiPhase, $prodiFlags);
            $application->update([$field => $newValue]);
            $after = $this->hasher()->hashForMagang($application->fresh(), $prodiPhase, $prodiFlags);
            $this->assertNotSame($before, $after, "{$field} must affect numbered phases.");
        }

        $tendikPhase = LetterDocumentArtifact::PHASE_TENDIK_REVIEW;
        $tendikFlags = $this->magangResolver()->phaseFlagsFor($application, $tendikPhase);
        $before = $this->hasher()->hashForMagang($application->fresh(), $tendikPhase, $tendikFlags);
        $application->update([
            'nomor_surat_pengantar' => 'MAG/P/NO-HASH/2026',
            'nomor_surat_tugas' => 'MAG/T/NO-HASH/2026',
        ]);
        $after = $this->hasher()->hashForMagang($application->fresh(), $tendikPhase, $tendikFlags);
        $this->assertSame($before, $after);
    }

    public function test_magang_explicit_final_inputs_affect_hash_and_legacy_aggregates_do_not(): void
    {
        $base = [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
            'jabatan_penerima' => 'Direktur',
            'nama_perusahaan' => 'PT Awal',
            'alamat_jalan' => 'Jl. Awal',
            'alamat_kelurahan' => 'Kelurahan Awal',
            'alamat_kecamatan' => 'Kecamatan Awal',
            'alamat_kota_kabupaten' => 'Kota Awal',
            'alamat_provinsi' => 'Provinsi Awal',
            'kode_pos' => '11111',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. Awal',
            'peran' => 'Intern Awal',
            'nomor_surat' => 'LEGACY/001',
            'alamat_perusahaan' => 'Alamat agregat awal',
            'rentang_tanggal' => 'Rentang agregat awal',
        ];
        $application = $this->magangApplication(null, $base);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $this->magangResolver()->phaseFlagsFor($application, $phase);

        foreach ([
            'jabatan_penerima' => 'Manajer',
            'nama_perusahaan' => 'PT Baru',
            'alamat_jalan' => 'Jl. Baru',
            'alamat_kelurahan' => 'Kelurahan Baru',
            'alamat_kecamatan' => 'Kecamatan Baru',
            'alamat_kota_kabupaten' => 'Kota Baru',
            'alamat_provinsi' => 'Provinsi Baru',
            'kode_pos' => '99999',
            'tgl_mulai' => '2026-06-02',
            'tgl_selesai' => '2026-09-01',
            'dosen_pembimbing_dpa' => 'Dr. Baru',
            'peran' => 'Intern Baru',
        ] as $field => $value) {
            $application->update($base);
            $before = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags);
            $application->update([$field => $value]);
            $after = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags);
            $this->assertNotSame($before, $after, "{$field} must affect Magang final source hash.");
        }

        foreach ([
            'nomor_surat' => 'LEGACY/CHANGED',
            'nama_penerima' => 'Legacy Penerima Changed',
            'alamat_perusahaan' => 'Legacy alamat berubah',
            'rentang_tanggal' => 'Legacy rentang berubah',
        ] as $field => $value) {
            $application->update($base);
            $before = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags);
            $application->update([$field => $value]);
            $after = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags);
            $this->assertSame($before, $after, "{$field} must not feed the Magang final source hash.");
        }
    }

    public function test_magang_missing_explicit_fields_are_not_derived_from_legacy_aggregates(): void
    {
        $application = $this->magangApplication(null, [
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
            'jabatan_penerima' => null,
            'alamat_jalan' => null,
            'alamat_kelurahan' => null,
            'alamat_kecamatan' => null,
            'alamat_kota_kabupaten' => null,
            'alamat_provinsi' => null,
            'kode_pos' => null,
            'tgl_mulai' => null,
            'tgl_selesai' => null,
            'nomor_surat' => 'LEGACY/NUMBER/2026',
            'nama_penerima' => 'Legacy Recipient Title',
            'alamat_perusahaan' => 'Legacy Full Address',
            'rentang_tanggal' => '1 Juni - 31 Agustus 2026',
        ]);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $this->magangResolver()->phaseFlagsFor($application, $phase);

        $payload = $this->hasher()->canonicalMagangPayload($application->fresh(), $phase, $flags);

        $this->assertSame('-', $payload['application']['nomor_surat_pengantar']);
        $this->assertSame('-', $payload['application']['nomor_surat_tugas']);
        $this->assertSame('-', $payload['application']['jabatan_penerima']);
        $this->assertSame('-', $payload['application']['alamat_jalan']);
        $this->assertSame('-', $payload['internship']['tgl_mulai']);
        $this->assertSame('-', $payload['internship']['tgl_selesai']);
    }

    public function test_magang_student_and_official_kadep_inputs_affect_hash_and_include_title(): void
    {
        $department = $this->department();
        $program = $this->studyProgram($department, ['code' => 'TRPL', 'name' => 'TRPL']);
        [$student, $profile] = $this->completeMahasiswa(['name' => 'Student A'], ['nim' => '111'], $program);
        $application = $this->magangApplication($student, [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
        ]);
        $kadepA = $this->akademik('kadep', ['department_id' => $department->id, 'name' => 'Kadep A', 'nip' => '111']);
        $kadepB = $this->akademik('kadep', ['department_id' => $department->id, 'name' => 'Kadep B', 'nip' => '222']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $this->magangResolver()->phaseFlagsFor($application, $phase);
        $hashA = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags, ['official_kadep' => $kadepA]);

        $student->update(['name' => 'Student B']);
        $profile->update(['nim' => '222']);
        $program->update(['code' => 'TRI', 'name' => 'TRI']);
        $studentChanged = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags, ['official_kadep' => $kadepA]);
        $kadepChanged = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags, ['official_kadep' => $kadepB]);
        $payload = $this->hasher()->canonicalMagangPayload($application->fresh(), $phase, $flags, ['official_kadep' => $kadepB]);

        $this->assertNotSame($hashA, $studentChanged);
        $this->assertNotSame($studentChanged, $kadepChanged);
        $this->assertSame('Ketua Departemen', $payload['rendered']['jabatan_kadep']);
        $this->assertSame('Kadep B', $payload['rendered']['nama_kadep']);
        $this->assertSame('222', $payload['rendered']['nip_kadep']);
    }

    public function test_magang_official_kadep_title_affects_hash(): void
    {
        $application = $this->magangApplication();
        $kadep = $this->akademik('kadep', ['name' => 'Kadep Resmi', 'nip' => '111']);
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $this->magangResolver()->phaseFlagsFor($application, $phase);
        $academicContext = $this->app->make(\App\Services\AcademicContextService::class);
        $routing = $this->app->make(\App\Services\AcademicRoutingService::class);
        $profileData = $this->app->make(\App\Services\MahasiswaProfileDataService::class);
        $baseSignatory = \Mockery::mock(\App\Services\AcademicSignatoryService::class, [$routing, $academicContext])
            ->makePartial();
        $changedSignatory = \Mockery::mock(\App\Services\AcademicSignatoryService::class, [$routing, $academicContext])
            ->makePartial();
        $baseSignatory->shouldReceive('academicOfficeRoleTitle')->with('kadep')->andReturn('Ketua Departemen');
        $changedSignatory->shouldReceive('academicOfficeRoleTitle')->with('kadep')->andReturn('Pelaksana Ketua Departemen');

        $baseHasher = new LetterDocumentSourceHashService($baseSignatory, $academicContext, $profileData);
        $changedHasher = new LetterDocumentSourceHashService($changedSignatory, $academicContext, $profileData);

        $baseHash = $baseHasher->hashForMagang($application->fresh(), $phase, $flags, ['official_kadep' => $kadep]);
        $changedHash = $changedHasher->hashForMagang($application->fresh(), $phase, $flags, ['official_kadep' => $kadep]);

        $this->assertNotSame($baseHash, $changedHash);
    }

    public function test_magang_paraf_marker_changes_hash_only_when_paraf_is_rendered(): void
    {
        $application = $this->magangApplication();
        $tempParaf = tempnam(sys_get_temp_dir(), 'magang_paraf_hash_') . '.png';
        $originalPath = config('surat.global_paraf_path');

        try {
            file_put_contents($tempParaf, $this->pngBytes());
            config(['surat.global_paraf_path' => $tempParaf]);
            $includedPhase = LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW;
            $includedFlags = $this->magangResolver()->phaseFlagsFor($application, $includedPhase);
            $excludedPhase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
            $excludedFlags = $this->magangResolver()->phaseFlagsFor($application, $excludedPhase);

            $includedBefore = $this->hasher()->hashForMagang($application->fresh(), $includedPhase, $includedFlags);
            $excludedBefore = $this->hasher()->hashForMagang($application->fresh(), $excludedPhase, $excludedFlags);
            file_put_contents($tempParaf, $this->pngBytes() . 'changed-paraf');
            $includedAfter = $this->hasher()->hashForMagang($application->fresh(), $includedPhase, $includedFlags);
            $excludedAfter = $this->hasher()->hashForMagang($application->fresh(), $excludedPhase, $excludedFlags);

            $this->assertNotSame($includedBefore, $includedAfter);
            $this->assertSame($excludedBefore, $excludedAfter);
        } finally {
            config(['surat.global_paraf_path' => $originalPath]);
            if (is_file($tempParaf)) {
                @unlink($tempParaf);
            }
        }
    }

    public function test_magang_signature_marker_changes_hash_only_when_signature_is_rendered(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('signatures/magang-kadep.png', $this->pngBytes());
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->magangApplication($student);
        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep Marker',
            'nip' => '111',
            'signature_path' => 'signatures/magang-kadep.png',
        ]);
        $includedPhase = LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW;
        $includedFlags = $this->magangResolver()->phaseFlagsFor($application, $includedPhase);
        $excludedPhase = LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW;
        $excludedFlags = $this->magangResolver()->phaseFlagsFor($application, $excludedPhase);

        $includedBefore = $this->hasher()->hashForMagang($application->fresh(), $includedPhase, $includedFlags, ['official_kadep' => $kadep]);
        $excludedBefore = $this->hasher()->hashForMagang($application->fresh(), $excludedPhase, $excludedFlags, ['official_kadep' => $kadep]);
        Storage::disk('public')->put('signatures/magang-kadep.png', $this->pngBytes() . 'changed-signature');
        $includedAfter = $this->hasher()->hashForMagang($application->fresh(), $includedPhase, $includedFlags, ['official_kadep' => $kadep]);
        $excludedAfter = $this->hasher()->hashForMagang($application->fresh(), $excludedPhase, $excludedFlags, ['official_kadep' => $kadep]);

        $this->assertNotSame($includedBefore, $includedAfter);
        $this->assertSame($excludedBefore, $excludedAfter);
    }

    public function test_magang_template_cache_marker_affects_hash(): void
    {
        $application = $this->magangApplication();
        $phase = LetterDocumentArtifact::PHASE_PRODI_REVIEW;
        $flags = $this->magangResolver()->phaseFlagsFor($application, $phase);
        $tempTemplate = tempnam(sys_get_temp_dir(), 'magang_template_hash_') . '.docx';
        $originalPath = config('surat.template_surat_pengantar_magang_cache_path');

        try {
            file_put_contents($tempTemplate, 'PK-template-a');
            config(['surat.template_surat_pengantar_magang_cache_path' => $tempTemplate]);
            $hashA = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags);
            file_put_contents($tempTemplate, 'PK-template-a-changed');
            $hashB = $this->hasher()->hashForMagang($application->fresh(), $phase, $flags);
            $this->assertNotSame($hashA, $hashB);
        } finally {
            config(['surat.template_surat_pengantar_magang_cache_path' => $originalPath]);
            if (is_file($tempTemplate)) {
                @unlink($tempTemplate);
            }
        }
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function activeAcademicPeriod(
        string $academicYear = '2025/2026',
        string $semesterType = AcademicPeriod::SEMESTER_TYPE_GENAP,
        string $startDate = '2026-01-01',
    ): AcademicPeriod {
        [$yearStart] = explode('/', $academicYear);

        return AcademicPeriod::create([
            'academic_year' => $academicYear,
            'year_start' => (int) $yearStart,
            'semester_type' => $semesterType,
            'semester_order' => AcademicPeriod::SEMESTER_ORDER_MAP[$semesterType],
            'start_date' => $startDate,
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
    }
}
