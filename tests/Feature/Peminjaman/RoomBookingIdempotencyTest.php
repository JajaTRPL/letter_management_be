<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingIdempotencyRecord;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use Illuminate\Support\Carbon;

class RoomBookingIdempotencyTest extends RoomBookingApiTestCase
{
    public function test_keys_are_hashed_and_action_scopes_are_isolated(): void
    {
        $student = $this->student();
        $reviewer = $this->reviewerUser('sarpras');
        $booking = $this->roomBooking($this->classroom(), $student);
        $key = 'shared-action-key-001';

        $this->actingAsUser($reviewer);
        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => $key,
        ])->assertOk();

        $this->actingAsUser($student);
        $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            [
                'reason' => 'Dibatalkan setelah review dimulai.',
                'expected_workflow_version' => 2,
                'idempotency_key' => $key,
            ],
        )->assertCreated();

        $records = RoomBookingIdempotencyRecord::query()->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertNotSame($key, $records[0]->idempotency_key_hash);
        $this->assertSame(64, strlen($records[0]->idempotency_key_hash));
        $this->assertNotSame($records[0]->action, $records[1]->action);
    }

    public function test_another_actor_cannot_receive_protected_replay(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $firstReviewer = $this->reviewerUser('sarpras');
        $secondReviewer = $this->reviewerUser('sarpras');
        $payload = [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'actor-isolation-review',
        ];

        $this->actingAsUser($firstReviewer);
        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), $payload)
            ->assertOk();

        $this->actingAsUser($secondReviewer);
        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 2,
            'idempotency_key' => $payload['idempotency_key'],
        ])->assertConflict()
            ->assertJsonPath('code', 'review_already_started');

        $this->assertDatabaseCount('room_booking_idempotency_records', 1);
    }

    public function test_review_and_withdraw_race_has_one_winner_and_no_impossible_state(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'race-review-winner',
        ])->assertOk();

        $this->actingAsUser($student);
        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Concurrent withdrawal loser.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'race-withdraw-loser',
        ])->assertConflict()
            ->assertJsonPath('code', 'stale_workflow_version');

        $fresh = $booking->fresh();
        $this->assertSame(RoomBookingStatus::Submitted, $fresh->status);
        $this->assertSame(2, $fresh->workflow_version);
        $this->assertNotNull($fresh->review_started_at);
        $this->assertSame(1, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->count());
    }

    public function test_existing_mutations_accept_optional_version_and_enforce_it_when_supplied(): void
    {
        $reviewer = $this->reviewerUser('sarpras');
        $this->actingAsUser($reviewer);
        $withoutVersion = $this->roomBooking($this->classroom());
        $this->patchJson($this->reviewerUrl("/{$withoutVersion->id}/approve"))
            ->assertOk();

        $stale = $this->roomBooking($this->classroom());
        $this->patchJson($this->reviewerUrl("/{$stale->id}/approve"), [
            'expected_workflow_version' => 7,
        ])->assertConflict()
            ->assertJsonPath('code', 'stale_workflow_version')
            ->assertJsonPath('data.current_workflow_version', 1)
            ->assertJsonPath('data.workflow_version', 1);

        $matching = $this->roomBooking($this->classroom());
        $this->patchJson($this->reviewerUrl("/{$matching->id}/approve"), [
            'expected_workflow_version' => 1,
        ])->assertOk()->assertJsonPath('data.workflow_version', 2);
    }

    public function test_all_workflow_owned_fields_are_guarded_from_mass_assignment_and_http_tampering(): void
    {
        $owned = [
            'status' => RoomBookingStatus::Approved->value,
            'reviewer_id' => 99,
            'reviewed_at' => now(),
            'revision_note' => 'forged',
            'rejection_reason' => 'forged',
            'cancellation_reason' => 'forged',
            'workflow_version' => 99,
            'submission_iteration' => 99,
            'review_started_at' => now(),
            'review_started_by' => 99,
            'cancellation_source' => 'forged',
            'cancelled_by_role_snapshot' => 'forged',
        ];
        $model = new RoomBookingRequest;
        $model->fill($owned);
        foreach (array_keys($owned) as $field) {
            $this->assertNull($model->{$field}, $field.' remained mass assignable.');
        }

        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);
        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            array_merge($this->validBookingPayloadWithPdf($room), $owned),
        )->assertCreated();

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $response->json('data.id'),
            'status' => RoomBookingStatus::Submitted->value,
            'workflow_version' => 1,
            'submission_iteration' => 1,
            'reviewer_id' => null,
            'review_started_by' => null,
            'cancellation_source' => null,
        ]);
    }

    public function test_replay_after_later_workflow_mutation_returns_exact_original_body(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $reviewer = $this->reviewerUser('sarpras');
        $payload = [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'exact-replay-after-advance',
        ];
        $this->actingAsUser($reviewer);

        $original = $this->patchJson(
            $this->reviewerUrl("/{$booking->id}/start-review"),
            $payload,
        )->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $originalBody = $original->json();
        $record = RoomBookingIdempotencyRecord::query()->firstOrFail();
        $this->assertSame(1, $record->response_schema_version);
        $this->assertSame(200, $record->result_status_code);
        $this->assertSame($originalBody, $record->safe_response_body);

        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"), [
            'expected_workflow_version' => 2,
        ])->assertOk()->assertJsonPath('data.workflow_version', 3);
        $historyCount = $booking->statusHistories()->count();
        $eventCount = $booking->workflowEvents()->count();

        $replay = $this->patchJson(
            $this->reviewerUrl("/{$booking->id}/start-review"),
            $payload,
        )->assertOk()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($originalBody, $replay->json());
        $replay->assertJsonPath('data.booking.status', RoomBookingStatus::Submitted->value)
            ->assertJsonPath('data.workflow_version', 2);
        $this->assertSame(RoomBookingStatus::Approved, $booking->fresh()->status);
        $this->assertSame(3, $booking->fresh()->workflow_version);
        $this->assertSame($historyCount, $booking->statusHistories()->count());
        $this->assertSame($eventCount, $booking->workflowEvents()->count());
        $this->assertDatabaseCount('room_booking_idempotency_records', 1);
    }

    public function test_expired_completed_record_is_not_replayed_and_key_can_be_reused(): void
    {
        $student = $this->student();
        $reviewer = $this->reviewerUser('sarpras');
        $booking = $this->roomBooking($this->classroom(), $student);

        $this->actingAsUser($reviewer);
        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'expiry-review-prerequisite',
        ])->assertOk();

        $this->actingAsUser($student);
        $url = $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests");
        $key = 'expired-reusable-request-key';
        $first = $this->postJson($url, [
            'reason' => 'Permohonan pertama.',
            'expected_workflow_version' => 2,
            'idempotency_key' => $key,
        ])->assertCreated();
        $firstRequestId = $first->json('data.cancellation_request.id');

        $this->patchJson(
            $this->mahasiswaUrl(
                "/requests/{$booking->id}/cancellation-requests/{$firstRequestId}/withdraw",
            ),
            [
                'expected_workflow_version' => 3,
                'idempotency_key' => 'expiry-withdraw-prerequisite',
            ],
        )->assertOk();

        $record = RoomBookingIdempotencyRecord::query()
            ->where('action', RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUESTED)
            ->firstOrFail();
        $record->forceFill(['expires_at' => Carbon::now()->subSecond()])->save();

        $second = $this->postJson($url, [
            'reason' => 'Permohonan kedua setelah masa deduplikasi berakhir.',
            'expected_workflow_version' => 4,
            'idempotency_key' => $key,
        ])->assertCreated()->assertHeader('Idempotent-Replay', 'false');

        $this->assertNotSame($firstRequestId, $second->json('data.cancellation_request.id'));
        $this->assertSame(5, $booking->fresh()->workflow_version);
        $this->assertDatabaseCount('room_booking_cancellation_requests', 2);
        $this->assertSame(2, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->where('event_type', RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUESTED)
            ->count());
        $this->assertDatabaseMissing('room_booking_idempotency_records', [
            'idempotency_key_hash' => $key,
        ]);
    }

    public function test_corrupted_completed_response_fails_closed_without_repeating_mutation(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $payload = [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'corrupted-response-replay',
        ];
        $url = $this->reviewerUrl("/{$booking->id}/start-review");

        $this->patchJson($url, $payload)->assertOk();
        RoomBookingIdempotencyRecord::query()->firstOrFail()->forceFill([
            'response_schema_version' => 999,
        ])->save();

        $response = $this->patchJson($url, $payload)
            ->assertStatus(500)
            ->assertJsonPath('code', 'infrastructure_error');

        $this->assertStringNotContainsString('stored', strtolower($response->getContent()));
        $this->assertSame(2, $booking->fresh()->workflow_version);
        $this->assertSame(1, $booking->workflowEvents()->count());
    }

    public function test_stored_replay_bodies_are_validated_against_a_fail_closed_schema(): void
    {
        // ~30 sequential replays of one endpoint: throttling is not the
        // behavior under test here (covered by the rate-limit tests).
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $this->actingAsUser($student);
        $payload = [
            'reason' => 'Ditarik untuk pengujian skema replay.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'replay-schema-guard-01',
        ];
        $url = $this->mahasiswaUrl("/requests/{$booking->id}/withdraw");

        $this->postJson($url, $payload)->assertOk();
        $record = RoomBookingIdempotencyRecord::query()->firstOrFail();
        $cleanBody = $record->safe_response_body;
        $eventsAfterMutation = $booking->workflowEvents()->count();
        $versionAfterMutation = (int) $booking->fresh()->workflow_version;
        $marker = 'INJECTED-SENSITIVE-VALUE';

        // Each case receives the clean stored body and returns a poisoned
        // copy. Sensitive stems are tried in every casing convention: the
        // positive schema rejects them as unknown keys, and the canonical
        // denylist backs that up even if a same-named key were allowlisted.
        $bookingKeyCases = [
            'nested storage_path' => 'storage_path',
            'nested storagePath' => 'storagePath',
            'nested StoragePath' => 'StoragePath',
            'nested storage-path' => 'storage-path',
            'nested storage_disk' => 'storage_disk',
            'nested storageDisk' => 'storageDisk',
            'nested disk' => 'disk',
            'nested checksum' => 'checksum',
            'nested attachmentChecksum' => 'attachmentChecksum',
            'unknown arbitrary key' => 'zzz_unlisted_field',
        ];
        $dataKeyCases = [
            'nested secret' => 'secret',
            'nested token' => 'token',
            'nested password' => 'password',
            'nested exception' => 'exception',
            'nested exceptionMessage' => 'exceptionMessage',
            'nested trace' => 'trace',
            'nested stackTrace' => 'stackTrace',
        ];
        $containerKeyCases = [
            'nested attributes container' => 'attributes',
            'nested raw container' => 'raw',
            'nested original container' => 'original',
            'nested model container' => 'model',
        ];

        $cases = [];
        foreach ($bookingKeyCases as $name => $key) {
            $cases[$name] = function (array $body) use ($key, $marker): array {
                $body['data']['booking'][$key] = $marker;

                return $body;
            };
        }
        foreach ($dataKeyCases as $name => $key) {
            $cases[$name] = function (array $body) use ($key, $marker): array {
                $body['data'][$key] = $marker;

                return $body;
            };
        }
        foreach ($containerKeyCases as $name => $key) {
            $cases[$name] = function (array $body) use ($key, $marker): array {
                $body['data']['booking'][$key] = ['x' => $marker];

                return $body;
            };
        }
        $cases['poisoned list item'] = function (array $body) use ($marker): array {
            $body['data']['booking']['status_histories'] = [
                ['id' => 1, 'to_status' => 'submitted', 'storagePath' => $marker],
            ];

            return $body;
        };
        $cases['invalid workflow_version type'] = function (array $body): array {
            $body['data']['workflow_version'] = 'four';

            return $body;
        };
        $cases['invalid booking id type'] = function (array $body): array {
            $body['data']['booking']['id'] = 'abc';

            return $body;
        };
        $cases['invalid correlation id'] = function (array $body): array {
            $body['data']['correlation_id'] = 'not-a-uuid';

            return $body;
        };
        $cases['overlong string value'] = function (array $body): array {
            $body['data']['booking']['purpose'] = str_repeat('a', 20000);

            return $body;
        };

        foreach ($cases as $name => $mutate) {
            $record->forceFill([
                'safe_response_body' => $mutate($cleanBody),
            ])->save();

            $response = $this->postJson($url, $payload);
            $response->assertStatus(500)
                ->assertJsonPath('code', 'infrastructure_error');
            $this->assertStringNotContainsString($marker, $response->getContent(), $name);

            // The stored mutation is never repeated and no evidence is added.
            $this->assertSame(
                $eventsAfterMutation,
                $booking->workflowEvents()->count(),
                $name,
            );
            $this->assertSame(
                $versionAfterMutation,
                (int) $booking->fresh()->workflow_version,
                $name,
            );
            $this->assertDatabaseCount('room_booking_cancellation_requests', 0);
        }

        // Restoring the untampered body replays normally again.
        $record->forceFill(['safe_response_body' => $cleanBody])->save();
        $this->postJson($url, $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');
    }
}
