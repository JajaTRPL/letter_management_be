<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D2C command coverage: dry-run is the safe default (no mutation), execute is
 * guarded by --confirm, filters work, and the JSON report stays private and
 * leaks no absolute filesystem path.
 */
class LetterAttachmentBackfillCommandTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const PDF = "%PDF-1.4\ncmd body\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreRetiredAttachmentColumnsForLegacyFixtureTests();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredAttachmentColumnsForLegacyFixtureTests();
        } finally {
            parent::tearDown();
        }
    }

    private function seedReadyBeasiswa(): ScholarshipApplication
    {
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF);

        return $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
            'slip_gaji_ayah_path' => null,
            'slip_gaji_ibu_path' => null,
        ]);
    }

    public function test_default_run_is_dry_run_and_mutates_nothing(): void
    {
        $this->seedReadyBeasiswa();
        $before = Storage::disk('local')->allFiles();

        $this->artisan('letter-attachments:backfill')
            ->expectsOutputToContain('MODE: DRY-RUN')
            ->expectsOutputToContain('No DB rows will be inserted.')
            ->assertExitCode(0);

        $this->assertSame(0, LetterApplicationAttachment::query()->count());
        $this->assertSame($before, Storage::disk('local')->allFiles());
    }

    public function test_execute_without_confirmation_is_refused(): void
    {
        $this->seedReadyBeasiswa();

        $this->artisan('letter-attachments:backfill --execute')
            ->expectsOutputToContain('Refusing to execute')
            ->assertExitCode(1);

        $this->assertSame(0, LetterApplicationAttachment::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_filter_by_application_id(): void
    {
        $a = $this->seedReadyBeasiswa();
        $this->seedReadyBeasiswa();

        // With the filter, only one application's rows are considered. We assert
        // via the JSON report which is the machine-readable surface.
        $this->artisan("letter-attachments:backfill --application-id={$a->id} --output=reports/dry-run.json")
            ->assertExitCode(0);

        $report = json_decode(Storage::disk('local')->get('reports/dry-run.json'), true);
        $appIds = array_unique(array_column($report['items'], 'application_id'));
        $this->assertSame([$a->id], array_values($appIds));
    }

    public function test_json_report_is_private_and_has_no_absolute_path(): void
    {
        $this->seedReadyBeasiswa();

        $this->artisan('letter-attachments:backfill --output=reports/dry-run.json')
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists('reports/dry-run.json'));
        $raw = Storage::disk('local')->get('reports/dry-run.json');
        $report = json_decode($raw, true);

        $this->assertSame('DRY-RUN', $report['mode']);
        $this->assertNotEmpty($report['items']);
        // No absolute Windows/Unix path, no public URL leaked.
        $this->assertStringNotContainsString(':\\', $raw);
        $this->assertStringNotContainsString('/home/', $raw);
        $this->assertStringNotContainsString('http://', $raw);
        $this->assertStringNotContainsString('storage/app', $raw);
        foreach ($report['items'] as $row) {
            $this->assertArrayHasKey('classification', $row);
            $this->assertArrayHasKey('source_checksum_sha256', $row);
        }
    }

    public function test_output_path_traversal_is_refused(): void
    {
        $this->seedReadyBeasiswa();

        $this->artisan('letter-attachments:backfill --output=../escape.json')
            ->expectsOutputToContain('unsafe')
            ->assertExitCode(0);

        // Nothing was written anywhere on the private disk for the rejected path.
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }
}
