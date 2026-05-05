<?php

namespace App\Support;

final class LetterWorkflowStatus
{
    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const REVISION = 'Revision';
    public const REJECTED = 'Rejected';
    public const APPROVED_TENDIK = 'Approved_Tendik';
    public const APPROVED_KAPRODI = 'Approved_Kaprodi';
    public const READY_FOR_STUDENT_REVIEW = 'Ready_For_Student_Review';
    public const COMPLETED = 'Completed';

    public const ALL = [
        self::DRAFT,
        self::SUBMITTED,
        self::REVISION,
        self::REJECTED,
        self::APPROVED_TENDIK,
        self::APPROVED_KAPRODI,
        self::READY_FOR_STUDENT_REVIEW,
        self::COMPLETED,
    ];

    public static function values(): array
    {
        return self::ALL;
    }
}
