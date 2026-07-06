<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\ImportBatch;
use App\Models\MahasiswaProfile;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class MahasiswaImportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        User::flushEventListeners();

        parent::tearDown();
    }

    // ─────────────────────────── dry-run safety ───────────────────────────

    public function test_dry_run_persists_batch_but_never_mutates_users_or_profiles(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $usersBefore = User::count();
        $profilesBefore = MahasiswaProfile::count();

        $response = $this->validateCsv([
            'Budi Santoso,budi.santoso@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ',2004-05-15',
            'Tanpa Email,,24/535279/SV/12346,' . $program->code . ',',
        ])->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.invalid', 1)
            ->assertJsonPath('summary.create', 1)
            ->assertJsonPath('summary.fail', 1)
            ->assertJsonPath('template_version', 'v2')
            ->assertJsonPath('source_format', 'csv')
            ->assertJsonStructure(['batch_id', 'file_hash']);

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($profilesBefore, MahasiswaProfile::count());

        $batch = ImportBatch::where('uuid', $response->json('batch_id'))->firstOrFail();
        $this->assertSame(ImportBatch::STATUS_VALIDATED, $batch->status);
        $this->assertSame(2, $batch->rows()->count());
        $this->assertSame(1, $batch->errorRows()->count());
    }

    // ─────────────────────────── row validation ───────────────────────────

    public function test_rejects_non_ugm_email_invalid_nim_unknown_prodi_and_bad_date(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $response = $this->validateCsv([
            'Luar Kampus,luar@gmail.com,24/535278/SV/12345,' . $program->code . ',2004-05-15',
            'Nim Rusak,nim.rusak@mail.ugm.ac.id,BUKAN-NIM,' . $program->code . ',',
            'Prodi Hilang,prodi.hilang@mail.ugm.ac.id,24/535280/SV/12347,ZZZZ,',
            'Tanggal Aneh,tanggal.aneh@mail.ugm.ac.id,24/535281/SV/12348,' . $program->code . ',15 Mei 2004',
        ])->assertOk()
            ->assertJsonPath('summary.invalid', 4);

        $errors = collect($response->json('invalid_rows'))->pluck('errors')->flatten()->all();
        $this->assertContains('Email harus menggunakan domain UGM (@mail.ugm.ac.id atau @ugm.ac.id).', $errors);
        $this->assertContains('Format NIM tidak valid. Contoh: 24/535278/SV/12345.', $errors);
        $this->assertContains(
            "Kode Program Studi 'ZZZZ' tidak ditemukan. Lihat sheet \"Referensi Prodi\" pada template.",
            $errors
        );
        $this->assertContains('Format tanggal lahir tidak dikenali. Gunakan YYYY-MM-DD atau DD/MM/YYYY.', $errors);
    }

    public function test_rejects_duplicate_email_and_nim_within_file(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $response = $this->validateCsv([
            'Asli,dobel@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ',',
            'Duplikat Email,dobel@mail.ugm.ac.id,24/535279/SV/12346,' . $program->code . ',',
            'Duplikat Nim,lain@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ',',
        ])->assertOk()
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.invalid', 2);

        $errors = collect($response->json('invalid_rows'))->pluck('errors')->flatten()->all();
        $this->assertContains('Email duplikat dalam file.', $errors);
        $this->assertContains('NIM duplikat dalam file.', $errors);
    }

    public function test_rejects_email_of_non_mahasiswa_and_nim_owned_by_other_account(): void
    {
        $program = $this->studyProgram();
        User::factory()->create([
            'email' => 'staf@ugm.ac.id',
            'role' => 'tendik',
            'status' => UserStatus::Active,
        ]);
        $otherStudent = User::factory()->create([
            'email' => 'pemilik.nim@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create(['user_id' => $otherStudent->id, 'nim' => '23/111111/SV/10001']);
        $this->actingAsPrimaryAdmin();

        $response = $this->validateCsv([
            'Staf Kampus,staf@ugm.ac.id,24/535278/SV/12345,' . $program->code . ',',
            'Nim Orang,akun.baru@mail.ugm.ac.id,23/111111/SV/10001,' . $program->code . ',',
        ])->assertOk()
            ->assertJsonPath('summary.invalid', 2);

        $errors = collect($response->json('invalid_rows'))->pluck('errors')->flatten()->all();
        $this->assertContains('Email milik user non-mahasiswa. Tidak dapat diperbarui melalui impor.', $errors);
        $this->assertContains('NIM sudah terdaftar pada akun lain.', $errors);
    }

    // ─────────────────────── create / update / skip ───────────────────────

    public function test_confirmed_import_creates_updates_and_skips_in_one_batch(): void
    {
        $program = $this->studyProgram();

        // Incomplete Google-created account: merge target.
        $googleStudent = User::factory()->create([
            'name' => 'Google Student',
            'email' => 'google.pending@mail.ugm.ac.id',
            'google_id' => 'google-123',
            'password' => null,
            'role' => 'mahasiswa',
            'status' => UserStatus::PendingProfile,
        ]);
        MahasiswaProfile::create(['user_id' => $googleStudent->id, 'data_source' => 'google_sync']);

        // Complete student identical to the file: skip target.
        $settled = User::factory()->create([
            'name' => 'Sudah Sesuai',
            'email' => 'sudah.sesuai@mail.ugm.ac.id',
            'password' => null,
            'role' => 'mahasiswa',
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create(['user_id' => $settled->id, 'nim' => '23/222222/SV/10002']);

        $this->actingAsPrimaryAdmin();

        $lines = [
            'Baru Sekali,baru.sekali@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ',2004-05-15',
            'Google Student,google.pending@mail.ugm.ac.id,24/535279/SV/12346,' . $program->code . ',',
            'Sudah Sesuai,sudah.sesuai@mail.ugm.ac.id,23/222222/SV/10002,' . $program->code . ',',
        ];

        $validation = $this->validateCsv($lines)
            ->assertOk()
            ->assertJsonPath('summary.create', 1)
            ->assertJsonPath('summary.update', 1)
            ->assertJsonPath('summary.skip', 1)
            ->assertJsonPath('summary.fail', 0);

        $this->confirmCsv($lines, $validation)
            ->assertOk()
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.updated', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.failed', 0);

        $created = User::where('email', 'baru.sekali@mail.ugm.ac.id')->firstOrFail();
        $this->assertNull($created->password);
        $this->assertSame(UserStatus::Active, $created->status);
        $this->assertSame('24/535278/SV/12345', $created->mahasiswaProfile->nim);
        $this->assertSame('import_manual', $created->mahasiswaProfile->data_source);

        $googleStudent->refresh();
        $this->assertSame(UserStatus::Active, $googleStudent->status);
        $this->assertSame('google-123', $googleStudent->google_id);
        $this->assertSame('24/535279/SV/12346', $googleStudent->mahasiswaProfile->nim);

        $batch = ImportBatch::where('uuid', $validation->json('batch_id'))->firstOrFail();
        $this->assertSame(ImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->created_count);
        $this->assertSame(1, $batch->updated_count);
        $this->assertSame(1, $batch->skipped_count);
        $this->assertSame($created->mahasiswaProfile->import_batch_id, $batch->uuid);
    }

    public function test_active_student_conflict_fails_without_override_and_updates_with_it(): void
    {
        $program = $this->studyProgram();
        $student = User::factory()->create([
            'name' => 'Aktif Lama',
            'email' => 'aktif.lama@mail.ugm.ac.id',
            'password' => null,
            'role' => 'mahasiswa',
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create(['user_id' => $student->id, 'nim' => '23/333333/SV/10003']);

        $this->actingAsPrimaryAdmin();

        $lines = ['Aktif Lama,aktif.lama@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ','];

        // Without override: row fails, nothing changes.
        $validation = $this->validateCsv($lines)
            ->assertOk()
            ->assertJsonPath('summary.fail', 1);

        $this->assertStringContainsString(
            'Centang opsi perbarui data',
            collect($validation->json('invalid_rows'))->pluck('errors')->flatten()->first()
        );

        $this->confirmCsv($lines, $validation)->assertUnprocessable();
        $this->assertSame('23/333333/SV/10003', $student->fresh()->mahasiswaProfile->nim);

        // With override: row updates and the change is audited.
        $validation = $this->validateCsv($lines, override: true)
            ->assertOk()
            ->assertJsonPath('summary.update', 1)
            ->assertJsonPath('summary.fail', 0);

        $this->confirmCsv($lines, $validation)
            ->assertOk()
            ->assertJsonPath('summary.updated', 1);

        $this->assertSame('24/535278/SV/12345', $student->fresh()->mahasiswaProfile->nim);

        $batch = ImportBatch::where('uuid', $validation->json('batch_id'))->firstOrFail();
        $row = $batch->rows()->firstOrFail();
        $this->assertSame('24/535278/SV/12345', $row->changes_json['nim']['to']);
        $this->assertSame('23/333333/SV/10003', $row->changes_json['nim']['from']);

        // Governance: the override reason is stored on the batch and audited.
        $this->assertSame('Data resmi kampus menjadi acuan terbaru.', $batch->override_reason);
        $log = \App\Models\ActivityLog::where('action', 'Bulk Import Mahasiswa')->latest('id')->firstOrFail();
        $this->assertStringContainsString('Mode perbarui aktif', $log->details);
        $this->assertStringContainsString('Alasan: Data resmi kampus', $log->details);
    }

    public function test_override_requires_primary_super_admin_and_a_reason(): void
    {
        $program = $this->studyProgram();
        $lines = ['Budi,budi@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ','];

        // Secondary Super Admin cannot use override at all.
        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'secondary',
            'status' => UserStatus::Active,
        ]));
        $this->post('/api/super-admin/users/validate-import', [
            'file' => $this->csvFile($lines),
            'override_existing_active' => '1',
            'override_reason' => 'Alasan yang valid.',
        ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Hanya Primary Super Admin yang dapat menggunakan mode perbarui data mahasiswa aktif.');

        // Primary without a reason is rejected.
        $this->actingAsPrimaryAdmin();
        $this->post('/api/super-admin/users/validate-import', [
            'file' => $this->csvFile($lines),
            'override_existing_active' => '1',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('override_reason');

        // Non-override imports stay reason-free for secondary admins.
        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'secondary',
            'status' => UserStatus::Active,
        ]));
        $this->validateCsv($lines)->assertOk();
    }

    public function test_secondary_super_admin_cannot_confirm_an_override_batch(): void
    {
        $program = $this->studyProgram();
        $student = User::factory()->create([
            'name' => 'Aktif Konflik',
            'email' => 'aktif.konflik@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => null,
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create(['user_id' => $student->id, 'nim' => '23/444444/SV/10004']);

        $this->actingAsPrimaryAdmin();
        $lines = ['Aktif Konflik,aktif.konflik@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ','];
        $validation = $this->validateCsv($lines, override: true)->assertOk();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'secondary',
            'status' => UserStatus::Active,
        ]));
        $this->confirmCsv($lines, $validation)->assertForbidden();
        $this->assertSame('23/444444/SV/10004', $student->fresh()->mahasiswaProfile->nim);
    }

    public function test_semicolon_delimited_csv_from_indonesian_excel_is_accepted(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $csv = implode("\n", [
            'name;email;nim;study_program_code;tanggal_lahir',
            'Budi Semicolon;budi.semicolon@mail.ugm.ac.id;24/535278/SV/12345;' . $program->code . ';2004-05-15',
        ]);

        $this->post('/api/super-admin/users/validate-import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.create', 1);

        // Arbitrary delimiters are still rejected by the strict header check.
        $tabbed = "name\temail\tnim\tstudy_program_code\ttanggal_lahir\n";
        $this->post('/api/super-admin/users/validate-import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $tabbed),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_unmodified_template_sample_rows_can_never_be_imported(): void
    {
        $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $response = $this->validateCsv([
            'Contoh: Budi Santoso,budi.contoh@mail.ugm.ac.id,24/535278/SV/12345,CONTOH,2004-05-15',
        ])->assertOk()
            ->assertJsonPath('summary.valid', 0)
            ->assertJsonPath('summary.invalid', 1);

        $errors = collect($response->json('invalid_rows'))->pluck('errors')->flatten()->all();
        $this->assertContains(
            'Baris ini adalah baris contoh dari template. Hapus baris contoh atau ganti dengan data mahasiswa yang sebenarnya.',
            $errors
        );
    }

    public function test_unknown_prodi_code_suggests_the_closest_match(): void
    {
        $this->studyProgram('TRPL');
        $this->actingAsPrimaryAdmin();

        $response = $this->validateCsv([
            'Salah Ketik,salah.ketik@mail.ugm.ac.id,24/535278/SV/12345,TRPPL,',
        ])->assertOk();

        $errors = collect($response->json('invalid_rows'))->pluck('errors')->flatten()->all();
        $this->assertContains("Kode Program Studi 'TRPPL' tidak ditemukan. Mungkin maksud Anda: TRPL?", $errors);
    }

    public function test_suspended_student_is_never_reactivated_by_import(): void
    {
        $program = $this->studyProgram();
        $suspended = User::factory()->create([
            'name' => 'Mahasiswa Suspend',
            'email' => 'suspend.import@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => null,
            'status' => UserStatus::Suspended,
        ]);
        MahasiswaProfile::create(['user_id' => $suspended->id, 'data_source' => 'google_sync']);

        $this->actingAsPrimaryAdmin();
        $lines = ['Mahasiswa Suspend,suspend.import@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ','];

        $validation = $this->validateCsv($lines)->assertOk()->assertJsonPath('summary.update', 1);
        $this->confirmCsv($lines, $validation)->assertOk()->assertJsonPath('summary.updated', 1);

        $suspended->refresh();
        $this->assertSame(UserStatus::Suspended, $suspended->status);
        $this->assertSame('24/535278/SV/12345', $suspended->mahasiswaProfile->nim);
    }

    public function test_confirm_reports_drift_when_data_changed_after_validation(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $lines = ['Berubah,berubah@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ','];
        $validation = $this->validateCsv($lines)
            ->assertOk()
            ->assertJsonPath('summary.create', 1)
            ->assertJsonPath('summary.invalid', 0);

        // Someone claims the email as a non-mahasiswa between dry-run and confirm.
        User::factory()->create([
            'email' => 'berubah@mail.ugm.ac.id',
            'role' => 'tendik',
            'status' => UserStatus::Active,
        ]);

        $response = $this->confirmCsv($lines, $validation)->assertUnprocessable();
        $this->assertSame(1, $response->json('summary.failed'));
        $this->assertStringContainsString('berbeda dari pratinjau validasi', $response->json('drift_note'));
    }

    public function test_dry_run_persists_skip_notes_for_ignored_differences(): void
    {
        $program = $this->studyProgram();
        $student = User::factory()->create([
            'name' => 'Nama Lama',
            'email' => 'nama.beda@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'password' => null,
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create(['user_id' => $student->id, 'nim' => '23/555555/SV/10005']);

        $this->actingAsPrimaryAdmin();
        $validation = $this->validateCsv([
            'Nama Baru,nama.beda@mail.ugm.ac.id,23/555555/SV/10005,' . $program->code . ',',
        ])->assertOk()->assertJsonPath('summary.skip', 1);

        $batch = ImportBatch::where('uuid', $validation->json('batch_id'))->firstOrFail();
        $row = $batch->rows()->firstOrFail();
        $this->assertStringContainsString('Perbedaan kecil', $row->changes_json['note']);
    }

    public function test_import_upload_bucket_returns_429_after_the_limit(): void
    {
        $this->actingAsPrimaryAdmin();

        // Throttle counts requests before validation, so empty posts suffice.
        for ($i = 0; $i < 10; $i++) {
            $this->post('/api/super-admin/users/validate-import', [], ['Accept' => 'application/json'])
                ->assertUnprocessable();
        }

        $this->post('/api/super-admin/users/validate-import', [], ['Accept' => 'application/json'])
            ->assertStatus(429);
    }

    public function test_reimporting_the_same_file_is_idempotent(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $lines = ['Sekali Saja,sekali.saja@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ',2004-05-15'];

        $first = $this->validateCsv($lines)->assertOk();
        $this->confirmCsv($lines, $first)->assertOk()->assertJsonPath('summary.created', 1);

        $second = $this->validateCsv($lines)->assertOk()->assertJsonPath('summary.skip', 1);
        $this->confirmCsv($lines, $second)
            ->assertOk()
            ->assertJsonPath('summary.created', 0)
            ->assertJsonPath('summary.updated', 0)
            ->assertJsonPath('summary.skipped', 1);

        $this->assertSame(1, User::where('email', 'sekali.saja@mail.ugm.ac.id')->count());
    }

    // ─────────────────────────── confirm guards ───────────────────────────

    public function test_confirm_requires_matching_batch_and_hash(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $lines = ['Budi,budi@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ','];
        $validation = $this->validateCsv($lines)->assertOk();

        // Unknown batch
        $this->post('/api/super-admin/users/bulk-import', [
            'file' => $this->csvFile($lines),
            'batch_id' => 'tidak-ada',
            'file_hash' => $validation->json('file_hash'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        // Swapped file (hash mismatch)
        $this->post('/api/super-admin/users/bulk-import', [
            'file' => $this->csvFile(['Lain,lain@mail.ugm.ac.id,24/535299/SV/12399,' . $program->code . ',']),
            'batch_id' => $validation->json('batch_id'),
            'file_hash' => $validation->json('file_hash'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'budi@mail.ugm.ac.id']);
        $this->assertDatabaseMissing('users', ['email' => 'lain@mail.ugm.ac.id']);

        // Batch already processed
        $this->confirmCsv($lines, $validation)->assertOk();
        $this->confirmCsv($lines, $validation)->assertUnprocessable();
    }

    public function test_unexpected_write_failure_rolls_back_the_whole_import(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $lines = [
            'Pertama,pertama@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ',',
            'Kedua,kedua@mail.ugm.ac.id,24/535279/SV/12346,' . $program->code . ',',
        ];
        $validation = $this->validateCsv($lines)->assertOk();

        $created = 0;
        User::created(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('Simulated mid-import failure');
            }
        });

        $this->confirmCsv($lines, $validation)->assertStatus(500);

        $this->assertDatabaseMissing('users', ['email' => 'pertama@mail.ugm.ac.id']);
        $this->assertDatabaseMissing('users', ['email' => 'kedua@mail.ugm.ac.id']);
        $this->assertSame(
            ImportBatch::STATUS_FAILED,
            ImportBatch::where('uuid', $validation->json('batch_id'))->firstOrFail()->status
        );
    }

    // ─────────────────────────── XLSX support ───────────────────────────

    public function test_xlsx_import_flows_like_csv_and_treats_formulas_as_plain_data(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $validation = $this->post('/api/super-admin/users/validate-import', [
            'file' => $this->xlsxFile('Data Mahasiswa', [
                ['name', 'email', 'nim', 'study_program_code', 'tanggal_lahir'],
                ['Dari Excel', 'dari.excel@mail.ugm.ac.id', '24/535278/SV/12345', $program->code, '2004-05-15'],
                ['=CMD()', 'formula@mail.ugm.ac.id', '24/535279/SV/12346', $program->code, ''],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('source_format', 'xlsx')
            ->assertJsonPath('summary.valid', 1)
            ->assertJsonPath('summary.invalid', 1);

        $errors = collect($validation->json('invalid_rows'))->pluck('errors')->flatten()->all();
        $this->assertContains('Nama tidak boleh diawali karakter formula (=, +, @).', $errors);
    }

    public function test_xlsx_without_data_mahasiswa_sheet_or_wrong_headers_fails(): void
    {
        $program = $this->studyProgram();
        $this->actingAsPrimaryAdmin();

        $this->post('/api/super-admin/users/validate-import', [
            'file' => $this->xlsxFile('Sheet1', [
                ['name', 'email', 'nim', 'study_program_code', 'tanggal_lahir'],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Sheet "Data Mahasiswa" tidak ditemukan. Gunakan template resmi dari sistem.');

        $this->post('/api/super-admin/users/validate-import', [
            'file' => $this->xlsxFile('Data Mahasiswa', [
                ['nama', 'surel'],
                ['Budi', 'budi@mail.ugm.ac.id'],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->assertStringContainsString(
            'tidak sesuai template',
            $this->post('/api/super-admin/users/validate-import', [
                'file' => $this->xlsxFile('Data Mahasiswa', [['nama', 'surel']]),
            ], ['Accept' => 'application/json'])->json('message')
        );
    }

    public function test_xls_files_are_rejected(): void
    {
        $this->actingAsPrimaryAdmin();

        $this->post('/api/super-admin/users/validate-import', [
            'file' => UploadedFile::fake()->create('legacy.xls', 12, 'application/vnd.ms-excel'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    // ───────────────────────── authorization / limits ─────────────────────────

    public function test_import_endpoints_deny_non_super_admin(): void
    {
        $program = $this->studyProgram();
        Sanctum::actingAs(User::factory()->create([
            'role' => 'mahasiswa',
            'study_program_id' => $program->id,
            'status' => UserStatus::Active,
        ]));

        $this->getJson('/api/super-admin/users/import-template')->assertForbidden();
        $this->getJson('/api/super-admin/users/import-batches')->assertForbidden();
        $this->getJson('/api/super-admin/users/export')->assertForbidden();
        $this->post('/api/super-admin/users/validate-import', [
            'file' => $this->csvFile(['Budi,budi@mail.ugm.ac.id,24/535278/SV/12345,' . $program->code . ',']),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_import_and_export_routes_are_rate_limited(): void
    {
        $expected = [
            'api/super-admin/users/validate-import' => 'throttle:10,1,import-upload',
            'api/super-admin/users/bulk-import' => 'throttle:10,1,import-upload',
            'api/super-admin/users/import-template' => 'throttle:30,1,import-template',
            'api/super-admin/users/import-batches' => 'throttle:30,1,import-history',
            'api/super-admin/users/import-batches/{importBatch}/errors' => 'throttle:10,1,import-errors',
            'api/super-admin/users/export' => 'throttle:10,1,users-export',
        ];

        foreach ($expected as $uri => $middleware) {
            $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route, "Route {$uri} not found");
            $this->assertContains($middleware, $route->gatherMiddleware(), "Route {$uri} is not rate limited");
        }
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

    /** @param list<string> $lines */
    private function csvFile(array $lines): UploadedFile
    {
        $csv = implode("\n", array_merge(['name,email,nim,study_program_code,tanggal_lahir'], $lines));

        return UploadedFile::fake()->createWithContent('students.csv', $csv);
    }

    /** @param list<string> $lines */
    private function validateCsv(array $lines, bool $override = false, ?string $reason = null): TestResponse
    {
        $payload = ['file' => $this->csvFile($lines)];
        if ($override) {
            $payload['override_existing_active'] = '1';
            $payload['override_reason'] = $reason ?? 'Data resmi kampus menjadi acuan terbaru.';
        }

        return $this->post('/api/super-admin/users/validate-import', $payload, ['Accept' => 'application/json']);
    }

    /** @param list<string> $lines */
    private function confirmCsv(array $lines, TestResponse $validation): TestResponse
    {
        return $this->post('/api/super-admin/users/bulk-import', [
            'file' => $this->csvFile($lines),
            'batch_id' => $validation->json('batch_id'),
            'file_hash' => $validation->json('file_hash'),
        ], ['Accept' => 'application/json']);
    }

    /** @param list<array<int, string>> $rows */
    private function xlsxFile(string $sheetName, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $columnIndex => $value) {
                // setValueExplicit keeps "=..." as plain strings in the fixture.
                $sheet->getCell([$columnIndex + 1, $rowIndex + 1])
                    ->setValueExplicit((string) $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            'students.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
