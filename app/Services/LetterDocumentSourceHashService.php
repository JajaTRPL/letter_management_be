<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic SHA-256 of the canonical inputs that affect a letter
 * rendered DOCX. Same inputs -> same hash, regardless of unrelated DB
 * metadata (timestamps, audit columns) or related-row ordering.
 *
 * Used by callers to short-circuit re-conversion when the source has not
 * changed since the last READY artifact for the same phase.
 */
class LetterDocumentSourceHashService
{
    private const RENDER_PROFILE = 'beasiswa-signature-layout-v5-nomor-surat-rekomendasi-alias-pasfoto-normalized-600x800-q90';
    private const SKA_RENDER_PROFILE = 'ska-docx-gotenberg-v1-final-contract';
    private const PLN_RENDER_PROFILE = 'pln-docx-gotenberg-v1-final-contract';
    private const MAGANG_RENDER_PROFILE = 'magang-docx-gotenberg-v1-final-contract';

    public function __construct(
        private ?AcademicSignatoryService $signatoryService = null,
        private ?AcademicContextService $academicContextService = null,
        private ?MahasiswaProfileDataService $profileDataService = null,
    ) {
        $this->signatoryService ??= app(AcademicSignatoryService::class);
        $this->academicContextService ??= app(AcademicContextService::class);
        $this->profileDataService ??= app(MahasiswaProfileDataService::class);
    }

