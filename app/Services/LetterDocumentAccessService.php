<?php

namespace App\Services;

use App\Models\User;
use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Model;

class LetterDocumentAccessService
{
    public function ensureOwner(Model $application, User $user): void
    {
        abort_unless((int) $application->getAttribute('user_id') === (int) $user->id, 403);
    }

    public function canComplete(Model $application): bool
    {
        return $application->getAttribute('status') === LetterWorkflowStatus::READY_FOR_STUDENT_REVIEW;
    }
}
