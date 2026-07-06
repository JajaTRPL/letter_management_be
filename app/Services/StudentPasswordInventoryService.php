<?php

namespace App\Services;

use App\Enums\PasswordSetMethod;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

class StudentPasswordInventoryService
{
    /**
     * Build a read-only inventory of Mahasiswa password state.
     *
     * Password hashes and candidate plaintext values are used only in memory
     * for one exact Hash::check comparison and are never returned.
     *
     * @return array<string, mixed>
     */
    public function inventory(
        string $environmentLabel,
        int $sampleLimit = 0,
        bool $includeGoogleLinkedSamples = false,
        bool $currentPatternSamplesOnly = false,
        bool $includePolicyBreakdown = false,
    ): array {
        $connectionName = (string) config('database.default');
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        $initialTransactionLevel = $connection->transactionLevel();

        try {
            $this->beginReadOnlyTransaction($connection, $driver, $initialTransactionLevel);

            $schema = $connection->getSchemaBuilder();
            if (!$schema->hasTable('users') || !$schema->hasTable('mahasiswa_profiles')) {
                throw new RuntimeException('Required users or mahasiswa_profiles table is unavailable.');
            }
            $metadataAvailable = $schema->hasColumns('users', [
                'password_set_method',
                'password_set_at',
                'password_set_by_user_id',
                'password_must_rotate',
            ]);

            $counts = [
                'total_mahasiswa' => 0,
                'password_null' => 0,
                'password_not_null' => 0,
                'google_linked_with_password' => 0,
                'google_linked_passwordless' => 0,
                'passwordless_without_google' => 0,
                'local_password_without_google' => 0,
                'local_password_current_identity_available' => 0,
                'local_password_missing_nim_or_birthdate' => 0,
                'current_pattern_match' => 0,
                'unknown_non_current_pattern' => 0,
                'unverifiable_hash_format' => 0,
                'suspended_or_inactive_with_local_password' => 0,
                'pending_or_incomplete_with_local_password' => 0,
                'password_with_no_metadata' => 0,
                'password_method_legacy_unknown' => 0,
                'password_method_reset_password_otp' => 0,
                'password_must_rotate' => 0,
            ];
            $statusBreakdown = [];
            $methodBreakdown = [];
            $samples = [];

            $select = [
                'u.id as id',
                'u.email as email',
                'u.password as password',
                'u.google_id as google_id',
                'u.status as status',
                'mp.nim as nim',
                'mp.tanggal_lahir as tanggal_lahir',
            ];
            if ($metadataAvailable) {
                $select[] = 'u.password_set_method as password_set_method';
                $select[] = 'u.password_set_at as password_set_at';
                $select[] = 'u.password_set_by_user_id as password_set_by_user_id';
                $select[] = 'u.password_must_rotate as password_must_rotate';
            }

            $connection->table('users as u')
                ->leftJoin('mahasiswa_profiles as mp', 'mp.user_id', '=', 'u.id')
                ->where('u.role', 'mahasiswa')
                ->select($select)
                ->orderBy('u.id')
                ->chunkById(250, function ($rows) use (
                    &$counts,
                    &$statusBreakdown,
                    &$methodBreakdown,
                    &$samples,
                    $sampleLimit,
                    $includeGoogleLinkedSamples,
                    $currentPatternSamplesOnly,
                    $metadataAvailable,
                ): void {
                    foreach ($rows as $row) {
                        $counts['total_mahasiswa']++;
                        $googleLinked = $this->hasValue($row->google_id);
                        $hasPassword = $this->hasValue($row->password);

                        if (!$hasPassword) {
                            $counts['password_null']++;
                            $googleLinked
                                ? $counts['google_linked_passwordless']++
                                : $counts['passwordless_without_google']++;
                            continue;
                        }

                        $counts['password_not_null']++;
                        $googleLinked
                            ? $counts['google_linked_with_password']++
                            : $counts['local_password_without_google']++;

                        $status = $this->normalizedStatus($row->status);
                        $statusBreakdown[$status] = ($statusBreakdown[$status] ?? 0) + 1;
                        $storedMethod = $metadataAvailable
                            ? trim((string) ($row->password_set_method ?? ''))
                            : '';
                        $effectiveMethod = $storedMethod !== ''
                            ? $storedMethod
                            : PasswordSetMethod::LegacyUnknown->value;
                        $methodBreakdown[$effectiveMethod] = ($methodBreakdown[$effectiveMethod] ?? 0) + 1;

                        if ($storedMethod === '') {
                            $counts['password_with_no_metadata']++;
                        }
                        if ($effectiveMethod === PasswordSetMethod::LegacyUnknown->value) {
                            $counts['password_method_legacy_unknown']++;
                        }
                        if ($effectiveMethod === PasswordSetMethod::ResetPasswordOtp->value) {
                            $counts['password_method_reset_password_otp']++;
                        }
                        if ($metadataAvailable && (bool) ($row->password_must_rotate ?? false)) {
                            $counts['password_must_rotate']++;
                        }

                        if (in_array($status, ['Suspended', 'Inactive', 'Blocked'], true)) {
                            $counts['suspended_or_inactive_with_local_password']++;
                        }
                        if (in_array($status, ['Pending_Profile', 'pending_profile'], true)) {
                            $counts['pending_or_incomplete_with_local_password']++;
                        }

                        $candidate = $this->currentPredictablePattern($row->nim, $row->tanggal_lahir);
                        if ($candidate === null) {
                            $classification = 'missing_nim_or_birthdate';
                            $counts['local_password_missing_nim_or_birthdate']++;
                        } else {
                            $counts['local_password_current_identity_available']++;

                            try {
                                $matches = Hash::check($candidate, (string) $row->password);
                                $classification = $matches
                                    ? 'current_pattern_match'
                                    : 'unknown_non_current_pattern';
                                $counts[$classification]++;
                            } catch (Throwable) {
                                $classification = 'unverifiable_hash_format';
                                $counts['unverifiable_hash_format']++;
                            } finally {
                                unset($candidate);
                            }
                        }

                        if (
                            count($samples) < $sampleLimit
                            && (!$googleLinked || $includeGoogleLinkedSamples)
                            && (!$currentPatternSamplesOnly || $classification === 'current_pattern_match')
                        ) {
                            $samples[] = $this->maskedSample(
                                $row,
                                $classification,
                                $googleLinked,
                                $effectiveMethod,
                                $metadataAvailable && (bool) ($row->password_must_rotate ?? false),
                            );
                        }
                    }
                }, 'u.id', 'id');

            ksort($statusBreakdown);
            ksort($methodBreakdown);

            $report = [
                'command' => 'users:student-password-inventory',
                'mode' => 'DRY-RUN_READ_ONLY',
                'generated_at' => now()->toIso8601String(),
                'environment_label' => $environmentLabel,
                'database' => [
                    'connection' => $connectionName,
                    'driver' => $driver,
                ],
                'safety' => [
                    'database_read_only_enforced' => in_array($driver, ['pgsql', 'mysql', 'mariadb'], true),
                    'transaction_rollback_guard' => true,
                    'database_mutations_performed' => false,
                    'pii_masking' => 'enforced',
                    'password_hashes_output' => false,
                    'plaintext_passwords_output' => false,
                    'non_mahasiswa_scanned' => $includePolicyBreakdown,
                ],
                'counts' => $counts,
                'local_password_status_breakdown' => $statusBreakdown,
                'password_method_breakdown' => $methodBreakdown,
                'password_origin_metadata' => [
                    'available' => $metadataAvailable,
                    'recent_reset_password_provenance' => $metadataAvailable
                        ? 'available_for_metadata_tracked_password_changes'
                        : 'not_reliably_inferable',
                    'reason' => $metadataAvailable
                        ? 'Password-origin columns are installed. Rows with a local password but no method are conservatively classified as legacy_unknown.'
                        : 'Password-origin columns are not installed. Transient reset-token state cannot prove the origin of the current password.',
                ],
                'known_pattern' => [
                    'description' => 'Current stored NIM stripped of separators plus current birth date formatted DDMMYYYY.',
                    'scope' => 'Only this exact current pattern is checked. No brute force or alternate-pattern testing is performed.',
                ],
                'warning' => 'An unknown/non-current-pattern result is not proof that a password is safe; historical NIM or birth-date changes can make a legacy password undetectable.',
                'samples' => $samples,
            ];

            if ($includePolicyBreakdown) {
                $report['policy_breakdown'] = $this->policyBreakdown(
                    $connection,
                    $metadataAvailable,
                );
            }

            return $report;
        } finally {
            if ($connection->transactionLevel() > $initialTransactionLevel) {
                $connection->rollBack($initialTransactionLevel);
            }
        }
    }

