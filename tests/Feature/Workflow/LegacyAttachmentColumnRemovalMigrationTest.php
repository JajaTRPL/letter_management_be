<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Services\LetterAttachmentMetadataService;
use App\Services\LetterAttachmentRequirementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class LegacyAttachmentColumnRemovalMigrationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredColumnsIfPresent();
        } finally {
            parent::tearDown();
        }
    }

    public function test_guard_aborts_on_missing_expected_column(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('required column');

        $this->migration()->up();
    }

    public function test_guard_aborts_on_non_nullability_drift(): void
    {
        $this->restoreLegacyColumns();

        Schema::table('scholarship_applications', function ($table): void {
            $table->dropColumn('transkrip_nilai_path');
        });
        Schema::table('scholarship_applications', function ($table): void {
            $table->string('transkrip_nilai_path')->default('');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not nullable');

        $this->migration()->up();
    }

    public function test_guard_aborts_on_incomplete_registry_coverage(): void
    {
        $this->restoreLegacyColumns();
        $application = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/transkrip.pdf'),
            'slip_gaji_ayah_path' => Storage::url('scholarships/slips/ayah.pdf'),
            'slip_gaji_ibu_path' => null,
        ]);
        $this->attachRegistryDocument($application, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai', 'transkrip.pdf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legacy-only active attachment slot lacks registry coverage');

        $this->migration()->up();
    }

    public function test_guard_aborts_on_malformed_legacy_value(): void
    {
        $this->restoreLegacyColumns();
        $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => 'scholarships/transcripts/../../escape.pdf',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed value');

        $this->migration()->up();
    }

    public function test_guard_aborts_on_legacy_only_required_slot(): void
    {
        $this->restoreLegacyColumns();
        $this->magangApplication(null, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/proposal.pdf'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legacy-only active attachment slot lacks registry coverage');

        $this->migration()->up();
    }

    public function test_guard_aborts_on_marker_only_value_without_registry(): void
    {
        $this->restoreLegacyColumns();
        $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => 'attachment://transkrip_nilai/transkrip.pdf',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('marker-only value lacks registry coverage');

        $this->migration()->up();
    }

    public function test_valid_fixture_drops_exactly_seven_columns_and_keeps_unrelated_columns(): void
    {
        $this->restoreLegacyColumns();
        $before = $this->targetColumnCount();

        $beasiswa = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/transkrip.pdf'),
            'slip_gaji_ayah_path' => Storage::url('scholarships/slips/ayah.pdf'),
            'slip_gaji_ibu_path' => Storage::url('scholarships/slips/ibu.pdf'),
        ]);
        $this->attachBeasiswaRequiredDocuments($beasiswa);

        $magang = $this->magangApplication(null, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/proposal.pdf'),
        ]);
        $this->attachRegistryDocument($magang, SuratPengantarMagangApplication::LETTER_TYPE, 'proposal', 'proposal.pdf');

        $suratTugas = $this->suratTugasApplication(null, [
            'proposal_kegiatan_magang_path' => 'surat-tugas/supporting/proposals/proposal.pdf',
            'surat_pengantar_magang_path' => 'surat-tugas/supporting/pengantar/pengantar.pdf',
        ]);
        $this->attachRegistryDocument($suratTugas, SuratTugasApplication::LETTER_TYPE, 'proposal', 'proposal.pdf');
        $this->attachRegistryDocument($suratTugas, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'pengantar.pdf');

        $this->migration()->up();

        $this->assertSame($before - 7, $this->targetColumnCount());
        $this->assertRetiredColumnsAbsent();
        $this->assertTrue(Schema::hasColumn('scholarship_applications', 'scholarship_name'));
        $this->assertTrue(Schema::hasColumn('surat_pengantar_magang_applications', 'nama_perusahaan'));
        $this->assertTrue(Schema::hasColumn('surat_tugas_applications', 'nomor_surat_tugas'));
    }

    public function test_down_recreates_seven_nullable_columns_only(): void
    {
        $this->migration()->down();

        foreach ($this->retiredTargets() as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} should exist after down().");
                $metadata = collect(Schema::getColumns($table))->firstWhere('name', $column);
                $this->assertSame(true, $metadata['nullable'] ?? null, "{$table}.{$column} should be nullable.");
            }
        }
    }

    public function test_app_boots_and_attachment_runtime_works_after_drop(): void
    {
        $this->assertRetiredColumnsAbsent();

        $this->getJson('/api/profile')->assertUnauthorized();

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student);
        $this->attachBeasiswaRequiredDocuments($application);

        $metadata = (array) $this->app
            ->make(LetterAttachmentMetadataService::class)
            ->forApplication($application, ScholarshipApplication::LETTER_TYPE);

        $this->assertTrue($metadata['transkrip_nilai']['exists']);
        $this->assertTrue($metadata['slip_gaji_ayah']['exists']);
        $this->assertTrue($metadata['slip_gaji_ibu']['exists']);

        $this->actingAs($student, 'sanctum')
            ->get("/api/scholarship/{$application->id}/supporting-documents/transkrip_nilai/preview")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame([], $this->app
            ->make(LetterAttachmentRequirementService::class)
            ->missingRequiredDocumentKeys(ScholarshipApplication::LETTER_TYPE, (int) $application->getKey()));
    }

    public function test_backfill_command_reports_retired_columns_without_crashing_after_drop(): void
    {
        $this->scholarshipApplication();

        $this->artisan('letter-attachments:backfill')
            ->expectsOutputToContain('MODE: DRY-RUN')
            ->expectsTable(['Classification', 'Count'], [
                ['RETIRED_COLUMN_ABSENT', 3],
                ['TOTAL', 3],
            ])
            ->assertExitCode(0);
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/2026_06_09_000000_drop_legacy_attachment_columns.php');
    }

    private function restoreLegacyColumns(): void
    {
        $this->migration()->down();
    }

    private function dropRetiredColumnsIfPresent(): void
    {
        foreach ($this->retiredTargets() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($present === []) {
                continue;
            }

            Schema::table($table, function ($blueprint) use ($present): void {
                $blueprint->dropColumn($present);
            });
        }
    }

    private function assertRetiredColumnsAbsent(): void
    {
        foreach ($this->retiredTargets() as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} should be absent.");
            }
        }
    }

    private function targetColumnCount(): int
    {
        return array_sum(array_map(
            fn (string $table): int => count(Schema::getColumns($table)),
            array_keys($this->retiredTargets()),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    private function retiredTargets(): array
    {
        return [
            'scholarship_applications' => [
                'transkrip_nilai_path',
                'slip_gaji_ayah_path',
                'slip_gaji_ibu_path',
                'ktm_path',
            ],
            'surat_pengantar_magang_applications' => [
                'proposal_kegiatan_magang_path',
            ],
            'surat_tugas_applications' => [
                'proposal_kegiatan_magang_path',
                'surat_pengantar_magang_path',
            ],
        ];
    }
}
