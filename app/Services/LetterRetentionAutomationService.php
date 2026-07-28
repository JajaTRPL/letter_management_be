<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\LetterRetentionPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Database-managed ON/OFF switch for letter-retention automation. The setting
 * lives on the single global policy row — never in .env — so a SuperAdmin can
 * flip it from the UI. The scheduled command enforces it at runtime, and every
 * change is audited via AdminLog. Scheduler registration itself stays
 * server-owned (config-driven), so this flag is an application-level gate, not
 * proof that the OS cron is running.
 */
class LetterRetentionAutomationService
{
    public function schemaReady(): bool
    {
        return Schema::hasTable('letter_retention_policies')
            && Schema::hasColumn('letter_retention_policies', 'automation_enabled');
    }

    /**
     * Effective automation flag. Falls back to the legacy config value when the
     * automation columns are not yet migrated, so nothing breaks pre-migration.
     */
    public function isEnabled(): bool
    {
        if (!$this->schemaReady()) {
            return (bool) config('letter_retention.enabled');
        }

        return (bool) ($this->row()?->automation_enabled ?? false);
    }

    private function row(): ?LetterRetentionPolicy
    {
        return LetterRetentionPolicy::query()
            ->where('scope', LetterRetentionPolicyService::GLOBAL_SCOPE)
            ->first();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $ready = $this->schemaReady();
        $row = $ready ? $this->row() : null;
        $enabled = $this->isEnabled();

        return [
            'enabled' => $enabled,
            'schema_ready' => $ready,
            'updated_by' => $row?->automationUpdatedBy?->name,
            'enabled_at' => $row?->automation_enabled_at?->toIso8601String(),
            'disabled_at' => $row?->automation_disabled_at?->toIso8601String(),
            'last_checked_at' => $row?->last_checked_at?->toIso8601String(),
            'last_run_at' => $row?->last_run_at?->toIso8601String(),
            'last_success_at' => $row?->last_success_at?->toIso8601String(),
            'last_failure_at' => $row?->last_failure_at?->toIso8601String(),
            'last_failure_message' => $row?->last_failure_message,
            // The scheduled command is registered unconditionally in
            // routes/console.php; the DB flag gates execution at runtime. This
            // is app-level registration, not proof the OS cron is firing.
            'schedule_registered' => true,
            'health_status' => $this->healthStatus($ready, $enabled, $row),
        ];
    }

    private function healthStatus(bool $ready, bool $enabled, ?LetterRetentionPolicy $row): string
    {
        if (!$ready) {
            return 'unavailable';
        }
        if (!$enabled) {
            return 'disabled';
        }

        $lastSuccess = $row?->last_success_at;
        $lastFailure = $row?->last_failure_at;
        $lastChecked = $row?->last_checked_at;

        if ($lastFailure && (!$lastSuccess || $lastFailure->gt($lastSuccess))) {
            return 'failed';
        }
        // The daily job wakes ~every 24h; if a wake is clearly overdue the
        // server schedule likely stopped running.
        if ($lastChecked && $lastChecked->lt(now()->subHours(26))) {
            return 'needs_server_attention';
        }
        if ($lastSuccess) {
            return 'healthy';
        }

        return 'enabled_waiting_first_run';
    }

    /**
     * Flip the setting and audit the change (actor + reason).
     *
     * @return array<string, mixed>
     */
    public function setEnabled(bool $enabled, User $actor, string $reason): array
    {
        $now = now();
        $row = LetterRetentionPolicy::query()->firstOrCreate([
            'scope' => LetterRetentionPolicyService::GLOBAL_SCOPE,
        ]);

        $row->automation_enabled = $enabled;
        $row->automation_updated_by = $actor->id;
        if ($enabled) {
            $row->automation_enabled_at = $now;
        } else {
            $row->automation_disabled_at = $now;
        }
        $row->save();

        ActivityLog::create([
            'user_id' => $actor->id,
            'type' => 'admin',
            'action' => $enabled ? 'Pengarsipan otomatis diaktifkan' : 'Pengarsipan otomatis dinonaktifkan',
            'target_user' => null,
            'details' => mb_substr($reason, 0, 1000),
        ]);

        return $this->status();
    }

    /** The command woke and evaluated the gate — regardless of enabled/skip. */
    public function recordChecked(): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        $this->row()?->forceFill(['last_checked_at' => now()])->save();
    }

    /** An ENABLED execution actually started (never on a skipped disabled wake). */
    public function recordRunStarted(): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        $this->row()?->forceFill(['last_run_at' => now()])->save();
    }

    public function recordRunSucceeded(): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        $this->row()?->forceFill(['last_success_at' => now(), 'last_failure_message' => null])->save();
    }

    public function recordRunFailed(string $message): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        $this->row()?->forceFill([
            'last_failure_at' => now(),
            'last_failure_message' => mb_substr($message, 0, 255),
        ])->save();
    }
}
