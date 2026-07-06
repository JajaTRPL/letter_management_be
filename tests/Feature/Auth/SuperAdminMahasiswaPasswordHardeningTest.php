<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Mail\ResetPasswordTokenMail;
use App\Models\Department;
use App\Models\MahasiswaProfile;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminMahasiswaPasswordHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_super_admin_creates_passwordless_mahasiswa_and_rejects_tampered_password(): void
    {
        $program = $this->studyProgram();
        Sanctum::actingAs($this->primaryAdmin());

        $this->postJson('/api/super-admin/users', [
            'name' => 'Student Passwordless',
            'email' => 'student.passwordless@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'nim' => '24/535278/sv/12345',
            'tanggal_lahir' => '2004-05-04',
            'study_program_id' => $program->id,
        ])->assertCreated()
            ->assertJsonMissingPath('data.password');

        $student = User::where('email', 'student.passwordless@mail.ugm.ac.id')->firstOrFail();
        $this->assertNull($student->password);
        $this->assertSame('24/535278/SV/12345', $student->mahasiswaProfile->nim);
        $this->assertSame('2004-05-04', $student->mahasiswaProfile->tanggal_lahir);

        $this->postJson('/api/super-admin/users', [
            'name' => 'Tampered Student',
            'email' => 'tampered.student@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => '24535279SV1234604052004',
            'nim' => '24/535279/SV/12346',
            'tanggal_lahir' => '2004-05-04',
            'study_program_id' => $program->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', [
            'email' => 'tampered.student@mail.ugm.ac.id',
        ]);

        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_super_admin_cannot_assign_mahasiswa_password_but_can_correct_identity_fields(): void
    {
        $oldProgram = $this->studyProgram('TRPL', 'Teknologi Rekayasa Perangkat Lunak');
        $newProgram = $this->studyProgram('TRIK', 'Teknologi Rekayasa Internet');
        $student = User::factory()->create([
            'email' => 'student.correction@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => null,
            'study_program_id' => $oldProgram->id,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create([
            'user_id' => $student->id,
            'nim' => '23/123456/SV/10001',
            'tanggal_lahir' => '2003-01-02',
        ]);
        Sanctum::actingAs($this->primaryAdmin());

        $this->putJson("/api/super-admin/users/{$student->id}", [
            'password' => 'AdminAssigned1!',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->putJson("/api/super-admin/users/{$student->id}", [
            'nim' => '24/535278/sv/12345',
            'tanggal_lahir' => '2004-05-04',
            'study_program_id' => $newProgram->id,
        ])->assertOk();

        $student->refresh();
        $this->assertNull($student->password);
        $this->assertSame($newProgram->id, $student->study_program_id);
        $this->assertSame('24/535278/SV/12345', $student->mahasiswaProfile->nim);
        $this->assertSame('2004-05-04', $student->mahasiswaProfile->tanggal_lahir);
    }

    public function test_bulk_import_keeps_new_and_google_only_mahasiswa_passwordless(): void
    {
        $program = $this->studyProgram();
        $googleStudent = User::factory()->create([
            'name' => 'Google Student',
            'email' => 'google.import@mail.ugm.ac.id',
            'google_id' => 'google-import-123',
            'password' => null,
            'role' => 'mahasiswa',
            'status' => UserStatus::PendingProfile,
        ]);
        MahasiswaProfile::create([
            'user_id' => $googleStudent->id,
            'data_source' => 'google_sync',
        ]);
        Sanctum::actingAs($this->primaryAdmin());

        $csv = implode("\n", [
            'name,email,nim,study_program_code,tanggal_lahir',
            'Imported Student,imported.student@mail.ugm.ac.id,24/535278/SV/12345,'.$program->code.',2004-05-04',
            'Google Student,google.import@mail.ugm.ac.id,24/535279/SV/12346,'.$program->code.',04/05/2004',
        ]);

        $validation = $this->post('/api/super-admin/users/validate-import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary.valid', 2)
            ->assertJsonPath('summary.invalid', 0);

        $this->post('/api/super-admin/users/bulk-import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
            'batch_id' => $validation->json('batch_id'),
            'file_hash' => $validation->json('file_hash'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.updated', 1)
            ->assertJsonPath('summary.failed', 0);

        $imported = User::where('email', 'imported.student@mail.ugm.ac.id')->firstOrFail();
        $googleStudent->refresh();

        $this->assertNull($imported->password);
        $this->assertNull($googleStudent->password);
        $this->assertSame('google-import-123', $googleStudent->google_id);
        $this->assertSame(UserStatus::Active, $googleStudent->status);

        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', [
            'email' => $googleStudent->email,
            'password' => '24535279SV1234604052004',
        ])->assertUnauthorized();
    }

    public function test_legacy_predictable_password_is_blocked_but_verified_reset_sets_a_local_password(): void
    {
        $student = User::factory()->create([
            'email' => 'legacy.student@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => Hash::make('24535278SV1234504052004'),
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create([
            'user_id' => $student->id,
            'nim' => '24/535278/SV/12345',
            'tanggal_lahir' => '2004-05-04',
        ]);

        $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => '24535278SV1234504052004',
        ])->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Password awal tidak lagi berlaku. Gunakan Google UGM atau Lupa Kata Sandi.'
            )
            ->assertJsonMissingPath('token');

        $this->postJson('/api/forgot-password', [
            'email' => $student->email,
        ])->assertOk();

        $code = null;
        Mail::assertSent(
            ResetPasswordTokenMail::class,
            function (ResetPasswordTokenMail $mail) use ($student, &$code) {
                if (!$mail->hasTo($student->email)) {
                    return false;
                }

                $code = $mail->code;

                return true;
            }
        );
        $this->assertNotNull($code);

        $verification = $this->postJson('/api/verify-token', [
            'email' => $student->email,
            'token' => $code,
        ])->assertOk()
            ->assertJsonStructure(['reset_token']);

        $this->postJson('/api/reset-password', [
            'email' => $student->email,
            'reset_token' => $verification->json('reset_token'),
            'password' => 'LocalSecure1!',
            'password_confirmation' => 'LocalSecure1!',
        ])->assertOk();

        $this->assertTrue(Hash::check('LocalSecure1!', $student->fresh()->password));
        $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'LocalSecure1!',
        ])->assertOk()
            ->assertJsonStructure(['token']);
    }

    private function primaryAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'primary',
            'status' => UserStatus::Active,
        ]);
    }

    private function studyProgram(
        string $code = 'TRPL',
        string $name = 'Teknologi Rekayasa Perangkat Lunak'
    ): StudyProgram {
        $department = Department::firstOrCreate(
            ['code' => 'DTEDI'],
            ['name' => 'Departemen Teknik Elektro dan Informatika']
        );

        return StudyProgram::create([
            'code' => $code,
            'name' => $name,
            'department_id' => $department->id,
        ]);
    }
}
