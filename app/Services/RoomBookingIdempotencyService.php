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
    public const RESPONSE_SCHEMA_VERSION = 2;

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
        int $transactionAttempts = 3,
    ): RoomBookingIdempotencyOutcome {
        $keyHash = hash_hmac('sha256', $idempotencyKey, $this->hashKey());
        $payloadHash = $this->payloadHash($canonicalPayload);
        $initialBookingId = $booking->exists ? (int) $booking->id : null;
        $applicantProjection = $actor->role === 'mahasiswa';
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
            $initialBookingId,
            $applicantProjection,
        ) {
            $now = $this->now();

            RoomBookingIdempotencyRecord::query()->insertOrIgnore(array_merge($scope, [
                'actor_id' => $actor->id,
                'room_booking_request_id' => $initialBookingId,
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
                $storedBookingId = (int) ($record->room_booking_request_id ?? 0);
                if ($storedBookingId < 1) {
                    throw new RuntimeException('Invalid stored room-booking idempotency subject.');
                }
                $body = $this->validatedStoredResponse(
                    $record,
                    $storedBookingId,
                    $applicantProjection,
                );

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
            $resultBookingId = (int) ($resultPayload['booking_id'] ?? 0);
            if (
                $resultBookingId < 1
                || ($initialBookingId !== null && $resultBookingId !== $initialBookingId)
            ) {
                throw new RuntimeException('Invalid room-booking idempotency result subject.');
            }
            $statusCode = $this->validatedStatusCode($result['status_code'] ?? null);
            $safeBody = $this->validatedResponseBody(
                $responseBody($resultPayload),
                $resultBookingId,
                $applicantProjection,
            );

            $record->forceFill([
                'room_booking_request_id' => $resultBookingId,
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
        }, max(1, $transactionAttempts));
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
        bool $applicantProjection,
    ): array {
        $schemaVersion = $record->response_schema_version;
        if (
            ! in_array($schemaVersion, [1, self::RESPONSE_SCHEMA_VERSION], true)
            || ! is_int($record->result_status_code)
            || $record->result_status_code < 200
            || $record->result_status_code >= 300
            || ! is_array($record->safe_response_body)
            || $record->expires_at === null
        ) {
            throw new RuntimeException('Invalid stored room-booking idempotency outcome.');
        }

        $body = $record->safe_response_body;
        if ($schemaVersion === 1 && $applicantProjection) {
            $body = $this->minimizeLegacyApplicantResponse($body);
        }
        $validated = $this->validatedResponseBody(
            $body,
            $bookingId,
            $applicantProjection,
        );

        if ($schemaVersion !== self::RESPONSE_SCHEMA_VERSION) {
            $record->forceFill([
                'response_schema_version' => self::RESPONSE_SCHEMA_VERSION,
                'safe_response_body' => $validated,
            ])->save();
        }

        return $validated;
    }

    /**
     * One-time fail-safe upgrade for pre-C7B3 applicant outcomes. Operational
     * staff bodies retain their authorized fields; applicant bodies lose
     * internal identities before they can be replayed or persisted as v2.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function minimizeLegacyApplicantResponse(array $body): array
    {
        if (! isset($body['data']) || ! is_array($body['data'])) {
            return $body;
        }

        $booking = array_key_exists('booking', $body['data'])
            ? ($body['data']['booking'] ?? null)
            : $body['data'];
        if (! is_array($booking)) {
            return $body;
        }

        unset(
            $booking['cancelled_by_role_snapshot'],
            $booking['requester'],
        );
        if (isset($booking['reviewer']) && is_array($booking['reviewer'])) {
            unset($booking['reviewer']['id']);
        }
        if (isset($booking['status_histories']) && is_array($booking['status_histories'])) {
            foreach ($booking['status_histories'] as &$history) {
                if (is_array($history) && isset($history['actor']) && is_array($history['actor'])) {
                    unset($history['actor']['id']);
                }
            }
            unset($history);
        }
        if (isset($booking['conflicts']) && is_array($booking['conflicts'])) {
            foreach ($booking['conflicts'] as &$conflict) {
                if (is_array($conflict)) {
                    unset(
                        $conflict['requester_name'],
                        $conflict['activity_name'],
                        $conflict['purpose'],
                    );
                }
            }
            unset($conflict);
        }

        if (array_key_exists('booking', $body['data'])) {
            $body['data']['booking'] = $booking;
        } else {
            $body['data'] = $booking;
        }

        return $body;
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
    private function validatedResponseBody(
        array $body,
        int $bookingId,
        bool $applicantProjection,
    ): array
    {
        $data = $body['data'] ?? null;
        $mutationShape = is_array($data) && array_key_exists('booking', $data);
        $responseBooking = $mutationShape ? ($data['booking'] ?? null) : $data;
        if (
            array_keys($body) !== ['message', 'data']
            || ! is_string($body['message'])
            || trim($body['message']) === ''
            || ! is_array($data)
            || ! is_array($responseBooking)
            || (int) ($responseBooking['id'] ?? 0) !== $bookingId
            || ! is_string($data['correlation_id'] ?? null)
            || ! Str::isUuid($data['correlation_id'])
        ) {
            throw new RuntimeException('Invalid room-booking idempotency response schema.');
        }

        $schema = $mutationShape
            ? $this->replaySchema($applicantProjection)
            : $this->initialSubmissionReplaySchema($applicantProjection);
        $this->assertReplaySchema($body, $schema, 'body');
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
     * Fail-closed positive schema for response_schema_version 2. Applicant
     * replays use a narrower projection than authorized staff replays. Every
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
    private function replaySchema(bool $applicantProjection): array
    {
        $capabilities = $this->capabilitiesReplaySchema();
        $cancellationRequest = $this->cancellationRequestReplaySchema();
        $booking = ['@object' => $this->bookingReplayFields(
            $applicantProjection,
            $capabilities,
            $cancellationRequest,
        )];

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

    /** @return array<string, mixed> */
    private function initialSubmissionReplaySchema(bool $applicantProjection): array
    {
        $fields = $this->bookingReplayFields(
            $applicantProjection,
            $this->capabilitiesReplaySchema(),
            $this->cancellationRequestReplaySchema(),
        );
        $fields['correlation_id'] = 'uuid';

        return ['@object' => [
            'message' => 'str',
            'data' => ['@object' => $fields],
        ]];
    }

    /** @return array<string, mixed> */
    private function capabilitiesReplaySchema(): array
    {
        return ['@object' => [
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
    }

    /** @return array<string, mixed> */
    private function cancellationRequestReplaySchema(): array
    {
        return ['@nullable' => ['@object' => [
            'id' => 'int',
            'status' => 'str',
            'reason' => 'str?',
            'requested_at' => 'str?',
            'decision_note' => 'str?',
            'decided_at' => 'str?',
            'responsible_role' => 'str',
            'available_applicant_action' => 'str?',
        ]]];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $cancellationRequest
     * @return array<string, mixed>
     */
    private function bookingReplayFields(
        bool $applicantProjection,
        array $capabilities,
        array $cancellationRequest,
    ): array {
        $reviewerFields = $applicantProjection
            ? ['name' => 'str']
            : ['id' => 'int', 'name' => 'str'];
        $historyActorFields = $applicantProjection
            ? ['name' => 'str']
            : ['id' => 'int', 'name' => 'str'];

        $fields = [
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
            'booking_mode' => 'str',
            'occurrence_end_date' => 'str',
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
            'reviewer' => ['@nullable' => ['@object' => $reviewerFields]],
            'reviewed_at' => 'str?',
            'revision_note' => 'str?',
            'rejection_reason' => 'str?',
            'cancellation_reason' => 'str?',
            'cancellation_source' => 'str?',
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
            'occurrences' => ['@list' => ['@object' => $this->occurrenceReplayFields(
                $applicantProjection,
            )]],
            'occurrence_summary' => ['@object' => [
                'total' => 'int',
                'completed' => 'int',
                'progress_label' => 'str',
                'next_action' => 'str?',
                'nearest_deadline' => 'str?',
            ]],
            'usage_timeline' => ['@list' => ['@object' => [
                'type' => 'str',
                'occurred_at' => 'str?',
                'label' => 'str?',
                'actor' => ['@object' => ['name' => 'str', 'role' => 'str']],
                'occurrence_ref' => 'str?',
            ]]],
            'status_histories' => ['@list' => ['@object' => [
                'id' => 'int',
                'from_status' => 'str?',
                'to_status' => 'str',
                'actor' => ['@nullable' => ['@object' => $historyActorFields]],
                'note' => 'str?',
                'created_at' => 'str?',
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
            ]]],
        ];

        if (! $applicantProjection) {
            $fields['cancelled_by_role_snapshot'] = 'str?';
            $fields['requester'] = ['@nullable' => ['@object' => [
                'id' => 'int',
                'name' => 'str',
                'email' => 'str',
            ]]];
            $fields['conflicts']['@list']['@object'] = array_merge(
                $fields['conflicts']['@list']['@object'],
                [
                    'requester_name' => 'str?',
                    'activity_name' => 'str?',
                    'purpose' => 'str?',
                ],
            );
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function occurrenceReplayFields(bool $applicantProjection): array
    {
        $returnFields = [
            'return_ref' => 'str',
            'status' => 'str',
            'version' => 'int',
            'submitted_at' => 'str',
            'decision_note' => 'str?',
            'key_received_at' => 'str?',
            'verified_at' => 'str?',
            'evidence' => ['@object' => [
                'original_name' => 'str',
                'mime' => 'str',
                'size_bytes' => 'int',
                'preview_url' => 'str',
                'download_url' => 'str',
            ]],
        ];
        if (! $applicantProjection) {
            $returnFields['verified_by'] = ['@nullable' => ['@object' => [
                'name' => 'str?', 'role' => 'str?',
            ]]];
            $returnFields['received_time_change_reason'] = 'str?';
        }

        $fields = [
            'occurrence_ref' => 'str',
            'sequence' => 'int',
            'date' => 'str',
            'start_at' => 'str',
            'end_at' => 'str',
            'return_due_at' => 'str',
            'version' => 'int',
            'operational_status' => 'str',
            'key_issuance' => ['@object' => [
                'issued' => 'bool',
                'issued_at' => 'str?',
                'issued_by' => ['@nullable' => ['@object' => [
                    'name' => 'str?', 'role' => 'str?',
                ]]],
            ]],
            'return' => ['@nullable' => ['@object' => $returnFields]],
            'capabilities' => ['@object' => [
                'can_submit_return' => 'bool',
                'can_withdraw_return' => 'bool',
                'can_resubmit_return' => 'bool',
            ]],
            'event_hooks' => ['@list' => ['@object' => [
                'type' => 'str', 'at' => 'str',
            ]]],
        ];
        if (! $applicantProjection) {
            $fields['return_history'] = ['@list' => ['@object' => $returnFields]];
        }

        return $fields;
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
