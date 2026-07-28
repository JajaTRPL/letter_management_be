<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A single intent to notify one recipient. Immutable value object assembled by
 * the projector and handed to NotificationWriter. Carries only recipient-safe
 * snapshots — never raw storage paths, checksums, internal actor ids, document
 * content, private purpose, reviewer notes, or applicant identity beyond what
 * the recipient is authorized to see.
 */
final class NotificationIntent
{
    public function __construct(
        public readonly User $recipient,
        public readonly string $eventType,
        public readonly NotificationCategory $category,
        public readonly NotificationPriority $priority,
        public readonly string $title,
        public readonly string $body,
        public readonly string $dedupKey,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectPublicId = null,
        public readonly ?string $actionRouteKey = null,
        public readonly ?string $actionLabel = null,
        public readonly ?Carbon $occurredAt = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly int $schemaVersion = 1,
        /** Dedup-key prefixes of older unresolved notifications this one supersedes. */
        public readonly array $supersedesDedupPrefixes = [],
    ) {}
}