    /**
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     */
    public function hashForBeasiswa(
        ScholarshipApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): string
    {
        $payload = $this->canonicalBeasiswaPayload($application, $phase, $phaseFlags, $pendingOverrides);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode Beasiswa source-hash payload.');
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     */
    public function hashForSka(
        SuratKeteranganAktifApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): string {
        $payload = $this->canonicalSkaPayload($application, $phase, $phaseFlags, $pendingOverrides);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode SKA source-hash payload.');
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     */
    public function hashForPln(
        ProsesLuarNegeriApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): string {
        $payload = $this->canonicalPlnPayload($application, $phase, $phaseFlags, $pendingOverrides);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode PLN source-hash payload.');
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array{
     *     include_nomor_pengantar: bool,
     *     include_nomor_tugas: bool,
     *     include_paraf_pengantar: bool,
     *     include_paraf_tugas: bool,
     *     include_kadep_ttd_pengantar: bool,
     *     include_kadep_ttd_tugas: bool
     * } $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     */
    public function hashForMagang(
        SuratPengantarMagangApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): string {
        $payload = $this->canonicalMagangPayload($application, $phase, $phaseFlags, $pendingOverrides);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode Magang source-hash payload.');
        }

        return hash('sha256', $encoded);
    }

    /**
     * Canonical payload exposed for testing. Keys are sorted; related rows are
     * sorted by stable identity then content; image identities are referenced
     * by stored path plus lightweight file markers, never raw bytes.
     *
     * @param array<string, mixed> $pendingOverrides
     * @return array<string, mixed>
     */
    public function canonicalBeasiswaPayload(
        ScholarshipApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): array
    {
        $application = $this->applicationSnapshot($application, $pendingOverrides);
        $application->loadMissing([
            'mahasiswaProfile.keluarga',
            'mahasiswaProfile.scholarshipHistories',
            'user.studyProgram.department.faculty',
            'user.department.faculty',
        ]);

        $profile = $application->getRelationValue('mahasiswaProfile');
        $user = $application->getRelationValue('user');

        $studyProgram = $user?->getRelationValue('studyProgram');
        $department = $studyProgram?->getRelationValue('department')
            ?? $user?->getRelationValue('department');
        $faculty = $department?->getRelationValue('faculty');

        // Family rows: sort by (jenis_relasi, id) for stable ordering.
        $family = collect($profile?->getRelationValue('keluarga') ?? [])
            ->sortBy(fn ($k) => [
                (string) ($k->getAttribute('jenis_relasi') ?? ''),
                (int) ($k->getAttribute('id') ?? 0),
            ])
            ->values()
            ->map(fn ($k) => [
                'jenis_relasi' => $k->getAttribute('jenis_relasi'),
                'nama_lengkap' => $k->getAttribute('nama_lengkap'),
                'pekerjaan' => $k->getAttribute('pekerjaan'),
                'penghasilan' => $k->getAttribute('penghasilan'),
                'status_hidup' => $k->getAttribute('status_hidup'),
                'tanggal_meninggal' => $this->normalizeDate($k->getAttribute('tanggal_meninggal')),
                'status_kawin' => $k->getAttribute('status_kawin'),
                'keterangan' => $k->getAttribute('keterangan'),
            ])
            ->all();

        // Scholarship history rows: sort by (nama_beasiswa, periode, id).
        $histories = collect($profile?->getRelationValue('scholarshipHistories') ?? [])
            ->sortBy(fn ($h) => [
                (string) ($h->getAttribute('nama_beasiswa') ?? ''),
                (string) ($h->getAttribute('periode') ?? ''),
                (int) ($h->getAttribute('id') ?? 0),
            ])
            ->values()
            ->map(fn ($h) => [
                'nama_beasiswa' => $h->getAttribute('nama_beasiswa'),
                'periode' => $h->getAttribute('periode'),
                'jumlah' => $h->getAttribute('jumlah'),
                'status' => $h->getAttribute('status'),
            ])
            ->all();

        // Image markers include path + file metadata when a file is actually
        // rendered, so replacing an image at the same path invalidates previews.
        $officialKadep = $this->officialKadepForPayload($application, $pendingOverrides);
        $renderedKadepName = $officialKadep?->getAttribute('name') ?? '-';
        $renderedKadepNip = $this->signatoryService->nipLikeValue($officialKadep);
        $renderedKadepRoleTitle = $this->signatoryService->academicOfficeRoleTitle('kadep');
        $renderedDepartmentName = $this->signatoryService->academicOfficeUnitName(
            'kadep',
            $department?->getAttribute('name'),
        );
        $renderedKadepOfficeTitle = $this->signatoryService->formatAcademicOfficeTitle(
            'kadep',
            $department?->getAttribute('name'),
        );
        $kadepSignature = $phaseFlags['include_kadep_signature']
            ? $this->publicImageMarker($this->signatoryService->signaturePath($officialKadep), '(Tanda tangan belum tersedia)')
            : null;
        $paraf = $phaseFlags['include_prodi_paraf']
            ? $this->globalParafMarker()
            : null;
        $pasFoto = $this->publicImageMarker($profile?->getAttribute('pas_foto_path'), '(Tidak Ada)');
        $studentSignature = $this->publicImageMarker($profile?->getAttribute('tanda_tangan_path'), '(Tidak Ada)');

        $payload = [
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application' => [
                'id' => $application->getKey(),
                'scholarship_name' => $application->getAttribute('scholarship_name'),
                'study_level' => $application->getAttribute('study_level'),
                'current_semester' => $application->getAttribute('current_semester'),
                'family_dependents' => $application->getAttribute('family_dependents'),
                'gpa_last_2_semesters' => $application->getAttribute('gpa_last_2_semesters'),
                'ipk' => $application->getAttribute('ipk'),
                'sks_last_2_semesters' => $application->getAttribute('sks_last_2_semesters'),
                'total_sks_passed' => $application->getAttribute('total_sks_passed'),
                'total_sks_required' => $application->getAttribute('total_sks_required'),
                'on_leave' => $application->getAttribute('on_leave'),
                'leave_semester' => $application->getAttribute('leave_semester'),
                'thesis_status' => $application->getAttribute('thesis_status'),
                'exam_plan_date' => $this->normalizeDate($application->getAttribute('exam_plan_date')),
                'nomor_surat' => $phaseFlags['include_nomor_surat']
                    ? $application->getAttribute('nomor_surat')
                    : null,
            ],
            'profile' => $profile ? [
                'nim' => $profile->getAttribute('nim'),
                'nama_lengkap' => $profile->getAttribute('nama_lengkap'),
                'tempat_lahir' => $profile->getAttribute('tempat_lahir'),
                'tanggal_lahir' => $this->normalizeDate($profile->getAttribute('tanggal_lahir')),
                'jenis_kelamin' => $profile->getAttribute('jenis_kelamin'),
                'no_hp' => $profile->getAttribute('no_hp'),
                'alamat_asal' => $profile->getAttribute('alamat_asal'),
                'alamat_domisili' => $profile->getAttribute('alamat_domisili'),
                'pas_foto' => $pasFoto,
                'tanda_tangan' => $studentSignature,
            ] : null,
            'student' => $user ? [
                'name' => $user->getAttribute('name'),
                'email' => $user->getAttribute('email'),
            ] : null,
            'academic' => [
                'study_program_id' => $studyProgram?->getKey(),
                'study_program_name' => $studyProgram?->getAttribute('name'),
                'department_id' => $department?->getKey(),
                'department_name' => $department?->getAttribute('name'),
                'faculty_id' => $faculty?->getKey(),
                'faculty_name' => $faculty?->getAttribute('name'),
            ],
            'family' => $family,
            'histories' => $histories,
            'phase' => $phase,
            'phase_flags' => $this->normalizeFlags($phaseFlags),
            'rendered' => [
                'profile' => self::RENDER_PROFILE,
                'tanggal_surat' => $this->normalizeRenderedDate($pendingOverrides['tanggal_surat'] ?? null),
                'jabatan_kadep' => $renderedKadepRoleTitle,
                'departemen' => $renderedDepartmentName,
                'jabatan_unit_kadep' => $renderedKadepOfficeTitle,
                'nama_kadep' => $renderedKadepName,
                'nip_kadep' => $renderedKadepNip,
            ],
            'signatory' => [
                'kadep_signature' => $kadepSignature,
                'paraf' => $paraf,
            ],
            'template_marker' => $this->templateMarker(),
        ];

        return $this->sortRecursive($payload);
    }

    /**
     * Canonical SKA payload exposed for testing. This represents the final DOCX
     * placeholder contract and private artifact pipeline.
     *
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     * @return array<string, mixed>
     */
    public function canonicalSkaPayload(
        SuratKeteranganAktifApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): array {
        $application = $this->skaApplicationSnapshot($application, $pendingOverrides);
        $application->loadMissing([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'mahasiswaProfile',
        ]);

        $studentData = $this->profileDataService->forApplication($application);
        $officialKadep = $this->officialKadepForPayload($application, $pendingOverrides);
        $departmentDisplay = $this->renderText($studentData['department_display'] ?? null);
        $renderedDepartmentName = $this->signatoryService->academicOfficeUnitName('kadep', $departmentDisplay);
        $renderedKadepOfficeTitle = $this->signatoryService->formatAcademicOfficeTitle('kadep', $departmentDisplay);
        $renderedTanggalSurat = $this->normalizeRenderedDate(
            $pendingOverrides['tanggal_surat'] ?? $this->resolveSkaTanggalSurat($application, $phase),
        );
        $renderedPeriodeAkademik = $this->renderAcademicPeriod(
            $pendingOverrides['periode_akademik']
                ?? $pendingOverrides['academic_period']
                ?? $this->academicContextService->currentAcademicPeriod(),
        );

        $payload = [
            'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
            'application' => [
                'id' => $application->getKey(),
                'nomor_surat' => $phaseFlags['include_nomor_surat']
                    ? $this->renderText($application->getAttribute('nomor_surat'))
                    : null,
                'keperluan' => $this->renderText($application->getAttribute('keperluan')),
            ],
            'student' => [
                'nama' => $this->renderText($studentData['name'] ?? null),
                'nim' => $this->renderText($studentData['nim'] ?? null),
                'prodi' => $this->renderText($studentData['program_studi_display'] ?? null),
                'departemen' => $this->renderText($studentData['department_display'] ?? null),
                'fakultas' => $this->renderedFacultyName($studentData['fakultas_display'] ?? null),
                'study_program_id' => $studentData['study_program_id'] ?? null,
                'department_id' => $studentData['department_id'] ?? null,
                'faculty_id' => $studentData['faculty_id'] ?? null,
            ],
            'parent_guardian' => [
                'ot_nama' => $this->renderText($application->getAttribute('nama_orang_tua_wali')),
                'ot_kerja' => $this->renderText($application->getAttribute('pekerjaan_orang_tua_wali')),
                'ot_identitas' => $this->renderText($application->getAttribute('nip_orang_tua_wali')),
                'ot_pangkat_jabatan' => $this->renderText($application->getAttribute('pangkat_gol_orang_tua_wali')),
                'ot_instansi' => $this->renderText($application->getAttribute('instansi_orang_tua_wali')),
            ],
            'phase' => $phase,
            'phase_flags' => $this->normalizeFlags($phaseFlags),
            'rendered' => [
                'profile' => self::SKA_RENDER_PROFILE,
                'tanggal_surat' => $renderedTanggalSurat,
                'periode_akademik' => $renderedPeriodeAkademik,
                'jabatan_kadep' => $this->signatoryService->academicOfficeRoleTitle('kadep'),
                'departemen' => $renderedDepartmentName,
                'jabatan_unit_kadep' => $renderedKadepOfficeTitle,
                'fakultas' => $this->renderedFacultyName($studentData['fakultas_display'] ?? null),
                'nama_kadep' => $this->renderText($officialKadep?->getAttribute('name')),
                'nip_kadep' => $this->signatoryService->nipLikeValue($officialKadep),
            ],
            'signatory' => [
                'kadep_signature' => $phaseFlags['include_kadep_signature']
                    ? $this->publicImageMarker($this->signatoryService->signaturePath($officialKadep), '(Tanda tangan belum tersedia)')
                    : null,
                'paraf' => $phaseFlags['include_prodi_paraf']
                    ? $this->globalParafMarker()
                    : null,
            ],
            'template_marker' => $this->templateMarker('template_surat_keterangan_aktif_cache_path'),
        ];

        return $this->sortRecursive($payload);
    }

    /**
     * Canonical PLN payload exposed for testing. This represents the final DOCX
     * placeholder contract and future private artifact pipeline.
     *
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     * @return array<string, mixed>
     */
    public function canonicalPlnPayload(
        ProsesLuarNegeriApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): array {
        $application = $this->plnApplicationSnapshot($application, $pendingOverrides);
        $application->loadMissing([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'mahasiswaProfile',
        ]);

        $studentData = $this->profileDataService->forApplication($application);
        $officialKadep = $this->officialKadepForPayload($application, $pendingOverrides);
        $departmentDisplay = $this->renderText($studentData['department_display'] ?? null);
        $renderedDepartmentName = $this->signatoryService->academicOfficeUnitName('kadep', $departmentDisplay);
        $renderedKadepOfficeTitle = $this->signatoryService->formatAcademicOfficeTitle('kadep', $departmentDisplay);
        $renderedTanggalSurat = $this->normalizeRenderedDate(
            $pendingOverrides['tanggal_surat'] ?? $this->resolvePlnTanggalSurat($application, $phase),
        );
        $renderedTanggalLahir = $this->normalizeRenderedDate($application->getAttribute('tanggal_lahir'));
        $renderedFaculty = $this->renderedFacultyName($studentData['fakultas_display'] ?? null);

        $payload = [
            'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
            'application' => [
                'id' => $application->getKey(),
                'nomor_surat' => $phaseFlags['include_nomor_surat']
                    ? $this->renderText($application->getAttribute('nomor_surat'))
                    : null,
                'no_paspor' => $this->renderText($application->getAttribute('nomor_paspor')),
                'keperluan' => $this->renderText($application->getAttribute('keperluan')),
            ],
            'student' => [
                'nama' => $this->renderText($studentData['name'] ?? null),
                'jenis_kelamin' => $this->renderText($application->getAttribute('jenis_kelamin')),
                'tempat_lahir' => $this->renderText($application->getAttribute('tempat_lahir')),
                'tanggal_lahir' => $renderedTanggalLahir,
                'nim' => $this->renderText($studentData['nim'] ?? null),
                'semester' => $this->renderText($application->getAttribute('semester')),
                'prodi' => $this->renderText($studentData['program_studi_display'] ?? null),
                'kode_prodi' => $this->renderText($studentData['study_program_code'] ?? null),
                'departemen' => $renderedDepartmentName,
                'fakultas' => $renderedFaculty,
                'study_program_id' => $studentData['study_program_id'] ?? null,
                'department_id' => $studentData['department_id'] ?? null,
                'faculty_id' => $studentData['faculty_id'] ?? null,
            ],
            'phase' => $phase,
            'phase_flags' => $this->normalizeFlags($phaseFlags),
            'rendered' => [
                'profile' => self::PLN_RENDER_PROFILE,
                'tanggal_surat' => $renderedTanggalSurat,
                'tanggal_lahir' => $renderedTanggalLahir,
                'jabatan_kadep' => $this->signatoryService->academicOfficeRoleTitle('kadep'),
                'departemen' => $renderedDepartmentName,
                'jabatan_unit_kadep' => $renderedKadepOfficeTitle,
                'fakultas' => $renderedFaculty,
                'nama_kadep' => $this->renderText($officialKadep?->getAttribute('name')),
                'nip_kadep' => $this->signatoryService->nipLikeValue($officialKadep),
            ],
            'signatory' => [
                'kadep_signature' => $phaseFlags['include_kadep_signature']
                    ? $this->publicImageMarker($this->signatoryService->signaturePath($officialKadep), '(Tanda tangan belum tersedia)')
                    : null,
                'paraf' => $phaseFlags['include_prodi_paraf']
                    ? $this->globalParafMarker()
                    : null,
            ],
            'template_marker' => $this->templateMarker('template_proses_luar_negeri_cache_path'),
        ];

        return $this->sortRecursive($payload);
    }

    /**
     * Canonical Magang payload for the accepted two-section final DOCX
     * contract. Legacy aggregate aliases are deliberately not read here.
     *
     * @param array{
     *     include_nomor_pengantar: bool,
     *     include_nomor_tugas: bool,
     *     include_paraf_pengantar: bool,
     *     include_paraf_tugas: bool,
     *     include_kadep_ttd_pengantar: bool,
     *     include_kadep_ttd_tugas: bool
     * } $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     * @return array<string, mixed>
     */
    public function canonicalMagangPayload(
        SuratPengantarMagangApplication $application,
        string $phase,
        array $phaseFlags,
        array $pendingOverrides = [],
    ): array {
        $application = $this->magangApplicationSnapshot($application, $pendingOverrides);
        $application->loadMissing([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'mahasiswaProfile',
        ]);

        $studentData = $this->profileDataService->forApplication($application);
        $officialKadep = $this->officialKadepForPayload($application, $pendingOverrides);
        $departmentDisplay = $this->renderText($studentData['department_display'] ?? null);
        $renderedDepartmentName = $this->signatoryService->academicOfficeUnitName('kadep', $departmentDisplay);
        $renderedFaculty = $this->renderedFacultyName($studentData['fakultas_display'] ?? null);
        $renderedTanggalSurat = $this->normalizeRenderedDate(
            $pendingOverrides['tanggal_surat'] ?? $this->resolveMagangTanggalSurat($application, $phase),
        );
        $includesParaf = ($phaseFlags['include_paraf_pengantar'] ?? false)
            || ($phaseFlags['include_paraf_tugas'] ?? false);
        $includesKadepTtd = ($phaseFlags['include_kadep_ttd_pengantar'] ?? false)
            || ($phaseFlags['include_kadep_ttd_tugas'] ?? false);

        $payload = [
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'application' => [
                'id' => $application->getKey(),
                'nomor_surat_pengantar' => ($phaseFlags['include_nomor_pengantar'] ?? false)
                    ? $this->renderText($application->getAttribute('nomor_surat_pengantar'))
                    : null,
                'nomor_surat_tugas' => ($phaseFlags['include_nomor_tugas'] ?? false)
                    ? $this->renderText($application->getAttribute('nomor_surat_tugas'))
                    : null,
                'jabatan_penerima' => $this->renderText($application->getAttribute('jabatan_penerima')),
                'nama_perusahaan' => $this->renderText($application->getAttribute('nama_perusahaan')),
                'alamat_jalan' => $this->renderText($application->getAttribute('alamat_jalan')),
                'alamat_kelurahan' => $this->renderText($application->getAttribute('alamat_kelurahan')),
                'alamat_kecamatan' => $this->renderText($application->getAttribute('alamat_kecamatan')),
                'alamat_kota_kabupaten' => $this->renderText($application->getAttribute('alamat_kota_kabupaten')),
                'alamat_provinsi' => $this->renderText($application->getAttribute('alamat_provinsi')),
                'kode_pos' => $this->renderText($application->getAttribute('kode_pos')),
            ],
            'student' => [
                'nama' => $this->renderText($studentData['name'] ?? null),
                'nim' => $this->renderText($studentData['nim'] ?? null),
                'prodi' => $this->renderText($studentData['program_studi_display'] ?? null),
                'kode_prodi' => $this->renderText($studentData['study_program_code'] ?? null),
                'departemen' => $renderedDepartmentName,
                'fakultas' => $renderedFaculty,
                'study_program_id' => $studentData['study_program_id'] ?? null,
                'department_id' => $studentData['department_id'] ?? null,
                'faculty_id' => $studentData['faculty_id'] ?? null,
            ],
            'internship' => [
                'tgl_mulai' => $this->normalizeExplicitRenderedDate($application->getAttribute('tgl_mulai')),
                'tgl_selesai' => $this->normalizeExplicitRenderedDate($application->getAttribute('tgl_selesai')),
                'dpa' => $this->renderText($application->getAttribute('dosen_pembimbing_dpa')),
                'posisi' => $this->renderText($application->getAttribute('peran')),
            ],
            'phase' => $phase,
            'phase_flags' => $this->normalizeMagangFlags($phaseFlags),
            'rendered' => [
                'profile' => self::MAGANG_RENDER_PROFILE,
                'tanggal_surat' => $renderedTanggalSurat,
                'jabatan_kadep' => $this->signatoryService->academicOfficeRoleTitle('kadep'),
                'departemen' => $renderedDepartmentName,
                'fakultas' => $renderedFaculty,
                'nama_kadep' => $this->renderText($officialKadep?->getAttribute('name')),
                'nip_kadep' => $this->signatoryService->nipLikeValue($officialKadep),
            ],
            'signatory' => [
                'kadep_signature' => $includesKadepTtd
                    ? $this->publicImageMarker($this->signatoryService->signaturePath($officialKadep), 'missing:kadep_signature')
                    : null,
                'paraf' => $includesParaf
                    ? $this->magangGlobalParafMarker()
                    : null,
            ],
            'template_marker' => $this->templateMarker('template_surat_pengantar_magang_cache_path'),
        ];

        return $this->sortRecursive($payload);
    }

    private function normalizeFlags(array $flags): array
    {
        $known = ['include_nomor_surat', 'include_prodi_paraf', 'include_kadep_signature'];
        $out = [];
        foreach ($known as $k) {
            $out[$k] = (bool) ($flags[$k] ?? false);
        }
        ksort($out);

        return $out;
    }

    private function normalizeMagangFlags(array $flags): array
    {
        $known = [
            'include_nomor_pengantar',
            'include_nomor_tugas',
            'include_paraf_pengantar',
            'include_paraf_tugas',
            'include_kadep_ttd_pengantar',
            'include_kadep_ttd_tugas',
        ];
        $out = [];
        foreach ($known as $key) {
            $out[$key] = (bool) ($flags[$key] ?? false);
        }
        ksort($out);

        return $out;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d');
            } catch (\Throwable) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Stable template identity marker. Today we read the cached template path
     * mtime+size; if the cached template is refreshed (manual edit landing in
     * Phase 0.7 etc.), every artifact's hash flips and previews are regenerated
     * automatically. Falls back to a constant when the cache file is absent so
     * tests in environments without the cache stay deterministic.
     */
    private function templateMarker(string $cachePathConfigKey = 'template_beasiswa_cache_path'): string
    {
        $cachePath = config('surat.' . $cachePathConfigKey);
        if (is_string($cachePath) && is_file($cachePath)) {
            $size = (int) filesize($cachePath);
            $mtime = (int) filemtime($cachePath);

            return "cache:{$size}:{$mtime}";
        }

        return 'cache:absent';
    }

    private function normalizeRenderedDate(mixed $value = null): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        if ($value instanceof \DateTimeInterface) {
            $d = (int) $value->format('d');
            $m = (int) $value->format('n');
            $y = (int) $value->format('Y');
        } elseif ($value !== null) {
            $timestamp = strtotime((string) $value);
            if ($timestamp === false) {
                return '-';
            }
            $d = (int) date('d', $timestamp);
            $m = (int) date('n', $timestamp);
            $y = (int) date('Y', $timestamp);
        } else {
            $now = Carbon::now();
            $d = (int) $now->format('d');
            $m = (int) $now->month;
            $y = (int) $now->format('Y');
        }

        return sprintf('%02d', $d) . ' ' . $months[$m] . ' ' . $y;
    }

