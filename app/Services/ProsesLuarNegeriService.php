<?php

namespace App\Services;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\User;

class ProsesLuarNegeriService
{
    public function __construct(
        private LetterAssignmentService $assignmentService
    )
    {
    }

    public function assignApplication(ProsesLuarNegeriApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik($application, ProsesLuarNegeriApplication::LETTER_TYPE);
    }
}
