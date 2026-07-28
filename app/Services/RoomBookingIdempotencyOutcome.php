<?php

namespace App\Services;

final class RoomBookingIdempotencyOutcome
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly array $body,
        public readonly int $statusCode,
        public readonly bool $replayed,
    ) {}
}
