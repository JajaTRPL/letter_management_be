<?php

namespace App\Services;

use App\Models\User;
use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LetterDocumentAccessService
{
    public function ensureOwner(Model $application, User $user): void
    {
        abort_unless((int) $application->getAttribute('user_id') === (int) $user->id, 403);
    }

    public function canPreview(Model $application): bool
    {
        return in_array($application->getAttribute('status'), [
            LetterWorkflowStatus::READY_FOR_STUDENT_REVIEW,
            LetterWorkflowStatus::COMPLETED,
        ], true);
    }

    public function canComplete(Model $application): bool
    {
        return $application->getAttribute('status') === LetterWorkflowStatus::READY_FOR_STUDENT_REVIEW;
    }

    public function canDownload(Model $application, string $pathAttribute, ?string $requiredPrefix = null): bool
    {
        return $application->getAttribute('status') === LetterWorkflowStatus::COMPLETED
            && $this->generatedDocumentExists($application, $pathAttribute, $requiredPrefix);
    }

    public function hasBeenPreviewed(Model $application): bool
    {
        return (bool) $application->getAttribute('student_reviewed_at');
    }

    public function markPreviewedIfNeeded(Model $application): void
    {
        if (
            $application->getAttribute('status') === LetterWorkflowStatus::READY_FOR_STUDENT_REVIEW
            && !$application->getAttribute('student_reviewed_at')
        ) {
            $application->forceFill([
                'student_reviewed_at' => now(),
            ])->save();
        }
    }

    public function redactGeneratedPathIfNeeded(
        Model $application,
        string $pathAttribute,
        ?string $requiredPrefix = null,
        bool $requireExistingFile = false
    ): Model {
        if (
            $application->getAttribute('status') !== LetterWorkflowStatus::COMPLETED
            || ($requireExistingFile && !$this->generatedDocumentExists($application, $pathAttribute, $requiredPrefix))
        ) {
            $application->setAttribute($pathAttribute, null);
        }

        return $application;
    }

    public function resolveGeneratedDocumentPath(
        Model $application,
        string $pathAttribute,
        ?string $requiredPrefix = null
    ): ?string {
        $path = $this->publicStoragePath($application->getAttribute($pathAttribute));
        if (!$path || ($requiredPrefix && !str_starts_with($path, $requiredPrefix))) {
            return null;
        }

        if (!Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->path($path);
    }

    public function generatedDocumentExists(
        Model $application,
        string $pathAttribute,
        ?string $requiredPrefix = null
    ): bool {
        return $this->resolveGeneratedDocumentPath($application, $pathAttribute, $requiredPrefix) !== null;
    }

    private function publicStoragePath(?string $filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        $path = parse_url($filePath, PHP_URL_PATH) ?: $filePath;
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