    private function normalizeExplicitRenderedDate(mixed $value): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return '-';
        }

        return $this->normalizeRenderedDate($value);
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    private function applicationSnapshot(ScholarshipApplication $application, array $pendingOverrides): ScholarshipApplication
    {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach (['nomor_surat'] as $attribute) {
            if (array_key_exists($attribute, $pendingOverrides)) {
                $snapshot->setAttribute($attribute, $pendingOverrides[$attribute]);
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    private function skaApplicationSnapshot(
        SuratKeteranganAktifApplication $application,
        array $pendingOverrides
    ): SuratKeteranganAktifApplication {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach ([
            'status',
            'nomor_surat',
            'submitted_at',
            'tendik_approved_at',
            'tendik_approved_by',
            'kaprodi_approved_at',
            'kaprodi_approved_by',
            'kadep_approved_at',
            'kadep_approved_by',
        ] as $attribute) {
            if (array_key_exists($attribute, $pendingOverrides)) {
                $snapshot->setAttribute($attribute, $pendingOverrides[$attribute]);
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    private function plnApplicationSnapshot(
        ProsesLuarNegeriApplication $application,
        array $pendingOverrides
    ): ProsesLuarNegeriApplication {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach ([
            'status',
            'nomor_surat',
            'submitted_at',
            'tendik_approved_at',
            'tendik_approved_by',
            'kaprodi_approved_at',
            'kaprodi_approved_by',
            'kadep_approved_at',
            'kadep_approved_by',
        ] as $attribute) {
            if (array_key_exists($attribute, $pendingOverrides)) {
                $snapshot->setAttribute($attribute, $pendingOverrides[$attribute]);
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    private function magangApplicationSnapshot(
        SuratPengantarMagangApplication $application,
        array $pendingOverrides
    ): SuratPengantarMagangApplication {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach ([
            'status',
            'nomor_surat_pengantar',
            'nomor_surat_tugas',
            'jabatan_penerima',
            'nama_perusahaan',
            'alamat_jalan',
            'alamat_kelurahan',
            'alamat_kecamatan',
            'alamat_kota_kabupaten',
            'alamat_provinsi',
            'kode_pos',
            'tgl_mulai',
            'tgl_selesai',
            'dosen_pembimbing_dpa',
            'peran',
            'submitted_at',
            'tendik_approved_at',
            'tendik_approved_by',
            'kaprodi_approved_at',
            'kaprodi_approved_by',
            'kadep_approved_at',
            'kadep_approved_by',
        ] as $attribute) {
            if (array_key_exists($attribute, $pendingOverrides)) {
                $snapshot->setAttribute($attribute, $pendingOverrides[$attribute]);
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    private function officialKadepForPayload(Model $application, array $pendingOverrides): ?User
    {
        foreach (['official_kadep', 'kadep_user'] as $key) {
            if (($pendingOverrides[$key] ?? null) instanceof User) {
                return $pendingOverrides[$key];
            }
        }

        return $this->signatoryService->officialKadepForApplication($application);
    }

    private function resolveSkaTanggalSurat(SuratKeteranganAktifApplication $application, string $phase): mixed
    {
        if ($phase === LetterDocumentArtifact::PHASE_TENDIK_REVIEW) {
            return $application->getAttribute('submitted_at')
                ?? $application->getAttribute('created_at')
                ?? Carbon::now();
        }

        return $application->getAttribute('tendik_approved_at')
            ?? $application->getAttribute('submitted_at')
            ?? $application->getAttribute('created_at')
            ?? Carbon::now();
    }

    private function resolvePlnTanggalSurat(ProsesLuarNegeriApplication $application, string $phase): mixed
    {
        if ($phase === LetterDocumentArtifact::PHASE_TENDIK_REVIEW) {
            return $application->getAttribute('submitted_at')
                ?? $application->getAttribute('created_at')
                ?? Carbon::now();
        }

        return $application->getAttribute('tendik_approved_at')
            ?? $application->getAttribute('submitted_at')
            ?? $application->getAttribute('created_at')
            ?? Carbon::now();
    }

    private function resolveMagangTanggalSurat(SuratPengantarMagangApplication $application, string $phase): mixed
    {
        if ($phase === LetterDocumentArtifact::PHASE_TENDIK_REVIEW) {
            return $application->getAttribute('submitted_at')
                ?? $application->getAttribute('created_at')
                ?? Carbon::now();
        }

        return $application->getAttribute('tendik_approved_at')
            ?? $application->getAttribute('submitted_at')
            ?? $application->getAttribute('created_at')
            ?? Carbon::now();
    }

    private function renderAcademicPeriod(mixed $period): string
    {
        if ($period instanceof AcademicPeriod) {
            $semesterType = $this->renderText($period->semester_type);
            $semesterType = $semesterType !== '-'
                ? mb_convert_case($semesterType, MB_CASE_TITLE, 'UTF-8')
                : '-';
            $academicYear = $this->renderText($period->academic_year);

            if ($semesterType === '-' && $academicYear === '-') {
                return '-';
            }

            return trim("Semester {$semesterType} Tahun Akademik {$academicYear}");
        }

        if (is_array($period)) {
            $semesterType = $this->renderText($period['semester_type'] ?? null);
            $semesterType = $semesterType !== '-'
                ? mb_convert_case($semesterType, MB_CASE_TITLE, 'UTF-8')
                : '-';
            $academicYear = $this->renderText($period['academic_year'] ?? null);

            if ($semesterType === '-' && $academicYear === '-') {
                return '-';
            }

            return trim("Semester {$semesterType} Tahun Akademik {$academicYear}");
        }

        return $this->renderText($period);
    }

    private function renderedFacultyName(mixed $value): string
    {
        $value = $this->renderText($value);
        if ($value === '-') {
            return $value;
        }

        $value = preg_replace('/\s+UGM$/iu', '', $value) ?? $value;

        return $this->renderText($value);
    }

    private function renderText(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }

    /**
     * @return array{rendered: string, disk?: string, path?: string, size?: int|null, mtime?: int|null}
     */
    private function publicImageMarker(?string $path, string $fallback): array
    {
        $publicPath = $this->normalizePublicStoragePath($path);
        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            return ['rendered' => $fallback];
        }

        return [
            'rendered' => 'image',
            'disk' => 'public',
            'path' => $publicPath,
            'size' => $this->safeStorageSize('public', $publicPath),
            'mtime' => $this->safeStorageLastModified('public', $publicPath),
        ];
    }

    /**
     * @return array{rendered: string, path?: string, size?: int|null, mtime?: int|null}
     */
    private function globalParafMarker(): array
    {
        $path = $this->signatoryService->globalParafFilePath();
        if (!$path || !is_file($path)) {
            return ['rendered' => '(Paraf belum tersedia)'];
        }

        return [
            'rendered' => 'image',
            'path' => str_replace('\\', '/', $path),
            'size' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
        ];
    }

    /**
     * The final Magang pipeline must fail required missing images during
     * generation. The foundation hash records absence without document text.
     *
     * @return array{rendered: string, path?: string, size?: int|null, mtime?: int|null}
     */
    private function magangGlobalParafMarker(): array
    {
        $path = $this->signatoryService->globalParafFilePath();
        if (!$path || !is_file($path)) {
            return ['rendered' => 'missing:paraf'];
        }

        return [
            'rendered' => 'image',
            'path' => str_replace('\\', '/', $path),
            'size' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
        ];
    }

    private function normalizePublicStoragePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private function safeStorageSize(string $disk, string $path): ?int
    {
        try {
            return (int) Storage::disk($disk)->size($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeStorageLastModified(string $disk, string $path): ?int
    {
        try {
            return (int) Storage::disk($disk)->lastModified($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively sort associative keys so the JSON serialization order is
     * stable regardless of where Laravel/Eloquent emits attributes.
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                ksort($value);
            }
            foreach ($value as $k => $v) {
                $value[$k] = $this->sortRecursive($v);
            }
        }

        return $value;
    }
}
