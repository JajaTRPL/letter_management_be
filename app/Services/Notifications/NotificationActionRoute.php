<?php

namespace App\Services\Notifications;

/**
 * Allowlisted deep-link route KEYS. A notification never stores a raw or
 * external URL; it stores one of these stable keys plus a public subject id,
 * and the frontend registry maps the key to a real in-app route that
 * re-authorizes the current user on arrival. Anything not on this list is
 * rejected before a notification is written.
 */
final class NotificationActionRoute
{
    public const MAHASISWA_LETTER_DETAIL = 'mahasiswa.letter.detail';

    public const MAHASISWA_BOOKING_DETAIL = 'mahasiswa.booking.detail';

    public const MAHASISWA_BOOKING_OCCURRENCE = 'mahasiswa.booking.occurrence';

    public const PERSURATAN_LETTER_QUEUE = 'persuratan.letter.queue';

    public const PERSURATAN_LETTER_DETAIL = 'persuratan.letter.detail';

    public const AKADEMIK_LETTER_QUEUE = 'akademik.letter.queue';

    public const AKADEMIK_LETTER_DETAIL = 'akademik.letter.detail';

    public const SARPRAS_BOOKING_REVIEW = 'sarpras.booking.review';

    public const SARPRAS_OPERATIONS = 'sarpras.operations';

    public const LABORAN_OPERATIONS = 'laboran.operations';

    public const KALAB_BOOKING_REVIEW = 'kalab.booking.review';

    public const KALAB_OPERATIONS = 'kalab.operations';

    public const SUPERADMIN_HEALTH = 'superadmin.health';

    private const ALL = [
        self::MAHASISWA_LETTER_DETAIL,
        self::MAHASISWA_BOOKING_DETAIL,
        self::MAHASISWA_BOOKING_OCCURRENCE,
        self::PERSURATAN_LETTER_QUEUE,
        self::PERSURATAN_LETTER_DETAIL,
        self::AKADEMIK_LETTER_QUEUE,
        self::AKADEMIK_LETTER_DETAIL,
        self::SARPRAS_BOOKING_REVIEW,
        self::SARPRAS_OPERATIONS,
        self::LABORAN_OPERATIONS,
        self::KALAB_BOOKING_REVIEW,
        self::KALAB_OPERATIONS,
        self::SUPERADMIN_HEALTH,
    ];

    public static function isAllowed(?string $key): bool
    {
        return $key === null || in_array($key, self::ALL, true);
    }
}