    /**
     * Build count-only policy and campaign support across all user roles.
     *
     * This query intentionally does not select password values, email
     * addresses, names, NIMs, OTPs, or reset-token state.
     *
     * @return array<string, mixed>
     */
    private function policyBreakdown(
        Connection $connection,
        bool $metadataAvailable,
    ): array {
        $schema = $connection->getSchemaBuilder();
        $dimensionAvailability = [
            'tendik_role' => $schema->hasColumn('users', 'tendik_role'),
            'sub_role' => $schema->hasColumn('users', 'sub_role'),
            'role_level' => $schema->hasColumn('users', 'role_level'),
        ];

        $overall = $this->emptyPolicyCounts();
        $roleBreakdown = [];
        foreach (['mahasiswa', 'tendik', 'akademik', 'super_admin'] as $role) {
            $roleBreakdown[$role] = $this->emptyPolicyCounts();
        }

        $tendikBreakdown = $this->emptyDimensionBreakdown([
            'persuratan',
            'sarpras',
            'kepala_lab',
            'laboran',
            'unknown',
        ]);
        $akademikBreakdown = $this->emptyDimensionBreakdown([
            'kaprodi',
            'sekprodi',
            'kadep',
            'sekdep',
            'unknown',
        ]);
        $superAdminBreakdown = $this->emptyDimensionBreakdown([
            'primary',
            'secondary',
            'unknown',
        ]);

        $recentReset = [
            'available' => $metadataAvailable,
            'timestamp_source' => $metadataAvailable ? 'password_set_at' : null,
            'total' => $metadataAvailable ? 0 : null,
            'last_7_days' => $metadataAvailable ? 0 : null,
            'last_30_days' => $metadataAvailable ? 0 : null,
        ];
        $now = now();
        $sevenDaysAgo = $now->copy()->subDays(7);
        $thirtyDaysAgo = $now->copy()->subDays(30);

        $select = [
            'id',
            'role',
            $connection->raw('CASE WHEN password IS NULL THEN 0 ELSE 1 END AS has_password'),
        ];
        foreach ($dimensionAvailability as $column => $available) {
            if ($available) {
                $select[] = $column;
            }
        }
        if ($metadataAvailable) {
            $select[] = 'password_set_method';
            $select[] = 'password_set_at';
            $select[] = 'password_must_rotate';
        }

        $connection->table('users')
            ->select($select)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (
                &$overall,
                &$roleBreakdown,
                &$tendikBreakdown,
                &$akademikBreakdown,
                &$superAdminBreakdown,
                &$recentReset,
                $metadataAvailable,
                $dimensionAvailability,
                $sevenDaysAgo,
                $thirtyDaysAgo,
            ): void {
                foreach ($rows as $row) {
                    $role = $this->normalizedPolicyKey($row->role ?? null);
                    if (!array_key_exists($role, $roleBreakdown)) {
                        $roleBreakdown[$role] = $this->emptyPolicyCounts();
                    }

                    $hasPassword = (bool) ($row->has_password ?? false);
                    $storedMethod = $metadataAvailable
                        ? trim((string) ($row->password_set_method ?? ''))
                        : '';
                    $mustRotate = $metadataAvailable
                        && (bool) ($row->password_must_rotate ?? false);

                    $this->incrementPolicyCounts(
                        $overall,
                        $hasPassword,
                        $storedMethod,
                        $mustRotate,
                        $metadataAvailable,
                    );
                    $this->incrementPolicyCounts(
                        $roleBreakdown[$role],
                        $hasPassword,
                        $storedMethod,
                        $mustRotate,
                        $metadataAvailable,
                    );

                    if ($role === 'tendik') {
                        $key = $dimensionAvailability['tendik_role']
                            ? $this->knownDimensionKey(
                                $row->tendik_role ?? null,
                                ['persuratan', 'sarpras', 'kepala_lab', 'laboran'],
                            )
                            : 'unknown';
                        $this->incrementDimensionCounts(
                            $tendikBreakdown[$key],
                            $hasPassword,
                            $storedMethod,
                            $mustRotate,
                            $metadataAvailable,
                        );
                    } elseif ($role === 'akademik') {
                        $key = $dimensionAvailability['sub_role']
                            ? $this->knownDimensionKey(
                                $row->sub_role ?? null,
                                ['kaprodi', 'sekprodi', 'kadep', 'sekdep'],
                            )
                            : 'unknown';
                        $this->incrementDimensionCounts(
                            $akademikBreakdown[$key],
                            $hasPassword,
                            $storedMethod,
                            $mustRotate,
                            $metadataAvailable,
                        );
                    } elseif ($role === 'super_admin') {
                        $key = $dimensionAvailability['role_level']
                            ? $this->knownDimensionKey(
                                $row->role_level ?? null,
                                ['primary', 'secondary'],
                            )
                            : 'unknown';
                        $this->incrementDimensionCounts(
                            $superAdminBreakdown[$key],
                            $hasPassword,
                            $storedMethod,
                            $mustRotate,
                            $metadataAvailable,
                        );
                    }

                    if (
                        $metadataAvailable
                        && $storedMethod === PasswordSetMethod::ResetPasswordOtp->value
                    ) {
                        $recentReset['total']++;
                        $setAt = $row->password_set_at ?? null;
                        if ($this->hasValue($setAt)) {
                            try {
                                $timestamp = Carbon::parse((string) $setAt);
                                if ($timestamp->greaterThanOrEqualTo($thirtyDaysAgo)) {
                                    $recentReset['last_30_days']++;
                                }
                                if ($timestamp->greaterThanOrEqualTo($sevenDaysAgo)) {
                                    $recentReset['last_7_days']++;
                                }
                            } catch (Throwable) {
                                // Invalid historical timestamps are excluded from
                                // windows but remain in the total method count.
                            }
                        }
                    }
                }
            }, 'id');

