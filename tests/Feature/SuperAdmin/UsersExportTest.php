<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\MahasiswaProfile;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class UsersExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_is_privacy_safe_by_default(): void
    {
        $this->seedStudent();
        $this->actingAsPrimaryAdmin();

        $content = $this->get('/api/super-admin/users/export?format=csv&role=mahasiswa')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('ekspor.mahasiswa@mail.ugm.ac.id', $content);
        $this->assertStringContainsString('24/535278/SV/12345', $content);

        // PII off by default
        $this->assertStringNotContainsString('Tanggal Lahir', $content);
        $this->assertStringNotContainsString('2004-05-15', $content);

        // Security fields must never leak
        $this->assertStringNotContainsString('google-export-123', $content);
        $this->assertStringNotContainsString('password', strtolower($content));

        // Placeholder dashes must stay readable, not formula-escaped to '-
        $this->assertStringNotContainsString("'-", $content);
    }

    public function test_csv_export_includes_tanggal_lahir_only_with_include_pii_and_reason(): void
    {
        $this->seedStudent();
        $this->actingAsPrimaryAdmin();

        // PII export without a stated reason is refused.
        $this->getJson('/api/super-admin/users/export?format=csv&role=mahasiswa&include_pii=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('export_reason');

        $content = $this->get('/api/super-admin/users/export?format=csv&role=mahasiswa&include_pii=1&export_reason=Verifikasi%20data%20beasiswa')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Tanggal Lahir', $content);
        $this->assertStringContainsString('2004-05-15', $content);
        $this->assertStringNotContainsString('google-export-123', $content);
    }

    public function test_export_escapes_formula_injection_in_cells(): void
    {
        $program = $this->studyProgram();
        $student = User::factory()->create([
            'name' => '=HYPERLINK("http://evil";"klik")',
            'email' => 'formula.injection@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => null,
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create(['user_id' => $student->id, 'nim' => '24/535280/SV/12347']);
        $this->actingAsPrimaryAdmin();

        $content = $this->get('/api/super-admin/users/export?format=csv&role=mahasiswa')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    public function test_xlsx_export_downloads_with_expected_headings(): void
    {
        $this->seedStudent();
        $this->actingAsPrimaryAdmin();

        $response = $this->get('/api/super-admin/users/export?format=xlsx&role=mahasiswa')->assertOk();

        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        $this->assertSame('Nama', $rows[0][0]);
        $this->assertContains('NIM', $rows[0]);
        $this->assertNotContains('Tanggal Lahir', $rows[0]);
        $this->assertContains('ekspor.mahasiswa@mail.ugm.ac.id', $rows[1]);

        // Provenance sheet: who exported, filters, PII flag, classification.
        $this->assertContains('Info Ekspor', $spreadsheet->getSheetNames());
        $info = json_encode($spreadsheet->getSheetByName('Info Ekspor')->toArray());
        $this->assertStringContainsString('tidak disertakan', $info);
        $this->assertStringContainsString('Klasifikasi', $info);

        $spreadsheet->disconnectWorksheets();
    }

    public function test_every_export_is_recorded_in_activity_log_including_pii_reason(): void
    {
        $this->seedStudent();
        $this->actingAsPrimaryAdmin();

        $this->get('/api/super-admin/users/export?format=csv&role=mahasiswa&include_pii=1&export_reason=Rekap%20administrasi%20fakultas')
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Export Users',
            'target_user' => 'mahasiswa',
        ]);
        $details = \App\Models\ActivityLog::where('action', 'Export Users')->latest('id')->firstOrFail()->details;
        $this->assertStringContainsString('Data pribadi: ya', $details);
        $this->assertStringContainsString('Alasan: Rekap administrasi fakultas', $details);
    }

    public function test_export_denies_non_super_admin_and_guests(): void
    {
        $this->getJson('/api/super-admin/users/export')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'akademik',
            'sub_role' => 'kaprodi',
            'status' => UserStatus::Active,
        ]));
        $this->getJson('/api/super-admin/users/export')->assertForbidden();
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function actingAsPrimaryAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'primary',
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function studyProgram(string $code = 'TRPL'): StudyProgram
    {
        $department = Department::firstOrCreate(
            ['code' => 'DTEDI'],
            ['name' => 'Departemen Teknik Elektro dan Informatika']
        );

        return StudyProgram::firstOrCreate(
            ['code' => $code],
            ['name' => 'Program ' . $code, 'department_id' => $department->id]
        );
    }

    private function seedStudent(): User
    {
        $program = $this->studyProgram();

        $student = User::factory()->create([
            'name' => 'Mahasiswa Ekspor',
            'email' => 'ekspor.mahasiswa@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => Hash::make('SangatRahasia1!'),
            'google_id' => 'google-export-123',
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]);

        MahasiswaProfile::create([
            'user_id' => $student->id,
            'nim' => '24/535278/SV/12345',
            'tanggal_lahir' => '2004-05-15',
        ]);

        return $student;
    }
}
