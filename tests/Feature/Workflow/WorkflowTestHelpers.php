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

    private function completeMahasiswa(array $userAttributes = [], array $profileAttributes = []): array
    {
        $studyProgram = $this->studyProgram();

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
        return $this->activeUser(array_merge([
            'role' => 'akademik',
            'sub_role' => $subRole,
        ], $attributes));
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
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test',
            'peran' => 'Software Engineer Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'dosen_pembimbing_dpa' => 'Dr. Test',
            'proposal_kegiatan_magang_path' => '/storage/surat-pengantar-magang/proposals/test.pdf',
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

    private function studyProgram(): StudyProgram
    {
        $suffix = Str::upper(Str::random(8));
        $faculty = Faculty::create([
            'code' => 'F' . $suffix,
            'name' => 'Faculty ' . $suffix,
        ]);

        $department = Department::create([
            'code' => 'D' . $suffix,
            'name' => 'Department ' . $suffix,
            'faculty_id' => $faculty->id,
        ]);

        return StudyProgram::create([
            'code' => 'SP' . $suffix,
            'name' => 'Study Program ' . $suffix,
            'department_id' => $department->id,
        ]);
    }
}
