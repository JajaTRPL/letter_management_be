<?php

namespace Tests\Feature\Workflow;

use App\Models\SuratTugasApplication;
use App\Services\SuratAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Surat Tugas integrates into the single shared average-duration analytics
 * service through the canonical per-type map: an approved temporary fallback
 * until enough Completed rows exist, then a live per-type average. Existing
 * letters' analytics are unaffected.
 */
class SuratAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-25 10:00:00'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_surat_tugas_appears_in_average_duration_response(): void
    {
        $result = app(SuratAnalyticsService::class)->getAverageDurationByType();

        $this->assertArrayHasKey('surat-tugas', $result);
        $this->assertArrayHasKey('value', $result['surat-tugas']);
        $this->assertArrayHasKey('source', $result['surat-tugas']);
        $this->assertArrayHasKey('label', $result['surat-tugas']);
    }

    public function test_surat_tugas_uses_fallback_label_when_no_completed_rows(): void
    {
        $result = app(SuratAnalyticsService::class)->getAverageDurationByType();

        $this->assertSame('fallback', $result['surat-tugas']['source']);
        $this->assertNull($result['surat-tugas']['value']);
        $this->assertSame('2–5 Hari Kerja', $result['surat-tugas']['label']);
    }

    public function test_surat_tugas_uses_live_average_after_enough_completed_rows(): void
    {
        // Five Completed Surat Tugas rows, each a 3-day submitted → Kadep-approval
        // span inside the recent window → dynamic average of 3.0 days.
        for ($i = 0; $i < 5; $i++) {
            $this->suratTugasApplication(null, [
                'status' => SuratTugasApplication::STATUS_COMPLETED,
                'submitted_at' => Carbon::parse('2026-05-22 10:00:00'),
                'kaprodi_approved_at' => Carbon::parse('2026-05-24 10:00:00'),
                'kadep_approved_at' => Carbon::parse('2026-05-25 10:00:00'),
                'completed_at' => Carbon::parse('2026-05-25 10:00:00'),
            ]);
        }

        $result = app(SuratAnalyticsService::class)->getAverageDurationByType();

        $this->assertSame('dynamic', $result['surat-tugas']['source']);
        $this->assertSame(3.0, $result['surat-tugas']['value']);
        $this->assertNull($result['surat-tugas']['label']);
    }

    public function test_existing_letter_analytics_remain_unchanged(): void
    {
        $result = app(SuratAnalyticsService::class)->getAverageDurationByType();

        // Canonical 4 letters still present with their own fallback labels and
        // are not affected by the Surat Tugas integration.
        $this->assertSame('3–7 Hari Kerja', $result['beasiswa']['label']);
        $this->assertSame('1–3 Hari Kerja', $result['aktif']['label']);
        $this->assertSame('2–5 Hari Kerja', $result['magang']['label']);
        $this->assertSame('2–4 Hari Kerja', $result['luar_negeri']['label']);
        foreach (['beasiswa', 'aktif', 'magang', 'luar_negeri'] as $type) {
            $this->assertSame('fallback', $result[$type]['source']);
            $this->assertNull($result[$type]['value']);
        }
    }

    public function test_completed_surat_tugas_does_not_leak_into_other_letter_buckets(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->suratTugasApplication(null, [
                'status' => SuratTugasApplication::STATUS_COMPLETED,
                'submitted_at' => Carbon::parse('2026-05-22 10:00:00'),
                'kadep_approved_at' => Carbon::parse('2026-05-25 10:00:00'),
                'completed_at' => Carbon::parse('2026-05-25 10:00:00'),
            ]);
        }

        $result = app(SuratAnalyticsService::class)->getAverageDurationByType();

        // Surat Tugas rows feed only the surat-tugas bucket; magang stays fallback.
        $this->assertSame('dynamic', $result['surat-tugas']['source']);
        $this->assertSame('fallback', $result['magang']['source']);
        $this->assertNull($result['magang']['value']);
    }
}
