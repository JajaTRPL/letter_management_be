<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ImportTemplateAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────── template ───────────────────────────

    public function test_template_download_requires_authenticated_super_admin(): void
    {
        $this->getJson('/api/super-admin/users/import-template')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'tendik',
            'status' => UserStatus::Active,
        ]));
        $this->getJson('/api/super-admin/users/import-template')->assertForbidden();
    }

    public function test_csv_template_ships_v2_headers_bom_and_versioned_filename(): void
    {
        $this->studyProgram('TRPL');
        $this->actingAsPrimaryAdmin();

        $response = $this->get('/api/super-admin/users/import-template?format=csv')->assertOk();

        $this->assertStringContainsString(
            'template_import_mahasiswa_verified_v2.csv',
            $response->headers->get('Content-Disposition')
        );

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('name,email,nim,study_program_code,tanggal_lahir', $content);

        // Sample rows use the reserved CONTOH code so an unmodified template
        // can never import fictional students.
        $this->assertStringContainsString('CONTOH', $content);
        $this->assertStringContainsString('Contoh: Budi Santoso', $content);
        $this->assertStringNotContainsString('TRPL', $content);
    }

    public function test_xlsx_template_contains_petunjuk_data_and_prodi_reference_sheets(): void
    {
        $program = $this->studyProgram('TRPL');
        $this->actingAsPrimaryAdmin();

        $response = $this->get('/api/super-admin/users/import-template?format=xlsx')->assertOk();

        $this->assertStringContainsString(
            'template_import_mahasiswa_verified_v2.xlsx',
            $response->headers->get('Content-Disposition')
        );

        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());

        $this->assertSame(
            ['Petunjuk', 'Data Mahasiswa', 'Referensi Prodi'],
            $spreadsheet->getSheetNames()
        );

        $dataSheet = $spreadsheet->getSheetByName('Data Mahasiswa');
        $this->assertSame(
            ['name', 'email', 'nim', 'study_program_code', 'tanggal_lahir'],
            array_slice($dataSheet->toArray()[0], 0, 5)
        );

        $referensi = $spreadsheet->getSheetByName('Referensi Prodi')->toArray();
        $codes = array_column(array_slice($referensi, 1), 0);
        $this->assertContains($program->code, $codes);

        $petunjuk = json_encode($spreadsheet->getSheetByName('Petunjuk')->toArray());
        $this->assertStringContainsString('Google Sheets', $petunjuk);
        $this->assertStringContainsString('mail.ugm.ac.id', $petunjuk);
        $this->assertStringContainsString('CONTOH', $petunjuk);

        // Data sheet sample row is import-proof (reserved CONTOH code).
        $this->assertSame('CONTOH', $dataSheet->toArray()[1][3]);

        $spreadsheet->disconnectWorksheets();
    }

    public function test_purge_command_removes_only_expired_rows_and_keeps_batch_metadata(): void
    {
        $admin = $this->actingAsPrimaryAdmin();

        $expired = $this->makeBatch($admin, [
            'status' => ImportBatch::STATUS_COMPLETED,
            'invalid_rows' => 1,
            'expires_at' => now()->subDay(),
        ]);
        $fresh = $this->makeBatch($admin, [
            'status' => ImportBatch::STATUS_COMPLETED,
            'invalid_rows' => 1,
        ]);

        foreach ([$expired, $fresh] as $batch) {
            ImportBatchRow::create([
                'import_batch_id' => $batch->id,
                'row_number' => 2,
                'email' => 'baris@mail.ugm.ac.id',
                'action' => ImportBatchRow::ACTION_FAIL,
                'status' => ImportBatchRow::STATUS_INVALID,
                'errors_json' => ['Contoh error.'],
            ]);
        }

        $this->artisan('import-batches:purge --dry-run')
            ->expectsOutputToContain('Dry-run: 1 baris')
            ->assertSuccessful();
        $this->assertSame(1, $expired->rows()->count());

        $this->artisan('import-batches:purge')
            ->expectsOutputToContain('Metadata batch dipertahankan')
            ->assertSuccessful();

        $this->assertSame(0, $expired->rows()->count());
        $this->assertSame(1, $fresh->rows()->count());
        // Batch metadata survives as the long-lived audit record.
        $this->assertSame(ImportBatch::STATUS_COMPLETED, $expired->fresh()->status);
        $this->assertSame(1, $expired->fresh()->invalid_rows);
    }

    public function test_purge_schedule_is_config_driven_and_off_by_default(): void
    {
        // Same convention as letter_retention: scheduler activation is
        // config-driven and disabled by default. RetentionApiTest asserts the
        // schedule stays empty by default; the console route registers the
        // purge command only when import_batches.purge.enabled is true.
        // (routes/console.php is evaluated at boot, so the enabled case
        // cannot be re-registered inside a running test.)
        $this->assertFalse(config('import_batches.purge.enabled'));
        $this->assertSame('03:15', config('import_batches.purge.time'));
    }

    public function test_purged_error_report_returns_a_friendly_retention_message(): void
    {
        $admin = $this->actingAsPrimaryAdmin();
        $purged = $this->makeBatch($admin, [
            'status' => ImportBatch::STATUS_COMPLETED,
            'invalid_rows' => 3,
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson("/api/super-admin/users/import-batches/{$purged->uuid}/errors")
            ->assertStatus(410)
            ->assertJsonPath('message', 'Laporan error batch ini sudah melewati masa penyimpanan dan telah dihapus.');

        // History marks the report as expired instead of offering a dead link.
        $response = $this->getJson('/api/super-admin/users/import-batches')->assertOk();
        $entry = collect($response->json('data'))->firstWhere('batch_id', $purged->uuid);
        $this->assertFalse($entry['has_error_report']);
        $this->assertTrue($entry['error_report_expired']);
    }

    // ─────────────────────────── history ───────────────────────────

    public function test_import_history_lists_batches_with_filters(): void
    {
        $admin = $this->actingAsPrimaryAdmin();

        $completed = $this->makeBatch($admin, [
            'status' => ImportBatch::STATUS_COMPLETED,
            'source_format' => 'csv',
            'created_count' => 5,
        ]);
        $this->makeBatch($admin, [
            'status' => ImportBatch::STATUS_VALIDATED,
            'source_format' => 'xlsx',
        ]);

        $this->getJson('/api/super-admin/users/import-batches')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $response = $this->getJson('/api/super-admin/users/import-batches?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($completed->uuid, $response->json('data.0.batch_id'));
        $this->assertSame(5, $response->json('data.0.created_count'));
        $this->assertSame($admin->name, $response->json('data.0.uploaded_by'));

        $this->getJson('/api/super-admin/users/import-batches?source_format=xlsx')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ─────────────────────────── error report ───────────────────────────

    public function test_error_report_streams_server_side_rows_with_formula_escape(): void
    {
        $admin = $this->actingAsPrimaryAdmin();
        $batch = $this->makeBatch($admin, ['invalid_rows' => 2]);

        ImportBatchRow::create([
            'import_batch_id' => $batch->id,
            'row_number' => 2,
            'email' => 'salah@gmail.com',
            'nim' => '24/535278/SV/12345',
            'display_name' => '=HYPERLINK("http://evil")',
            'action' => ImportBatchRow::ACTION_FAIL,
            'status' => ImportBatchRow::STATUS_INVALID,
            'errors_json' => ['Email harus menggunakan domain UGM (@mail.ugm.ac.id atau @ugm.ac.id).'],
        ]);
        ImportBatchRow::create([
            'import_batch_id' => $batch->id,
            'row_number' => 3,
            'email' => 'valid@mail.ugm.ac.id',
            'nim' => '24/535279/SV/12346',
            'display_name' => 'Baris Sukses',
            'action' => ImportBatchRow::ACTION_CREATE,
            'status' => ImportBatchRow::STATUS_VALID,
        ]);

        $csv = $this->get("/api/super-admin/users/import-batches/{$batch->uuid}/errors?format=csv")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Email harus menggunakan domain UGM', $csv);
        // Formula-injection guard: leading = must be neutralized with a quote.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        // Only error rows belong in the report.
        $this->assertStringNotContainsString('Baris Sukses', $csv);

        $xlsx = $this->get("/api/super-admin/users/import-batches/{$batch->uuid}/errors?format=xlsx")->assertOk();
        $spreadsheet = IOFactory::load($xlsx->baseResponse->getFile()->getPathname());
        $rows = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();

        $this->assertSame(['Baris', 'Nama', 'Email', 'NIM', 'Error'], array_slice($rows[0], 0, 5));
        $this->assertStringContainsString('domain UGM', $rows[1][4]);
    }

    public function test_error_report_returns_404_when_batch_has_no_errors_and_denies_non_admin(): void
    {
        $admin = $this->actingAsPrimaryAdmin();
        $clean = $this->makeBatch($admin);

        $this->getJson("/api/super-admin/users/import-batches/{$clean->uuid}/errors")
            ->assertNotFound();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
        ]));
        $this->getJson("/api/super-admin/users/import-batches/{$clean->uuid}/errors")
            ->assertForbidden();
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

    private function studyProgram(string $code): StudyProgram
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

    /** @param array<string, mixed> $attributes */
    private function makeBatch(User $uploader, array $attributes = []): ImportBatch
    {
        return ImportBatch::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'kind' => ImportBatch::KIND_VERIFIED_MAHASISWA,
            'template_version' => 'v2',
            'source_format' => 'csv',
            'original_filename' => 'students.csv',
            'file_hash' => hash('sha256', Str::random(12)),
            'uploaded_by_user_id' => $uploader->id,
            'status' => ImportBatch::STATUS_VALIDATED,
            'expires_at' => now()->addDays(90),
        ], $attributes));
    }
}
