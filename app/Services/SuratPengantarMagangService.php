<?php

namespace App\Services;

use App\Models\SuratPengantarMagangApplication;
use App\Models\User;

class SuratPengantarMagangService
{
    public function __construct(private LetterAssignmentService $assignmentService)
    {
    }

    /**
     * Assign the application to an active Tendik Persuratan responsible for magang letters.
     */
    public function assignApplication(SuratPengantarMagangApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik(
            $application,
            SuratPengantarMagangApplication::LETTER_TYPE,
        );
    }
}
