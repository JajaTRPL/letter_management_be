<?php

namespace App\Services;

use App\Models\SuratKeteranganAktifApplication;
use App\Models\User;

class SuratKeteranganAktifService
{
    public function __construct(
        private LetterAssignmentService $assignmentService
    )
    {
    }

    public function assignApplication(SuratKeteranganAktifApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik($application, SuratKeteranganAktifApplication::LETTER_TYPE);
    }
}
