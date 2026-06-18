<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\LetterRetentionAction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SuperAdminRetentionService
{
    public function __construct(
        private readonly LetterRetentionService $retention,
        private readonly LetterRetentionPolicyService $policies,
        private readonly LetterRetentionApplicationRepository $applications,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $dryRun = $this->retention->run(new LetterRetentionOptions(batch: 500));

        $candidateCounts = [];
        foreach ($dryRun->actions as $action) {
            $candidateCounts[$action->category] = ($candidateCounts[$action->category] ?? 0) + 1;
        }
        ksort($candidateCounts);

        return [
            'schema_ready' => $dryRun->schemaReady,
            'policy' => $this->policyPayload(),
            'candidates' => [
                'total' => $dryRun->total(),
                'by_category' => $candidateCounts,
            ],
            'archives' => [
                'available' => LetterDocumentArtifact::query()
                    ->whereNotNull('archived_at')
                    ->whereNull('archive_purged_at')
                    ->count(),
                'purged' => LetterDocumentArtifact::query()
                    ->whereNotNull('archive_purged_at')
                    ->count(),
            ],
            'actions' => [
                'total' => LetterRetentionAction::query()->count(),
                'by_status' => LetterRetentionAction::query()
                    ->selectRaw('status, count(*) as aggregate')
                    ->groupBy('status')
                    ->pluck('aggregate', 'status')
                    ->map(fn ($value): int => (int) $value)
                    ->all(),
            ],
            'scheduler' => [
                'enabled' => (bool) config('letter_retention.enabled'),
                'source' => 'config',
                'api_managed' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function policyPayload(): array
    {
        return [
            'schema_ready' => $this->policies->schemaReady(),
            'values' => $this->policies->current(),
            'defaults' => $this->policies->defaults(),
            'scope' => LetterRetentionPolicyService::GLOBAL_SCOPE,
            'scheduler' => [
                'enabled' => (bool) config('letter_retention.enabled'),
                'source' => 'config',
                'api_managed' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function runFromPayload(array $payload, bool $execute, ?User $actor = null): LetterRetentionRunResult
    {
        return $this->retention->run(new LetterRetentionOptions(
            execute: $execute,
            letterType: $payload['letter_type'] ?? null,
            applicationId: isset($payload['application_id']) ? (int) $payload['application_id'] : null,
            category: $payload['category'] ?? null,
            batch: $execute ? 1 : min(max((int) ($payload['batch'] ?? 100), 1), 500),
            subjectType: $payload['subject_type'] ?? null,
            subjectId: isset($payload['subject_id']) ? (int) $payload['subject_id'] : null,
            trigger: $actor ? 'manual' : 'system',
            actorId: $actor?->id,
            reason: $payload['reason'] ?? null,
        ));
    }

    public function restoreArchive(LetterDocumentArtifact $artifact, User $actor, string $reason): LetterRetentionActionResult
    {
        $now = Carbon::now();
        $eligibleAt = $now->copy();
        $archivePath = $this->normalizePath($artifact->archive_path);
        $pathHash = $archivePath ? hash('sha256', $archivePath) : null;

        if (!$this->applications->completedAt($artifact->letter_type, (int) $artifact->application_id)) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'application_not_completed', null, $pathHash, $now);
        }

        if (!$artifact->archived_at || $artifact->archive_purged_at) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'archive_not_available', null, $pathHash, $now);
        }

        $archiveDisk = $artifact->archive_disk;
        $activePath = $this->normalizePath($artifact->pdf_path);
        if (!is_string($archiveDisk) || $archiveDisk === '' || !$archivePath || !$activePath || !$this->isArtifactPdfPath($artifact, $activePath)) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'archive_metadata_missing', null, $pathHash, $now);
        }

        $archive = Storage::disk($archiveDisk);
        if (!$archive->exists($archivePath)) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'archive_missing', null, $pathHash, $now);
        }

        $contents = $archive->get($archivePath);
        if (!is_string($contents)) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'archive_read_failed', null, $pathHash, $now);
        }

        $checksum = hash('sha256', $contents);
        if (
            is_string($artifact->archive_checksum_sha256)
            && $artifact->archive_checksum_sha256 !== ''
            && $checksum !== $artifact->archive_checksum_sha256
        ) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'archive_checksum_mismatch', $checksum, $pathHash, $now);
        }

        $active = Storage::disk('local');
        if ($active->exists($activePath)) {
            $activeContents = $active->get($activePath);
            if (!is_string($activeContents) || hash('sha256', $activeContents) !== $checksum) {
                return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'active_checksum_mismatch', $checksum, $pathHash, $now);
            }
        } elseif (!$active->put($activePath, $contents)) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'failed', $eligibleAt, 'active_restore_failed', $checksum, $pathHash, $now);
        }

        $restoredContents = $active->get($activePath);
        if (!is_string($restoredContents) || hash('sha256', $restoredContents) !== $checksum) {
            return $this->persistRestoreAction($artifact, $actor, $reason, 'blocked', $eligibleAt, 'active_restore_checksum_mismatch', $checksum, $pathHash, $now);
        }

        $artifact->forceFill([
            'retention_status' => 'restored',
        ])->save();

        return $this->persistRestoreAction($artifact, $actor, $reason, 'completed', $eligibleAt, null, $checksum, $pathHash, $now);
    }

    /**
     * @return array<string, mixed>
     */
    public function actionResultPayload(LetterRetentionActionResult $action): array
    {
        return [
            'letter_type' => $action->letterType,
            'application_id' => $action->applicationId,
            'category' => $action->category,
            'action' => $action->action,
            'subject_type' => $action->subjectType,
            'subject_id' => $action->subjectId,
            'status' => $action->status,
            'eligible_at' => $action->eligibleAt?->toIso8601String(),
            'error_code' => $action->errorCode,
            'verification_state' => $this->verificationState($action->checksumSha256, $action->errorCode),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionModelPayload(LetterRetentionAction $action): array
    {
        $metadata = is_array($action->metadata) ? $action->metadata : [];

        return [
            'id' => $action->id,
            'letter_type' => $action->letter_type,
            'application_id' => (int) $action->application_id,
            'category' => $action->category,
            'action' => $action->action,
            'subject_type' => $action->subject_type,
            'subject_id' => $action->subject_id,
            'status' => $action->status,
            'eligible_at' => $action->eligible_at?->toIso8601String(),
            'executed_at' => $action->executed_at?->toIso8601String(),
            'error_code' => $action->error_code,
            'verification_state' => $this->verificationState($action->checksum_sha256, $action->error_code),
            'metadata' => [
                'trigger' => $metadata['trigger'] ?? 'system',
                'actor_id' => $metadata['actor_id'] ?? null,
                'reason_present' => isset($metadata['reason']) && $metadata['reason'] !== '',
            ],
            'created_at' => $action->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function archivePayload(LetterDocumentArtifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'letter_type' => $artifact->letter_type,
            'application_id' => (int) $artifact->application_id,
            'phase' => $artifact->phase,
            'version' => (int) $artifact->version,
            'status' => $artifact->status,
            'retention_status' => $artifact->retention_status,
            'archived_at' => $artifact->archived_at?->toIso8601String(),
            'archive_purged_at' => $artifact->archive_purged_at?->toIso8601String(),
            'verification_state' => $this->verificationState($artifact->archive_checksum_sha256),
        ];
    }

    private function verificationState(?string $checksum, ?string $errorCode = null): string
    {
        if (is_string($errorCode) && str_contains($errorCode, 'checksum')) {
            return 'verification_failed';
        }

        return is_string($checksum) && $checksum !== ''
            ? 'verified'
            : 'not_available';
    }

    private function persistRestoreAction(
        LetterDocumentArtifact $artifact,
        User $actor,
        string $reason,
        string $status,
        Carbon $eligibleAt,
        ?string $errorCode,
        ?string $checksum,
        ?string $pathHash,
        Carbon $now,
    ): LetterRetentionActionResult {
        LetterRetentionAction::create([
            'action_key' => hash('sha256', implode('|', [
                'manual',
                'restore',
                'artifact',
                $artifact->id,
                (string) Str::uuid(),
            ])),
            'letter_type' => $artifact->letter_type,
            'application_id' => (int) $artifact->application_id,
            'subject_type' => 'artifact',
            'subject_id' => (int) $artifact->id,
            'category' => LetterRetentionService::CATEGORY_FINAL_OFFICIAL_PDF,
            'action' => 'restore',
            'status' => $status,
            'storage_disk' => $artifact->archive_disk,
            'storage_path_hash' => $pathHash,
            'checksum_sha256' => $checksum,
            'eligible_at' => $eligibleAt,
            'executed_at' => $now,
            'error_code' => $errorCode,
            'manifest_reference' => null,
            'metadata' => [
                'schema_version' => 1,
                'trigger' => 'manual',
                'actor_id' => $actor->id,
                'reason' => $reason,
            ],
        ]);

        return new LetterRetentionActionResult(
            $artifact->letter_type,
            (int) $artifact->application_id,
            LetterRetentionService::CATEGORY_FINAL_OFFICIAL_PDF,
            'restore',
            'artifact',
            (int) $artifact->id,
            $status,
            $eligibleAt,
            $errorCode,
            $checksum,
            $artifact->archive_disk,
            $pathHash,
        );
    }

    private function normalizePath(?string $stored): ?string
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($stored, '/'));
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            return null;
        }

        return $path;
    }

    private function isArtifactPdfPath(LetterDocumentArtifact $artifact, string $path): bool
    {
        return str_starts_with(
            $path,
            'letter-document-artifacts/' . $artifact->letter_type . '/' . $artifact->application_id . '/',
        ) && str_ends_with(strtolower($path), '.pdf');
    }
}
