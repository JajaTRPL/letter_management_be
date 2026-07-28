<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\LetterRetentionPolicy;
use App\Services\LetterRetentionAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class RetentionAutomationApiTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const URL = '/api/super-admin/retention/automation';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('archive');
    }

    public function test_super_admin_reads_automation_status_off_by_default(): void
    {
        Sanctum::actingAs($this->primarySuperAdmin());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.schema_ready', true)
            ->assertJsonPath('data.schedule_registered', true)
            ->assertJsonPath('data.health_status', 'disabled');
    }

    public function test_enabled_but_never_run_reports_waiting_first_run_health(): void
    {
        app(LetterRetentionAutomationService::class)->setEnabled(
            true,
            $this->primarySuperAdmin(),
            'Mengaktifkan untuk pemeriksaan terjadwal.',
        );

        Sanctum::actingAs($this->primarySuperAdmin());
        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.health_status', 'enabled_waiting_first_run')
            ->assertJsonPath('data.last_success_at', null);
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->tendikPersuratan());
        $this->getJson(self::URL)->assertForbidden();
        $this->patchJson(self::URL, ['enabled' => true, 'reason' => 'coba mengaktifkan', 'acknowledged' => true])->assertForbidden();
    }

    public function test_super_admin_enables_with_reason_and_acknowledgement(): void
    {
        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        $this->patchJson(self::URL, [
            'enabled' => true,
            'reason' => 'Mengaktifkan pengarsipan otomatis sesuai kebijakan retensi.',
            'acknowledged' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('letter_retention_policies', [
            'scope' => 'global',
            'automation_enabled' => true,
            'automation_updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'Pengarsipan otomatis diaktifkan',
        ]);
    }

    public function test_enable_is_blocked_without_reason_or_acknowledgement(): void
    {
        Sanctum::actingAs($this->primarySuperAdmin());

        $this->patchJson(self::URL, ['enabled' => true, 'acknowledged' => true])->assertUnprocessable();
        $this->patchJson(self::URL, ['enabled' => true, 'reason' => 'pendek', 'acknowledged' => true])
            ->assertUnprocessable(); // reason < 10 chars
        $this->patchJson(self::URL, ['enabled' => true, 'reason' => 'alasan yang cukup panjang', 'acknowledged' => false])
            ->assertUnprocessable(); // no acknowledgement
    }

    public function test_disable_requires_reason_acknowledgement_and_confirmation_phrase(): void
    {
        $admin = $this->primarySuperAdmin();
        Sanctum::actingAs($admin);

        // Missing confirmation phrase.
        $this->patchJson(self::URL, [
            'enabled' => false,
            'reason' => 'Menonaktifkan sementara untuk pemeliharaan.',
            'acknowledged' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ketik NONAKTIFKAN untuk mengonfirmasi penonaktifan.');

        // Wrong phrase.
        $this->patchJson(self::URL, [
            'enabled' => false,
            'reason' => 'Menonaktifkan sementara untuk pemeliharaan.',
            'acknowledged' => true,
            'confirmation_phrase' => 'nonaktifkan',
        ])->assertStatus(422);

        // No acknowledgement (even with the phrase).
        $this->patchJson(self::URL, [
            'enabled' => false,
            'reason' => 'Menonaktifkan sementara untuk pemeliharaan.',
            'acknowledged' => false,
            'confirmation_phrase' => 'NONAKTIFKAN',
        ])->assertUnprocessable();

        // Full valid disable.
        $this->patchJson(self::URL, [
            'enabled' => false,
            'reason' => 'Menonaktifkan sementara untuk pemeliharaan.',
            'acknowledged' => true,
            'confirmation_phrase' => 'NONAKTIFKAN',
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'Pengarsipan otomatis dinonaktifkan',
            'details' => 'Menonaktifkan sementara untuk pemeliharaan.',
        ]);
    }

    public function test_command_skips_execution_and_records_only_checked_when_disabled(): void
    {
        // A settings row exists (disabled) so telemetry can be recorded.
        app(LetterRetentionAutomationService::class)->setEnabled(
            false,
            $this->primarySuperAdmin(),
            'Nonaktif untuk pengujian.',
        );

        $this->artisan('letters:retention', ['--execute' => true])
            ->expectsOutputToContain('Pengarsipan otomatis dinonaktifkan')
            ->assertExitCode(0);

        $row = LetterRetentionPolicy::query()->where('scope', 'global')->first();
        $this->assertNotNull($row->last_checked_at); // it woke and checked the gate
        $this->assertNull($row->last_run_at);         // but no real execution started
        $this->assertNull($row->last_success_at);     // and nothing succeeded
    }

    public function test_command_runs_and_records_execution_when_enabled(): void
    {
        app(LetterRetentionAutomationService::class)->setEnabled(
            true,
            $this->primarySuperAdmin(),
            'Mengaktifkan untuk pemeriksaan terjadwal.',
        );

        $this->artisan('letters:retention', ['--execute' => true])
            ->expectsOutputToContain('letters:retention execute completed')
            ->assertExitCode(0);

        $row = LetterRetentionPolicy::query()->where('scope', 'global')->first();
        $this->assertNotNull($row->last_checked_at);
        $this->assertNotNull($row->last_run_at);
        $this->assertNotNull($row->last_success_at);
    }
}
