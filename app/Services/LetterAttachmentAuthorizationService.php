<?php

namespace App\Services;

use App\Models\User;
use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use Illuminate\Database\Eloquent\Model;

class LetterAttachmentAuthorizationService
{
    public function __construct(
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $routingService,
    ) {
    }

    public function canPreview(User $user, Model $application, string $letterType): bool
    {
        $canonicalType = LetterTypeRegistry::canonicalize($letterType);
        $letter = LetterAttachmentDefinitionRegistry::forLetter($letterType);
        $modelClass = $letter['application_model'] ?? null;

        if (
            !$canonicalType
            || !is_string($modelClass)
            || !$application instanceof $modelClass
            || !$application->exists
            || $application->getKey() === null
        ) {
            return false;
        }

        return match ($user->role) {
            'super_admin' => true,
            'tendik' => $this->assignmentService->canHandle($user, $canonicalType),
            'akademik' => $this->routingService->canHandleProdiStage($user, $application)
                || $this->routingService->canHandleDepartmentStage($user, $application),
            'mahasiswa' => (int) $application->getAttribute('user_id') === (int) $user->id,
            default => false,
        };
    }
}
