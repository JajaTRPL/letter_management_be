<?php

namespace App\Services;

use App\Models\SuratTugasApplication;
use App\Models\User;

class SuratTugasService
{
    public function __construct(private LetterAssignmentService $assignmentService)
    {
    }

    /**
     * Assign the application to an active Tendik Persuratan responsible for
     * Surat Tugas letters, via the shared canonical assignment algorithm.
     */
    public function assignApplication(SuratTugasApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik(
            $application,
            SuratTugasApplication::LETTER_TYPE,
        );
    }
}
