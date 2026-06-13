<?php

namespace App\Enums;

/**
 * Exhaustive classification of a single legacy supporting-document candidate
 * evaluated by the D2C backfill planner. Every candidate resolves to exactly
 * one of these states. Only READY_TO_COPY is acted on by a future --execute run.
 */
enum LetterAttachmentBackfillClassification: string
{
    /** Real legacy file present + safe + no registry row yet → copyable. */
    case READY_TO_COPY = 'READY_TO_COPY';

    /** Registry row already exists whose checksum matches the legacy source. */
    case ALREADY_BACKFILLED_MATCH = 'ALREADY_BACKFILLED_MATCH';

    /** Legacy column holds a D2B attachment:// marker and a valid registry row exists. */
    case MARKER_BACKED_REGISTRY_OK = 'MARKER_BACKED_REGISTRY_OK';

    /** Legacy column holds a marker but NO registry row backs it → fail closed. */
    case MARKER_WITHOUT_REGISTRY_BLOCKER = 'MARKER_WITHOUT_REGISTRY_BLOCKER';

    /** Legacy column is null/empty → nothing to back-fill. */
    case LEGACY_VALUE_EMPTY = 'LEGACY_VALUE_EMPTY';

    /** Legacy column has already been retired from the local schema. */
    case RETIRED_COLUMN_ABSENT = 'RETIRED_COLUMN_ABSENT';

    /** Legacy value resolves to a safe path under the prefix, but the file is gone. */
    case SOURCE_FILE_MISSING = 'SOURCE_FILE_MISSING';

    /** Legacy value normalizes safely but does not sit under the expected legacy prefix. */
    case SOURCE_PREFIX_INVALID = 'SOURCE_PREFIX_INVALID';

    /** Legacy value contains traversal, a null byte, or other unsafe path content. */
    case SOURCE_PATH_UNSAFE = 'SOURCE_PATH_UNSAFE';

    /** Source file exists but its server-detected MIME is not an allowed PDF. */
    case SOURCE_MIME_INVALID = 'SOURCE_MIME_INVALID';

    /** A registry row exists but its checksum disagrees with the legacy source. */
    case REGISTRY_CONFLICT = 'REGISTRY_CONFLICT';

    /** A registry row exists with no checksum to compare; ambiguous, do not overwrite. */
    case DESTINATION_CONFLICT = 'DESTINATION_CONFLICT';

    /** The (letter_type, document_key) pair is not an active registry definition. */
    case UNKNOWN_DEFINITION = 'UNKNOWN_DEFINITION';

    /** Explicitly excluded from backfill (e.g. dormant KTM) — never planned. */
    case SKIPPED_EXCLUDED = 'SKIPPED_EXCLUDED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** States that would result in a copy under a future --execute run. */
    public function isActionable(): bool
    {
        return $this === self::READY_TO_COPY;
    }

    /** States that must block an execute run until an operator resolves them. */
    public function isBlocker(): bool
    {
        return match ($this) {
            self::MARKER_WITHOUT_REGISTRY_BLOCKER,
            self::REGISTRY_CONFLICT,
            self::DESTINATION_CONFLICT,
            self::SOURCE_MIME_INVALID,
            self::SOURCE_PATH_UNSAFE => true,
            default => false,
        };
    }
}