        ksort($roleBreakdown);

        $legacyByRole = static fn (string $role): int => (int) (
            $roleBreakdown[$role]['password_methods'][PasswordSetMethod::LegacyUnknown->value]
            ?? 0
        );
        $mahasiswaLegacy = $legacyByRole('mahasiswa');
        $tendikLegacy = $legacyByRole('tendik');
        $akademikLegacy = $legacyByRole('akademik');
        $superAdminLegacy = $legacyByRole('super_admin');
        $staffLegacy = $tendikLegacy + $akademikLegacy;
        $totalLegacy = (int) (
            $overall['password_methods'][PasswordSetMethod::LegacyUnknown->value]
            ?? 0
        );
        $superAdminTotal = (int) ($roleBreakdown['super_admin']['total_users'] ?? 0);
        $allSuperAdminsLegacy = $superAdminTotal > 0
            && $superAdminLegacy === $superAdminTotal;
        $continuityWarning = $superAdminLegacy > 0
            ? 'Do not force-rotate/nullify all Super Admins at once.'
            : null;

        return [
            'scope' => 'all_users_count_only',
            'metadata_available' => $metadataAvailable,
            'dimension_availability' => $dimensionAvailability,
            'overall' => $overall,
            'by_role' => $roleBreakdown,
            'by_tendik_specialization' => $tendikBreakdown,
            'by_akademik_subrole' => $akademikBreakdown,
            'by_super_admin_type' => $superAdminBreakdown,
            'recent_reset_password_otp' => $recentReset,
            'campaign_eligibility' => [
                'mahasiswa_legacy_unknown' => $mahasiswaLegacy,
                'staff_legacy_unknown' => $staffLegacy,
                'super_admin_legacy_unknown' => $superAdminLegacy,
                'already_password_must_rotate' => (int) $overall['password_must_rotate'],
                'warning_campaign_eligible' => $totalLegacy,
                'future_forced_local_login_rotation_eligible' => $mahasiswaLegacy + $staffLegacy,
                'excluded_from_mass_action_super_admin_continuity' => $superAdminLegacy,
                'definitions' => [
                    'staff' => 'tendik_and_akademik',
                    'future_forced_local_login_rotation_eligible' => 'legacy_unknown_excluding_super_admin',
                    'exclusion_reason' => 'Super Admin accounts require individual continuity and break-glass verification.',
                ],
            ],
            'break_glass' => [
                'super_admin_total' => $superAdminTotal,
                'super_admin_legacy_unknown' => $superAdminLegacy,
                'all_super_admins_legacy_unknown' => $allSuperAdminsLegacy,
                'continuity_anchor_required' => $superAdminLegacy > 0,
                'warning' => $continuityWarning,
                'recommendation' => $superAdminLegacy > 0
                    ? 'Select and verify a Super Admin continuity anchor before any future mutation.'
                    : null,
            ],
            'mass_action_preview' => [
                'mutation_performed' => false,
                'targets' => [
                    'mahasiswa-legacy-unknown' => [
                        'affected_count' => $mahasiswaLegacy,
                        'continuity_excluded_count' => 0,
                        'preview_selectable_count' => $mahasiswaLegacy,
                    ],
                    'staff-legacy-unknown' => [
                        'affected_count' => $staffLegacy,
                        'continuity_excluded_count' => 0,
                        'preview_selectable_count' => $staffLegacy,
                    ],
                    'all-legacy-unknown' => [
                        'affected_count' => $totalLegacy,
                        'continuity_excluded_count' => $superAdminLegacy,
                        'preview_selectable_count' => $totalLegacy - $superAdminLegacy,
                        'warning' => $continuityWarning,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPolicyCounts(): array
    {
        return [
            'total_users' => 0,
            'password_null' => 0,
            'password_not_null' => 0,
            'metadata_null' => 0,
            'metadata_not_null' => 0,
            'password_methods' => [
                PasswordSetMethod::LegacyUnknown->value => 0,
                PasswordSetMethod::ResetPasswordOtp->value => 0,
                PasswordSetMethod::SuperAdminSet->value => 0,
                PasswordSetMethod::SelfServiceChange->value => 0,
                PasswordSetMethod::TemporaryAdmin->value => 0,
                PasswordSetMethod::SystemMigration->value => 0,
                PasswordSetMethod::SystemSeed->value => 0,
                'other' => 0,
            ],
            'password_must_rotate' => 0,
        ];
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, int>>
     */
    private function emptyDimensionBreakdown(array $keys): array
    {
        $breakdown = [];
        foreach ($keys as $key) {
            $breakdown[$key] = [
                'total_users' => 0,
                'password_null' => 0,
                'password_not_null' => 0,
                'legacy_unknown' => 0,
                'password_must_rotate' => 0,
            ];
        }

        return $breakdown;
    }

    /**
     * @param array<string, mixed> $counts
     */
    private function incrementPolicyCounts(
        array &$counts,
        bool $hasPassword,
        string $storedMethod,
        bool $mustRotate,
        bool $metadataAvailable,
    ): void {
        $counts['total_users']++;
        $counts[$hasPassword ? 'password_not_null' : 'password_null']++;

        if (!$metadataAvailable || $storedMethod === '') {
            $counts['metadata_null']++;
        } else {
            $counts['metadata_not_null']++;
        }

        if ($storedMethod !== '' || $hasPassword) {
            $effectiveMethod = $storedMethod !== ''
                ? $storedMethod
                : PasswordSetMethod::LegacyUnknown->value;
            $methodKey = array_key_exists($effectiveMethod, $counts['password_methods'])
                ? $effectiveMethod
                : 'other';
            $counts['password_methods'][$methodKey]++;
        }

        if ($mustRotate) {
            $counts['password_must_rotate']++;
        }
    }

    /**
     * @param array<string, int> $counts
     */
    private function incrementDimensionCounts(
        array &$counts,
        bool $hasPassword,
        string $storedMethod,
        bool $mustRotate,
        bool $metadataAvailable,
    ): void {
        $counts['total_users']++;
        $counts[$hasPassword ? 'password_not_null' : 'password_null']++;

        if (
            $storedMethod === PasswordSetMethod::LegacyUnknown->value
            || ($hasPassword && (!$metadataAvailable || $storedMethod === ''))
        ) {
            $counts['legacy_unknown']++;
        }
        if ($mustRotate) {
            $counts['password_must_rotate']++;
        }
    }

    /**
     * @param list<string> $known
     */
    private function knownDimensionKey(mixed $value, array $known): string
    {
        $key = $this->normalizedPolicyKey($value);

        return in_array($key, $known, true) ? $key : 'unknown';
    }

    private function normalizedPolicyKey(mixed $value): string
    {
        $key = strtolower(trim((string) $value));

        return $key !== '' ? $key : 'unknown';
    }

    private function beginReadOnlyTransaction(
        Connection $connection,
        string $driver,
        int $initialTransactionLevel,
    ): void {
        if ($initialTransactionLevel !== 0 && $driver !== 'sqlite') {
            throw new RuntimeException('Inventory must start outside an existing database transaction.');
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->beginTransaction();

            return;
        }

        $connection->beginTransaction();

        if ($driver === 'pgsql') {
            $connection->statement('SET TRANSACTION READ ONLY');

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver for enforced read-only inventory.');
        }
    }

    private function currentPredictablePattern(mixed $nim, mixed $dateOfBirth): ?string
    {
        if (!$this->hasValue($nim) || !$this->hasValue($dateOfBirth)) {
            return null;
        }

        $normalizedNim = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) $nim));
        if (!$normalizedNim) {
            return null;
        }

        try {
            return $normalizedNim.Carbon::parse((string) $dateOfBirth)->format('dmY');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, bool|string>
     */
    private function maskedSample(
        object $row,
        string $classification,
        bool $googleLinked,
        string $passwordSetMethod,
        bool $passwordMustRotate,
    ): array {
        return [
            'reference' => substr(hash_hmac(
                'sha256',
                (string) $row->id,
                (string) config('app.key', 'student-password-inventory')
            ), 0, 12),
            'email_masked' => $this->maskEmail((string) $row->email),
            'nim_masked' => $this->maskIdentifier((string) ($row->nim ?? '')),
            'status' => $this->normalizedStatus($row->status),
            'google_linked' => $googleLinked,
            'classification' => $classification,
            'password_set_method' => $passwordSetMethod,
            'password_must_rotate' => $passwordMustRotate,
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', trim($email), 2), 2, '');

        return $this->maskToken($local).'@'.$this->maskToken($domain);
    }

    private function maskIdentifier(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length === 0) {
            return '[missing]';
        }

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2).str_repeat('*', $length - 4).substr($value, -2);
    }

    private function maskToken(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '[missing]' : substr($value, 0, 1).'***';
    }

    private function normalizedStatus(mixed $status): string
    {
        $value = trim((string) $status);

        return $value !== '' ? $value : '[missing]';
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
