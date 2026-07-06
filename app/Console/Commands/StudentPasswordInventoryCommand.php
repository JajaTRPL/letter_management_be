<?php

namespace App\Console\Commands;

use App\Services\StudentPasswordInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

class StudentPasswordInventoryCommand extends Command
{
    protected $signature = 'users:student-password-inventory
        {--dry-run : Required acknowledgement; this command has no mutation mode}
        {--environment-label= : local, staging, production, or testing}
        {--show-samples=0 : Number of masked risky-account samples to show (0-10)}
        {--mask : Explicit masking acknowledgement; masking is always enforced}
        {--json : Print a machine-readable JSON report}
        {--export= : Write masked JSON under reports/student-password-inventory on the private local disk}
        {--current-pattern-only : Restrict samples to exact current-pattern matches}
        {--include-google-linked : Include Google-linked accounts in masked samples}
        {--include-status-breakdown : Show local-password counts by account status}
        {--policy-breakdown : Add count-only all-role policy, campaign, and continuity reporting}
        {--authorized-read-only : Required on staging/production after operator approval}';

    protected $description = 'Read-only password inventory with optional all-role policy breakdown. No cleanup mode exists.';

    public function handle(StudentPasswordInventoryService $inventory): int
    {
        if (!(bool) $this->option('dry-run')) {
            $this->error('Refusing to run: --dry-run is required. This command has no mutation mode.');

            return self::FAILURE;
        }

        $environmentLabel = $this->resolveEnvironmentLabel();
        if ($environmentLabel === null) {
            return self::FAILURE;
        }

        $sampleLimit = $this->sampleLimit();
        if ($sampleLimit === null) {
            return self::FAILURE;
        }

        $exportPath = null;
        if ($this->option('export') !== null && $this->option('export') !== '') {
            $exportPath = $this->normalizeExportPath((string) $this->option('export'));
            if ($exportPath === null) {
                $this->error('Refusing to write report: --export must be a JSON path under reports/student-password-inventory/.');

                return self::FAILURE;
            }
        }

        try {
            $report = $inventory->inventory(
                environmentLabel: $environmentLabel,
                sampleLimit: $sampleLimit,
                includeGoogleLinkedSamples: (bool) $this->option('include-google-linked'),
                currentPatternSamplesOnly: (bool) $this->option('current-pattern-only'),
                includePolicyBreakdown: (bool) $this->option('policy-breakdown'),
            );

            if ($exportPath !== null) {
                $report['export'] = [
                    'disk' => 'private-local',
                    'relative_path' => $exportPath,
                ];
                Storage::disk('local')->put($exportPath, $this->encode($report));
            }

            if ((bool) $this->option('json')) {
                $this->line($this->encode($report));
            } else {
                $this->renderHumanReport($report, $exportPath);
            }
        } catch (Throwable) {
            $this->error('Inventory failed safely. No database changes were committed and no report was produced.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveEnvironmentLabel(): ?string
    {
        $appLabel = $this->normalizedAppEnvironment();
        $option = trim((string) ($this->option('environment-label') ?? ''));
        $label = $option !== '' ? strtolower($option) : $appLabel;
        $allowed = ['local', 'staging', 'production', 'testing'];

        if (!in_array($label, $allowed, true)) {
            $this->error('Invalid --environment-label. Use local, staging, production, or testing.');

            return null;
        }

        if (in_array($label, ['staging', 'production'], true)) {
            if ($appLabel !== $label) {
                $this->error("Refusing {$label} inventory: APP_ENV must match the requested environment label.");

                return null;
            }

            if (!(bool) $this->option('authorized-read-only')) {
                $this->error("Refusing {$label} inventory: explicit --authorized-read-only approval is required.");

                return null;
            }
        }

        if (in_array($appLabel, ['staging', 'production'], true) && $label !== $appLabel) {
            $this->error('Refusing inventory: the environment label does not match APP_ENV.');

            return null;
        }

        return $label;
    }

    private function normalizedAppEnvironment(): string
    {
        $environment = strtolower((string) app()->environment());

        return match ($environment) {
            'production' => 'production',
            'staging' => 'staging',
            'testing' => 'testing',
            default => 'local',
        };
    }

    private function sampleLimit(): ?int
    {
        $raw = trim((string) $this->option('show-samples'));
        if (!preg_match('/^\d+$/', $raw)) {
            $this->error('--show-samples must be an integer from 0 to 10.');

            return null;
        }

        $limit = (int) $raw;
        if ($limit < 0 || $limit > 10) {
            $this->error('--show-samples must be between 0 and 10.');

            return null;
        }

        return $limit;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderHumanReport(array $report, ?string $exportPath): void
    {
        $this->line('<options=bold;fg=green>MODE: DRY-RUN / DATABASE READ-ONLY</>');
        $this->line(
            (bool) $this->option('policy-breakdown')
                ? 'Scope: Mahasiswa risk scan plus all-role count-only policy breakdown. PII masking is enforced.'
                : 'Scope: Mahasiswa only. PII masking is enforced.'
        );
        $this->line('No password hashes, plaintext passwords, OTPs, or reset tokens are output.');
        $this->line("Environment label: {$report['environment_label']}");
        $this->line("Database driver: {$report['database']['driver']}");

        $labels = [
            'total_mahasiswa' => 'A. Total Mahasiswa',
            'password_null' => 'B. Password IS NULL',
            'password_not_null' => 'C. Password IS NOT NULL',
            'google_linked_with_password' => 'D. Google-linked with local password',
            'passwordless_without_google' => 'E. No Google identity and passwordless',
            'local_password_current_identity_available' => 'F. Local password with current NIM + birth date',
            'local_password_missing_nim_or_birthdate' => 'G. Local password missing NIM or birth date',
            'current_pattern_match' => 'H. Exact current predictable-pattern match',
            'unknown_non_current_pattern' => 'I. Unknown/non-current-pattern',
            'suspended_or_inactive_with_local_password' => 'J. Suspended/inactive with local password',
            'pending_or_incomplete_with_local_password' => 'K. Pending/incomplete with local password',
            'password_with_no_metadata' => 'Password present but metadata missing',
            'password_method_legacy_unknown' => 'Password method: legacy_unknown',
            'password_method_reset_password_otp' => 'Password method: reset_password_otp',
            'password_must_rotate' => 'Password rotation required',
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = [$label, $report['counts'][$key]];
        }
        $this->newLine();
        $this->table(['Inventory category', 'Count'], $rows);

        if ((bool) $this->option('include-status-breakdown')) {
            $statusRows = [];
            foreach ($report['local_password_status_breakdown'] as $status => $count) {
                $statusRows[] = [$status, $count];
            }
            if ($statusRows !== []) {
                $this->table(['Local-password status', 'Count'], $statusRows);
            }

            $methodRows = [];
            foreach ($report['password_method_breakdown'] as $method => $count) {
                $methodRows[] = [$method, $count];
            }
            if ($methodRows !== []) {
                $this->table(['Password set method', 'Count'], $methodRows);
            }
        }

        if ($report['samples'] !== []) {
            $this->table(
                ['Reference', 'Email masked', 'NIM masked', 'Status', 'Google', 'Classification', 'Method', 'Rotate'],
                array_map(static fn (array $sample): array => [
                    $sample['reference'],
                    $sample['email_masked'],
                    $sample['nim_masked'],
                    $sample['status'],
                    $sample['google_linked'] ? 'yes' : 'no',
                    $sample['classification'],
                    $sample['password_set_method'],
                    $sample['password_must_rotate'] ? 'yes' : 'no',
                ], $report['samples'])
            );
        }

        $this->warn($report['warning']);
        $this->line(
            $report['password_origin_metadata']['available']
                ? 'L. Password-origin metadata: available for metadata-tracked password changes.'
                : 'L. Password-origin metadata: unavailable; recent reset origin is not reliably inferable.'
        );

        if (isset($report['policy_breakdown'])) {
            $this->renderPolicyBreakdown($report['policy_breakdown']);
        }

        if ($exportPath !== null) {
            $this->info("Masked report written to private local storage: {$exportPath}");
        }
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function renderPolicyBreakdown(array $policy): void
    {
        $this->newLine();
        $this->line('<options=bold>All-role policy breakdown (count-only)</>');

        $overall = $policy['overall'];
        $this->table(
            ['Overall category', 'Count'],
            [
                ['Total users', $overall['total_users']],
                ['Password IS NULL', $overall['password_null']],
                ['Password IS NOT NULL', $overall['password_not_null']],
                ['Password metadata NULL', $overall['metadata_null']],
                ['Password metadata NOT NULL', $overall['metadata_not_null']],
                ['legacy_unknown', $overall['password_methods']['legacy_unknown']],
                ['reset_password_otp', $overall['password_methods']['reset_password_otp']],
                ['super_admin_set', $overall['password_methods']['super_admin_set']],
                ['self_service_change', $overall['password_methods']['self_service_change']],
                ['password_must_rotate = true', $overall['password_must_rotate']],
            ]
        );

        $roleRows = [];
        foreach ($policy['by_role'] as $role => $counts) {
            $roleRows[] = [
                $role,
                $counts['total_users'],
                $counts['password_null'],
                $counts['password_not_null'],
                $counts['password_methods']['legacy_unknown'],
                $counts['password_must_rotate'],
            ];
        }
        $this->table(
            ['Role', 'Total', 'Password null', 'Password set', 'legacy_unknown', 'Must rotate'],
            $roleRows
        );

        $this->renderDimensionTable(
            'Tendik specialization',
            $policy['by_tendik_specialization'],
        );
        $this->renderDimensionTable(
            'Akademik subrole',
            $policy['by_akademik_subrole'],
        );
        $this->renderDimensionTable(
            'Super Admin type',
            $policy['by_super_admin_type'],
        );

        $recent = $policy['recent_reset_password_otp'];
        if ($recent['available']) {
            $this->table(
                ['Recent reset_password_otp', 'Count'],
                [
                    ['Total', $recent['total']],
                    ['Last 7 days', $recent['last_7_days']],
                    ['Last 30 days', $recent['last_30_days']],
                ]
            );
        } else {
            $this->warn('Recent reset_password_otp windows are unavailable because password-origin metadata is unavailable.');
        }

        $campaign = $policy['campaign_eligibility'];
        $this->table(
            ['Campaign policy count', 'Count'],
            [
                ['Mahasiswa legacy_unknown', $campaign['mahasiswa_legacy_unknown']],
                ['Staff legacy_unknown', $campaign['staff_legacy_unknown']],
                ['Super Admin legacy_unknown', $campaign['super_admin_legacy_unknown']],
                ['Already password_must_rotate', $campaign['already_password_must_rotate']],
                ['Warning campaign eligible', $campaign['warning_campaign_eligible']],
                ['Future forced local-login eligible', $campaign['future_forced_local_login_rotation_eligible']],
                ['Excluded for Super Admin continuity', $campaign['excluded_from_mass_action_super_admin_continuity']],
            ]
        );

        $previewRows = [];
        foreach ($policy['mass_action_preview']['targets'] as $target => $preview) {
            $previewRows[] = [
                $target,
                $preview['affected_count'],
                $preview['continuity_excluded_count'],
                $preview['preview_selectable_count'],
            ];
        }
        $this->table(
            ['Preview target', 'Affected', 'Continuity excluded', 'Preview selectable'],
            $previewRows
        );
        $this->line('Mass-action preview: count-only; mutation performed = no.');

        if ($policy['break_glass']['warning'] !== null) {
            $this->warn($policy['break_glass']['warning']);
            $this->warn($policy['break_glass']['recommendation']);
        }
    }

    /**
     * @param array<string, array<string, int>> $breakdown
     */
    private function renderDimensionTable(string $title, array $breakdown): void
    {
        $rows = [];
        foreach ($breakdown as $key => $counts) {
            $rows[] = [
                $key,
                $counts['total_users'],
                $counts['password_null'],
                $counts['password_not_null'],
                $counts['legacy_unknown'],
                $counts['password_must_rotate'],
            ];
        }
        $this->table(
            [$title, 'Total', 'Password null', 'Password set', 'legacy_unknown', 'Must rotate'],
            $rows
        );
    }

    private function normalizeExportPath(string $raw): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim($raw)), '/');

        foreach (['storage/app/private/', 'storage/app/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        $segments = array_filter(explode('/', $path), 'strlen');
        if (
            $path === ''
            || !str_starts_with($path, 'reports/student-password-inventory/')
            || !str_ends_with(strtolower($path), '.json')
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
        ) {
            return null;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @throws JsonException
     */
    private function encode(array $report): string
    {
        return json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
