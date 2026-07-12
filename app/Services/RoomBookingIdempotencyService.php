<?php

namespace App\Services;

use App\Models\RoomBookingIdempotencyRecord;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hashed, actor/action/subject-scoped idempotency for C7B2 booking mutations.
 * Lock order begins here: idempotency record, booking, cancellation request,
 * then room when a status decision requires it.
 */
class RoomBookingIdempotencyService
{
    public const RESPONSE_SCHEMA_VERSION = 1;

    private const REPLAY_STRING_MAX = 10000;

    /**
     * Defense-in-depth on top of the positive schema: keys are canonicalized
     * (lowercased, non-alphanumerics stripped) before comparison, so
     * storage_path / storagePath / Storage-Path all match the same stem.
     */
    private const FORBIDDEN_CANONICAL_KEYS = [
        'storagepath',
        'storagedisk',
        'disk',
        'checksum',
        'checksumsha256',
        'attachmentchecksum',
        'payloadchecksum',
        'safemetadata',
        'internalnote',
        'idempotencykey',
        'idempotencykeyhash',
        'password',
        'token',
        'secret',
        'exception',
        'exceptionmessage',
        'trace',
        'stacktrace',
        'attributes',
        'raw',
        'original',
        'model',
    ];

    /**
     * @param  array<string, mixed>  $canonicalPayload
     * @param  callable(string): array{status_code: int, payload: array<string, mixed>}  $operation
     * @param  callable(array<string, mixed>): array<string, mixed>  $responseBody
     */
    public function execute(
        User $actor,
        RoomBookingRequest $booking,
        string $action,
        string $subjectKey,
        string $idempotencyKey,
        array $canonicalPayload,
        callable $operation,
        ?callable $responseBody = null,
    ): RoomBookingIdempotencyOutcome {
        $keyHash = hash_hmac('sha256', $idempotencyKey, $this->hashKey());
        $payloadHash = $this->payloadHash($canonicalPayload);
        $responseBody ??= fn (array $result): array => $this->defaultResponseBody($result);
        $scope = [
            'actor_identity_snapshot' => 'user:'.$actor->id,
            'action' => $action,
            'subject_key' => $subjectKey,
            'idempotency_key_hash' => $keyHash,
        ];

        $this->discardExpiredCompletedRecord($scope);

        return DB::transaction(function () use (
            $actor,
            $booking,
            $scope,
            $payloadHash,
            $operation,
            $responseBody,
        ) {
            $now = $this->now();

            RoomBookingIdempotencyRecord::query()->insertOrIgnore(array_merge($scope, [
                'actor_id' => $actor->id,
                'room_booking_request_id' => $booking->id,
                'payload_hash' => $payloadHash,
                'expires_at' => $now->copy()->addHours(max(
                    1,
                    (int) config('room_booking.idempotency_retention_hours', 168),
                )),
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $record = RoomBookingIdempotencyRecord::query()
                ->where($scope)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals((string) $record->payload_hash, $payloadHash)) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::IDEMPOTENCY_KEY_REUSED,
                    'Kunci idempotensi sudah digunakan dengan data yang berbeda.',
                );
            }

            if ($record->completed_at !== null) {
                $body = $this->validatedStoredResponse($record, (int) $booking->id);

                return new RoomBookingIdempotencyOutcome(
                    body: $body,
                    statusCode: (int) $record->result_status_code,
                    replayed: true,
                );
            }

            $correlationId = $this->correlationId();
            $result = $operation($correlationId);
            $resultPayload = $result['payload'];
            $resultPayload['correlation_id'] = $correlationId;
            $statusCode = $this->validatedStatusCode($result['status_code'] ?? null);
            $safeBody = $this->validatedResponseBody(
                $responseBody($resultPayload),
                (int) $booking->id,
            );

            $record->forceFill([
                'result_status_code' => $statusCode,
                'response_schema_version' => self::RESPONSE_SCHEMA_VERSION,
                'safe_response_body' => $safeBody,
                'completed_at' => $this->now(),
            ])->save();

            return new RoomBookingIdempotencyOutcome(
                body: $safeBody,
                statusCode: $statusCode,
                replayed: false,
            );
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        $canonical = $this->canonicalize($payload);

        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array<string, string> $scope */
    private function discardExpiredCompletedRecord(array $scope): void
    {
        DB::transaction(function () use ($scope): void {
            $record = RoomBookingIdempotencyRecord::query()
                ->where($scope)
                ->lockForUpdate()
                ->first();

            if (
                $record?->completed_at !== null
                && $record->expires_at !== null
                && $record->expires_at->lessThanOrEqualTo($this->now())
            ) {
                $record->delete();
            }
        }, 3);
    }

    /** @return array<string, mixed> */
    private function validatedStoredResponse(
        RoomBookingIdempotencyRecord $record,
        int $bookingId,
    ): array {
        if (
            $record->response_schema_version !== self::RESPONSE_SCHEMA_VERSION
            || ! is_int($record->result_status_code)
            || $record->result_status_code < 200
            || $record->result_status_code >= 300
            || ! is_array($record->safe_response_body)
            || $record->expires_at === null
        ) {
            throw new RuntimeException('Invalid stored room-booking idempotency outcome.');
        }

        return $this->validatedResponseBody($record->safe_response_body, $bookingId);
    }

    private function validatedStatusCode(mixed $statusCode): int
    {
        if (! is_int($statusCode) || $statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Invalid room-booking idempotency response status.');
        }

        return $statusCode;
    }

    /**
     * Service-level callers receive a compact safe result. HTTP controllers
     * provide the complete API projection callback used by client responses.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function defaultResponseBody(array $result): array
    {
        return [
            'message' => $result['message'],
            'data' => [
                'booking' => ['id' => (int) $result['booking_id']],
                'stored_status' => $result['stored_status'],
                'effective_status' => $result['effective_status'],
                'workflow_version' => (int) $result['workflow_version'],
                'cancellation_request_id' => $result['cancellation_request_id'] ?? null,
                'cancellation_request_status' => $result['cancellation_request_status'] ?? null,
                'correlation_id' => $result['correlation_id'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function validatedResponseBody(array $body, int $bookingId): array
    {
        if (
            array_keys($body) !== ['message', 'data']
            || ! is_string($body['message'])
            || trim($body['message']) === ''
            || ! is_array($body['data'])
            || ! isset($body['data']['booking'])
            || ! is_array($body['data']['booking'])
            || (int) ($body['data']['booking']['id'] ?? 0) !== $bookingId
            || ! is_string($body['data']['correlation_id'] ?? null)
            || ! Str::isUuid($body['data']['correlation_id'])
        ) {
            throw new RuntimeException('Invalid room-booking idempotency response schema.');
        }

        $this->assertReplaySchema($body, $this->replaySchema(), 'body');
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (strlen($json) > 262144) {
            throw new RuntimeException('Room-booking idempotency response is too large.');
        }

        $normalized = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($normalized)) {
            throw new RuntimeException('Invalid room-booking idempotency response body.');
        }

        return $normalized;
    }

    /**
     * Fail-closed positive schema for response_schema_version 1. Every
     * associative key must be declared here; unknown keys are rejected, so
     * nested sensitive content cannot pass under alternate or unlisted names.
     * All declared keys are optional-but-typed: both the compact service
     * body and the full HTTP projection validate against the same contract.
     *
     * Node forms: scalar token ('int', 'int?', 'str', 'str?', 'bool',
     * 'uuid'), ['@object' => [key => spec]], ['@list' => itemSpec], and
     * ['@nullable' => spec].
     *
     * @return array<string, mixed>
     */
    private function replaySchema(): array
    {
        $capabilities = ['@object' => [
            'can_edit' => 'bool',
            'can_resubmit' => 'bool',
            'can_cancel' => 'bool',
            'can_review' => 'bool',
            'can_start_review' => 'bool',
            'can_approve' => 'bool',
            'can_request_revision' => 'bool',
            'can_reject' => 'bool',
            'can_view_attachment' => 'bool',
            'can_withdraw' => 'bool',
            'can_request_cancellation' => 'bool',
            'can_withdraw_cancellation_request' => 'bool',
            'can_decide_cancellation' => 'bool',
            'withdrawal_block_reason' => 'str?',
            'next_action' => 'str?',
        ]];
        $cancellationRequest = ['@nullable' => ['@object' => [
            'id' => 'int',
            'status' => 'str',
            'reason' => 'str?',
            'requested_at' => 'str?',
            'decision_note' => 'str?',
            'decided_at' => 'str?',
            'responsible_role' => 'str',
            'available_applicant_action' => 'str?',
        ]]];
        $booking = ['@object' => [
            'id' => 'int',
            'room' => ['@object' => [
                'id' => 'int',
                'code' => 'str',
                'name' => 'str',
                'type' => 'str',
                'capacity' => 'int',
                'location' => 'str?',
                'description' => 'str?',
                'is_active' => 'bool',
                'owning_laboratory' => ['@nullable' => ['@object' => [
                    'id' => 'int',
                    'code' => 'str',
                    'name' => 'str',
                ]]],
            ]],
            'activity_name' => 'str',
            'purpose' => 'str',
            'participant_count' => 'int',
            'start_at' => 'str',
            'end_at' => 'str',
            'status' => 'str',
            'stored_status' => 'str',
            'workflow_version' => 'int',
            'submission_iteration' => 'int',
            'effective_status' => 'str',
            'is_expired' => 'bool',
            'is_completed' => 'bool',
            'review_started_at' => 'str?',
            'cancellation_pending' => 'bool',
            'cancellation_request' => $cancellationRequest,
            'capabilities' => $capabilities,
            'reviewer' => ['@nullable' => ['@object' => ['id' => 'int', 'name' => 'str']]],
            'reviewed_at' => 'str?',
            'revision_note' => 'str?',
            'rejection_reason' => 'str?',
            'cancellation_reason' => 'str?',
            'cancellation_source' => 'str?',
            'cancelled_by_role_snapshot' => 'str?',
            'created_at' => 'str?',
            'updated_at' => 'str?',
            'surat_peminjaman_pdf' => ['@nullable' => ['@object' => [
                'exists' => 'bool',
                'has_surat_peminjaman_pdf' => 'bool',
                'original_name' => 'str?',
                'size_bytes' => 'int?',
                'uploaded_at' => 'str?',
                'preview_url' => 'str?',
                'download_url' => 'str?',
            ]]],
            'status_histories' => ['@list' => ['@object' => [
                'id' => 'int',
                'from_status' => 'str?',
                'to_status' => 'str',
                'actor' => ['@nullable' => ['@object' => ['id' => 'int', 'name' => 'str']]],
                'note' => 'str?',
                'created_at' => 'str?',
            ]]],
            'requester' => ['@nullable' => ['@object' => [
                'id' => 'int',
                'name' => 'str',
                'email' => 'str',
            ]]],
            'conflict_status' => 'str',
            'has_conflict' => 'bool',
            'conflict_level' => 'str',
            'conflict_message' => 'str?',
            'conflicts' => ['@list' => ['@object' => [
                'booking_id' => 'int',
                'room_id' => 'int',
                'room_name' => 'str?',
                'start_at' => 'str',
                'end_at' => 'str',
                'status' => 'str',
                'requester_name' => 'str?',
                'activity_name' => 'str?',
                'purpose' => 'str?',
            ]]],
        ]];

        return ['@object' => [
            'message' => 'str',
            'data' => ['@object' => [
                'booking' => $booking,
                'stored_status' => 'str',
                'effective_status' => 'str',
                'workflow_version' => 'int',
                'capabilities' => $capabilities,
                'cancellation_request' => $cancellationRequest,
                'cancellation_pending' => 'bool',
                'notification_state' => 'str',
                'correlation_id' => 'uuid',
                'cancellation_request_id' => 'int?',
                'cancellation_request_status' => 'str?',
            ]],
        ]];
    }

    private function assertReplaySchema(mixed $value, mixed $spec, string $path): void
    {
        if (is_array($spec) && array_key_exists('@nullable', $spec)) {
            if ($value === null) {
                return;
            }
            $spec = $spec['@nullable'];
        }

        if (is_string($spec)) {
            $this->assertReplayScalar($value, $spec, $path);

            return;
        }

        if (is_array($spec) && array_key_exists('@list', $spec)) {
            if (! is_array($value) || ! array_is_list($value)) {
                throw new RuntimeException("Unsafe room-booking idempotency response field ({$path}).");
            }
            foreach ($value as $index => $item) {
                $this->assertReplaySchema($item, $spec['@list'], "{$path}.{$index}");
            }

            return;
        }

        if (is_array($spec) && array_key_exists('@object', $spec)) {
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new RuntimeException("Unsafe room-booking idempotency response field ({$path}).");
            }
            $allowed = $spec['@object'];
            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    throw new RuntimeException("Unsafe room-booking idempotency response field ({$path}).");
                }
                $canonical = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
                if (in_array($canonical, self::FORBIDDEN_CANONICAL_KEYS, true)) {
                    throw new RuntimeException("Unsafe room-booking idempotency response field ({$path}.{$key}).");
                }
                if (! array_key_exists($key, $allowed)) {
                    throw new RuntimeException("Unknown room-booking idempotency response field ({$path}.{$key}).");
                }
                $this->assertReplaySchema($item, $allowed[$key], "{$path}.{$key}");
            }

            return;
        }

        throw new RuntimeException("Invalid room-booking idempotency response schema node ({$path}).");
    }

    private function assertReplayScalar(mixed $value, string $token, string $path): void
    {
        $valid = match ($token) {
            'int' => is_int($value),
            'int?' => $value === null || is_int($value),
            'bool' => is_bool($value),
            'str' => is_string($value) && strlen($value) <= self::REPLAY_STRING_MAX,
            'str?' => $value === null
                || (is_string($value) && strlen($value) <= self::REPLAY_STRING_MAX),
            'uuid' => is_string($value) && Str::isUuid($value),
            default => false,
        };

        if (! $valid) {
            throw new RuntimeException("Unsafe room-booking idempotency response field ({$path}).");
        }
    }

    private function correlationId(): string
    {
        $header = request()?->header('X-Request-Id');

        return is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid();
    }

    private function hashKey(): string
    {
        $key = (string) config('app.key');

        return $key !== '' ? $key : 'room-booking-idempotency-test-key';
    }

    private function now(): Carbon
    {
        return Carbon::now(config('app.timezone'));
    }
}
