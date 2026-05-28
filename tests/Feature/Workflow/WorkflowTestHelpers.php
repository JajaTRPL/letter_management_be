<?php

namespace Tests\Feature\Workflow;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\MahasiswaProfile;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\StudyProgram;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Support\Str;

trait WorkflowTestHelpers
{
    private ?StudyProgram $workflowDefaultStudyProgram = null;

    private function activeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User ' . Str::uuid(),
            'email' => Str::uuid() . '@example.test',
            'password' => 'password',
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
        ], $attributes));
    }

    private function completeMahasiswa(array $userAttributes = [], array $profileAttributes = [], ?StudyProgram $studyProgram = null): array
    {
        $studyProgram ??= $this->defaultStudyProgram();
        $studyProgram->loadMissing('department.faculty');

        $user = $this->activeUser(array_merge([
            'role' => 'mahasiswa',
            'study_program_id' => $studyProgram->id,
        ], $userAttributes));

        $profile = MahasiswaProfile::create(array_merge([
            'user_id' => $user->id,
            'nim' => '24' . random_int(100000, 999999),
            'fakultas' => 'Sekolah Vokasi',
            'program_studi' => $studyProgram->name,
            'data_source' => 'test',
        ], $profileAttributes));

        return [$user, $profile];
    }

    private function tendikPersuratan(array $assignedTasks = [], array $attributes = []): User
    {
        return $this->activeUser(array_merge([
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'assigned_tasks' => $assignedTasks,
        ], $attributes));
    }

    private function tendikSarpras(array $attributes = []): User
    {
        return $this->activeUser(array_merge([
            'role' => 'tendik',
            'tendik_role' => 'sarpras',
            'assigned_tasks' => [
                ProsesLuarNegeriApplication::LETTER_TYPE,
                SuratKeteranganAktifApplication::LETTER_TYPE,
                SuratPengantarMagangApplication::LETTER_TYPE,
                ScholarshipApplication::LETTER_TYPE,
            ],
        ], $attributes));
    }

    private function akademik(string $subRole, array $attributes = []): User
    {
        $defaults = [
            'role' => 'akademik',
            'sub_role' => $subRole,
        ];

        if (in_array($subRole, ['kaprodi', 'sekprodi'], true)) {
            $programId = $attributes['study_program_id'] ?? $this->defaultStudyProgram()->id;
            $program = StudyProgram::find($programId);
            $defaults['study_program_id'] = $programId;
            $defaults['department_id'] = $attributes['department_id'] ?? $program?->department_id;
        }

        if (in_array($subRole, ['kadep', 'sekdep'], true)) {
            $defaults['department_id'] = $attributes['department_id'] ?? $this->defaultStudyProgram()->department_id;
            $defaults['study_program_id'] = null;
        }

        return $this->activeUser(array_merge($defaults, $attributes));
    }

    private function primarySuperAdmin(array $attributes = []): User
    {
        return $this->activeUser(array_merge([
            'role' => 'super_admin',
            'role_level' => 'primary',
        ], $attributes));
    }

    private function scholarshipApplication(?User $student = null, array $attributes = []): ScholarshipApplication
    {
        [$student, $profile] = $student
            ? [$student, $student->mahasiswaProfile]
            : $this->completeMahasiswa();

        return ScholarshipApplication::create(array_merge([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $profile?->id,
            'scholarship_name' => 'Beasiswa Test',
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ], $attributes));
    }

    private function magangApplication(?User $student = null, array $attributes = []): SuratPengantarMagangApplication
    {
        [$student, $profile] = $student
            ? [$student, $student->mahasiswaProfile]
            : $this->completeMahasiswa();

        return SuratPengantarMagangApplication::create(array_merge([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $profile?->id,
            'nama_penerima' => 'HR Department',
            'jabatan_penerima' => 'Kepala Divisi Teknologi',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test',
            'alamat_jalan' => 'Jl. Test No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'peran' => 'Software Engineer Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. Test',
            'proposal_kegiatan_magang_path' => '/storage/surat-pengantar-magang/proposals/test.pdf',
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ], $attributes));
    }

    private function aktifApplication(?User $student = null, array $attributes = []): SuratKeteranganAktifApplication
    {
        [$student, $profile] = $student
            ? [$student, $student->mahasiswaProfile]
            : $this->completeMahasiswa();

        return SuratKeteranganAktifApplication::create(array_merge([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $profile?->id,
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'Laki-laki',
            'keperluan' => 'Keperluan administrasi',
            'nama_orang_tua_wali' => 'Orang Tua Test',
            'pekerjaan_orang_tua_wali' => 'Pegawai',
            'nip_orang_tua_wali' => null,
            'pangkat_gol_orang_tua_wali' => null,
            'instansi_orang_tua_wali' => null,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ], $attributes));
    }

    private function prosesLuarNegeriApplication(?User $student = null, array $attributes = []): ProsesLuarNegeriApplication
    {
        [$student, $profile] = $student
            ? [$student, $student->mahasiswaProfile]
            : $this->completeMahasiswa();

        return ProsesLuarNegeriApplication::create(array_merge([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $profile?->id,
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'Laki-laki',
            'semester' => 4,
            'nomor_paspor' => 'A1234567',
            'keperluan' => 'Surat rekomendasi pendaftaran student exchange',
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ], $attributes));
    }

    private function mockBeasiswaPreviewGenerationForApprove(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(\App\Services\BeasiswaPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturn(\App\Models\LetterDocumentArtifact::make([
                'phase' => \App\Models\LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                'status' => \App\Models\LetterDocumentArtifact::STATUS_READY,
            ]));

        $this->app->instance(\App\Services\BeasiswaPreviewGenerationService::class, $mock);

        return $mock;
    }

    private function mockBeasiswaPreviewGenerationForProdiApprove(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(\App\Services\BeasiswaPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturn(\App\Models\LetterDocumentArtifact::make([
                'phase' => \App\Models\LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
                'status' => \App\Models\LetterDocumentArtifact::STATUS_READY,
            ]));

        $this->app->instance(\App\Services\BeasiswaPreviewGenerationService::class, $mock);

        return $mock;
    }

    private function mockBeasiswaPreviewGenerationForDepartmentApprove(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(\App\Services\BeasiswaPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturn(\App\Models\LetterDocumentArtifact::make([
                'phase' => \App\Models\LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
                'status' => \App\Models\LetterDocumentArtifact::STATUS_READY,
            ]));

        $this->app->instance(\App\Services\BeasiswaPreviewGenerationService::class, $mock);

        return $mock;
    }

    /**
     * Permissive mock for the SKA preview generation pipeline. Returns a fresh
     * READY artifact for any (application, phase) pair. Use in tests that
     * exercise the wired SKA workflow transitions but do not specifically
     * assert artifact-pipeline behavior.
     */
    private function mockSkaPreviewGenerationAlwaysReady(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(\App\Services\SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($application, string $phase) {
                return \App\Models\LetterDocumentArtifact::make([
                    'letter_type' => \App\Models\SuratKeteranganAktifApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'status' => \App\Models\LetterDocumentArtifact::STATUS_READY,
                ]);
            });

        $this->app->instance(\App\Services\SuratKeteranganAktifPreviewGenerationService::class, $mock);

        return $mock;
    }

    /**
     * Permissive mock for the PLN preview generation pipeline. Use in tests
     * that exercise PLN workflow transitions but do not assert artifact
     * generation behavior directly.
     */
    private function mockPlnPreviewGenerationAlwaysReady(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(\App\Services\ProsesLuarNegeriPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($application, string $phase) {
                return \App\Models\LetterDocumentArtifact::make([
                    'letter_type' => \App\Models\ProsesLuarNegeriApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'status' => \App\Models\LetterDocumentArtifact::STATUS_READY,
                ]);
            });

        $this->app->instance(\App\Services\ProsesLuarNegeriPreviewGenerationService::class, $mock);

        return $mock;
    }

    /**
     * Permissive mock for the Magang preview generation pipeline. Use in tests
     * that exercise wired Magang workflow transitions without testing artifact
     * orchestration behavior directly.
     */
    private function mockMagangPreviewGenerationAlwaysReady(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(\App\Services\SuratPengantarMagangPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($application, string $phase) {
                return \App\Models\LetterDocumentArtifact::make([
                    'letter_type' => \App\Models\SuratPengantarMagangApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'status' => \App\Models\LetterDocumentArtifact::STATUS_READY,
                ]);
            });

        $this->app->instance(\App\Services\SuratPengantarMagangPreviewGenerationService::class, $mock);

        return $mock;
    }

    private function defaultStudyProgram(): StudyProgram
    {
        if ($this->workflowDefaultStudyProgram
            && StudyProgram::whereKey($this->workflowDefaultStudyProgram->id)->exists()
        ) {
            return $this->workflowDefaultStudyProgram;
        }

        return $this->workflowDefaultStudyProgram = $this->studyProgram();
    }

    private function department(array $attributes = []): Department
    {
        $suffix = Str::upper(Str::random(8));
        $faculty = Faculty::create([
            'code' => 'F' . $suffix,
            'name' => 'Faculty ' . $suffix,
        ]);

        return Department::create(array_merge([
            'code' => 'D' . $suffix,
            'name' => 'Department ' . $suffix,
            'faculty_id' => $faculty->id,
        ], $attributes));
    }

    private function studyProgram(?Department $department = null, array $attributes = []): StudyProgram
    {
        $suffix = Str::upper(Str::random(8));
        $department ??= $this->department();

        return StudyProgram::create(array_merge([
            'code' => 'SP' . $suffix,
            'name' => 'Study Program ' . $suffix,
            'department_id' => $department->id,
        ], $attributes));
    }
}
