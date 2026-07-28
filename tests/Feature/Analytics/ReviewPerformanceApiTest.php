<?php

namespace Tests\Feature\Analytics;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Analytics\ReviewSampleConfidencePolicy;
use App\Support\Workflow\LetterReviewStageClock as LetterStage;
use App\Support\Workflow\RoomBookingReviewStageClock as BookingStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The SuperAdmin analytics API, plus the regression guard for the two defects
 * that made the old dashboard card permanently read "00 Hari 00 Jam 00 Menit".
 */
class ReviewPerformanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_non_superadmin_role_is_refused(): void
    {
        foreach (['mahasiswa', 'tendik', 'akademik'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/super-admin/review-performance')
                ->assertForbidden();
        }
    }

    public function test_summary_reports_all_five_stages_across_both_domains(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson('/api/super-admin/review-performance?period=3months')
            ->assertOk()
            ->json('data');

        $stages = collect($data['scopes'])->flatMap(fn ($scope) => collect($scope['stages'])->pluck('stage'));

        $this->assertEqualsCanonicalizing([
            LetterStage::STAGE_PERSURATAN,
            LetterStage::STAGE_PRODI,
            LetterStage::STAGE_DEPARTEMEN,
            BookingStage::STAGE_SARPRAS,
            BookingStage::STAGE_KALAB,
        ], $stages->all(), 'Akademik is split into Prodi and Departemen, and bookings are included.');

        $this->assertSame('3 Bulan', $data['period']['label']);
        $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_NONE, $data['scopes'][0]['stages'][0]['metric']['source']);
    }

    public function test_the_measurement_caveats_ship_with_every_response(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $basis = $this->getJson('/api/super-admin/review-performance')->assertOk()->json('data.basis');

        // The single most important sentence: reviewers are not charged for the
        // time an applicant spends fixing their own paperwork.
        $this->assertStringContainsString('Waktu mahasiswa merevisi', $basis['excludes']);
        $this->assertStringContainsString('pemeriksaan terakhir', strtolower($basis['measures']));
        $this->assertSame(5, $basis['min_sample']);
    }

    public function test_an_empty_period_never_reports_a_zeroed_duration(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson('/api/super-admin/review-performance')->assertOk()->json('data');

        foreach ($data['scopes'] as $scope) {
            foreach ($scope['stages'] as $stage) {
                $metric = $stage['metric'];
                // Null, never 0 — "00 Hari 00 Jam" reads as "approved instantly".
                $this->assertNull($metric['median_seconds'], "{$stage['stage']} must not report a measured zero.");
                $this->assertNull($metric['median_label']);
                $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_NONE, $metric['source']);
                $this->assertNull($metric['estimate_label'], 'No activity means no estimate either.');
            }
        }
    }

    public function test_a_disabled_deadline_is_reported_as_unrated_not_as_good(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson('/api/super-admin/review-performance')->assertOk()->json('data');

        foreach ($data['scopes'] as $scope) {
            $this->assertFalse($scope['sla']['enabled'], 'The SLA policy ships disabled.');
            foreach ($scope['stages'] as $stage) {
                $this->assertSame(
                    ReviewSampleConfidencePolicy::STATUS_UNRATED,
                    $stage['metric']['status'],
                    'Nothing may be judged against a deadline nobody has switched on.',
                );
            }
        }
    }

    public function test_breakdown_defaults_to_volume_sorting_and_names_its_dimension(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson('/api/super-admin/review-performance/breakdown?scope=letter&stage=prodi')
            ->assertOk()
            ->json('data');

        // A table sorted by duration is a leaderboard regardless of its heading.
        $this->assertSame('volume_desc', $data['sort']);
        $this->assertSame('study_program', $data['unit_dimension']);
        $this->assertSame('Program Studi', $data['unit_dimension_label']);
    }

    public function test_trend_buckets_by_month_for_long_windows_and_by_day_for_short_ones(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->assertSame('month', $this->getJson('/api/super-admin/review-performance/trend?scope=letter&stage=prodi&period=12months')
            ->assertOk()->json('data.bucket'));
        $this->assertSame('day', $this->getJson('/api/super-admin/review-performance/trend?scope=letter&stage=prodi&period=week')
            ->assertOk()->json('data.bucket'));
    }

    public function test_an_unknown_scope_or_stage_is_a_404_not_an_empty_report(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/super-admin/review-performance/breakdown?scope=letter&stage=rektor')->assertNotFound();
        $this->getJson('/api/super-admin/review-performance/breakdown?scope=nonsense&stage=prodi')->assertNotFound();
        $this->getJson('/api/super-admin/review-performance/trend?scope=letter&stage=rektor')->assertNotFound();
    }

    public function test_no_response_field_identifies_an_individual_reviewer(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/super-admin/review-performance')->assertOk()->getContent();

        foreach (['reviewer', 'approved_by', 'actor', 'user_id', 'nip'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "Analytics must never expose `{$forbidden}`.");
        }
    }

    // ── legacy dashboard card: the regression guard ─────────────────────────

    public function test_the_legacy_dashboard_stats_endpoint_runs_on_sqlite(): void
    {
        // It could not before: AVG(EXTRACT(EPOCH …)) is Postgres-only, which is
        // exactly why this endpoint had no test coverage at all.
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/super-admin/dashboard/stats')
            ->assertOk()
            ->assertJsonStructure(['approval_durations' => ['tendik', 'akademik']]);
    }

    public function test_the_legacy_card_no_longer_depends_on_the_dead_column(): void
    {
        // Comments are stripped first: the docblock deliberately NAMES the dead
        // column to explain why it is gone, and that explanation is worth keeping.
        // What must not survive is executable code that reads it.
        $code = $this->strippedSource(app_path('Http/Controllers/SuperAdmin/DashboardController.php'));

        $this->assertStringNotContainsString('akademik_approved_at', $code,
            'Nothing writes that column, so reading it guaranteed a permanent zero.');
        $this->assertStringNotContainsString('EXTRACT(EPOCH', $code,
            'Database-specific date SQL cannot run on the test connection.');
    }

    /** Source with comments and docblocks removed, so guards test code only. */
    private function strippedSource(string $path): string
    {
        $code = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function superAdmin(): User
    {
        return $this->user('super_admin');
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }
}
