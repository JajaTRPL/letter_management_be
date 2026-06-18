<?php

namespace App\Services;

use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LetterRetentionApplicationRepository
{
    /**
     * @return array<string, class-string<Model>>
     */
    public function applicationModels(?string $letterType = null): array
    {
        $definitions = LetterAttachmentDefinitionRegistry::all();
        if ($letterType !== null && $letterType !== '') {
            $canonical = LetterTypeRegistry::canonicalize($letterType);
            $definitions = $canonical && isset($definitions[$canonical])
                ? [$canonical => $definitions[$canonical]]
                : [];
        }

        $models = [];
        foreach ($definitions as $canonicalType => $definition) {
            $modelClass = $definition['application_model'] ?? null;
            if (is_string($modelClass) && is_a($modelClass, Model::class, true)) {
                /** @var class-string<Model> $modelClass */
                $models[$canonicalType] = $modelClass;
            }
        }

        return $models;
    }

    public function completedAt(string $letterType, int $applicationId): ?Carbon
    {
        $models = $this->applicationModels($letterType);
        $modelClass = $models[LetterTypeRegistry::canonicalize($letterType) ?? $letterType] ?? null;
        if (!$modelClass) {
            return null;
        }

        $application = $modelClass::query()
            ->whereKey($applicationId)
            ->where('status', LetterWorkflowStatus::COMPLETED)
            ->whereNotNull('completed_at')
            ->first();

        $completedAt = $application?->getAttribute('completed_at');

        return $completedAt instanceof Carbon ? $completedAt : null;
    }
}
