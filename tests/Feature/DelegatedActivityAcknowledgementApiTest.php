<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\DelegatedActivityAcknowledgement;
use App\Models\Laboratory;
use App\Models\User;
use App\Services\DelegatedActivityAcknowledgementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DelegatedActivityAcknowledgementApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENDIK_URL = '/api/tendik/delegated-activity-acknowledgements';
    private const SUPER_ADMIN_URL = '/api/super-admin/delegated-activity-acknowledgements';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_service_creates_pending_task_with_configured_sla_and_idempotency(): void
    {
        $laboratory = $this->laboratory();
        $delegatedActor = $this->user('tendik', 'laboran', $laboratory);
        $kepalaLab = $this->user('tendik', 'kepala_lab', $laboratory);
        $service = app(DelegatedActivityAcknowledgementService::class);

        $payload = $this->taskPayload($delegatedActor, $kepalaLab, [
            'idempotency_key' => 'lab-facility-change:1',
            'performed_at' => '2026-07-06 09:00:00',
        ]);

        $task = $service->createTask($payload);
        $sameTask = $service->createTask($payload);

        $this->assertSame($task->id, $sameTask->id);
        $this->assertSame(DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW, $task->status);
        $this->assertSame(DelegatedActivityAcknowledgement::URGENCY_NORMAL, $task->urgency);
        $this->assertFalse($task->isOverdue());
        $this->assertSame(
            '2026-07-09 09:00:00',
            $task->acknowledgement_due_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        );
        $this->assertDatabaseHas('delegated_activity_acknowledgements', [
            'id' => $task->id,
            'status' => DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW,
            'idempotency_key' => 'lab-facility-change:1',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $delegatedActor->id,
            'type' => 'delegated_activity',
            'action' => 'Delegated activity recorded',
        ]);
    }

    public function test_overdue_is_derived_and_not_stored_as_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', config('app.timezone')));

        $laboratory = $this->laboratory();
        $task = $this->createTask(
            $this->user('tendik', 'laboran', $laboratory),
            $this->user('tendik', 'kepala_lab', $laboratory),
            ['performed_at' => '2026-07-01 09:00:00'],
        )->fresh();

        $this->assertTrue($task->isOverdue());
        $this->assertSame(DelegatedActivityAcknowledgement::EFFECTIVE_STATUS_OVERDUE, $task->effectiveStatus());
        $this->assertSame(DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW, $task->status);
        $this->assertDatabaseMissing('delegated_activity_acknowledgements', [
            'id' => $task->id,
            'status' => DelegatedActivityAcknowledgement::EFFECTIVE_STATUS_OVERDUE,
        ]);
    }

    public function test_kepala_lab_can_list_own_tasks_but_not_another_accountable_task(): void
    {
        $ownLaboratory = $this->laboratory('ACK-OWN');
        $otherLaboratory = $this->laboratory('ACK-OTHER');
        $kepalaLab = $this->user('tendik', 'kepala_lab', $ownLaboratory);
        $otherKepalaLab = $this->user('tendik', 'kepala_lab', $otherLaboratory);
        $ownTask = $this->createTask(
            $this->user('tendik', 'laboran', $ownLaboratory),
            $kepalaLab,
            ['internal_note' => 'Catatan internal lab sendiri.'],
        );
        $otherTask = $this->createTask(
            $this->user('tendik', 'laboran', $otherLaboratory),
            $otherKepalaLab,
            ['internal_note' => 'Catatan internal lab lain.'],
        );

        Sanctum::actingAs($kepalaLab);

        $response = $this->getJson(self::TENDIK_URL);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownTask->id)
            ->assertJsonPath('data.0.permissions.can_acknowledge', true)
            ->assertJsonPath('meta.summary.pending_count', 1)
            ->assertJsonPath('meta.summary.overdue_count', 0);
        $this->assertNotContains($otherTask->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertStringNotContainsString('Catatan internal lab lain.', $response->getContent());

        $this->getJson(self::TENDIK_URL.'/'.$otherTask->id)
            ->assertNotFound();
    }

    public function test_kepala_lab_can_acknowledge_own_pending_task_once(): void
    {
        $laboratory = $this->laboratory();
        $kepalaLab = $this->user('tendik', 'kepala_lab', $laboratory);
        $task = $this->createTask(
            $this->user('tendik', 'laboran', $laboratory),
            $kepalaLab,
        );

        Sanctum::actingAs($kepalaLab);

        $this->postJson(self::TENDIK_URL.'/'.$task->id.'/acknowledge', [
            'note' => 'Sudah ditinjau dan sesuai kewenangan lab.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', DelegatedActivityAcknowledgement::STATUS_ACKNOWLEDGED)
            ->assertJsonPath('data.acknowledged_by.id', $kepalaLab->id)
            ->assertJsonPath('data.acknowledgement_note', 'Sudah ditinjau dan sesuai kewenangan lab.')
            ->assertJsonPath('data.permissions.can_acknowledge', false);

        $freshTask = $task->fresh();
        $this->assertSame(DelegatedActivityAcknowledgement::STATUS_ACKNOWLEDGED, $freshTask->status);
        $this->assertSame($kepalaLab->id, $freshTask->acknowledged_by);
        $this->assertNotNull($freshTask->acknowledged_at);

        $this->postJson(self::TENDIK_URL.'/'.$task->id.'/acknowledge')
            ->assertConflict();
    }

    public function test_unsupported_tendik_roles_receive_forbidden_and_laboran_cannot_acknowledge(): void
    {
        $laboratory = $this->laboratory();
        $kepalaLab = $this->user('tendik', 'kepala_lab', $laboratory);
        $laboran = $this->user('tendik', 'laboran', $laboratory);
        $task = $this->createTask($laboran, $kepalaLab);

        foreach ([
            $laboran,
            $this->user('tendik', 'sarpras'),
            $this->user('tendik', 'persuratan'),
        ] as $unsupportedUser) {
            Sanctum::actingAs($unsupportedUser);

            $this->getJson(self::TENDIK_URL)
                ->assertForbidden();
            $this->postJson(self::TENDIK_URL.'/'.$task->id.'/acknowledge')
                ->assertForbidden();
        }
    }

    public function test_delegated_actor_cannot_acknowledge_own_task(): void
    {
        $laboratory = $this->laboratory();
        $kepalaLab = $this->user('tendik', 'kepala_lab', $laboratory);
        $task = $this->createTask($kepalaLab, $kepalaLab);

        Sanctum::actingAs($kepalaLab);

        $this->postJson(self::TENDIK_URL.'/'.$task->id.'/acknowledge')
            ->assertForbidden();
    }

    public function test_super_admin_can_list_filter_overdue_and_mark_escalation_seen_without_acknowledging(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', config('app.timezone')));

        $laboratory = $this->laboratory();
        $kepalaLab = $this->user('tendik', 'kepala_lab', $laboratory);
        $delegatedActor = $this->user('tendik', 'laboran', $laboratory);
        $overdueTask = $this->createTask($delegatedActor, $kepalaLab, [
            'activity_summary' => 'Aktivitas melewati batas peninjauan.',
            'acknowledgement_due_at' => '2026-07-08 09:00:00',
        ]);
        $futureTask = $this->createTask($delegatedActor, $kepalaLab, [
            'activity_summary' => 'Aktivitas masih dalam SLA.',
            'acknowledgement_due_at' => '2026-07-13 09:00:00',
        ]);

        Sanctum::actingAs($this->user('super_admin'));

        $this->getJson(self::SUPER_ADMIN_URL)
            ->assertOk()
            ->assertJsonPath('meta.summary.pending_count', 2);

        $response = $this->getJson(self::SUPER_ADMIN_URL.'?overdue=true');
        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdueTask->id)
            ->assertJsonPath('data.0.effective_status', DelegatedActivityAcknowledgement::EFFECTIVE_STATUS_OVERDUE)
            ->assertJsonPath('data.0.labels.overdue', 'Melewati Batas Peninjauan')
            ->assertJsonPath('data.0.permissions.can_mark_escalation_seen', true)
            ->assertJsonPath('meta.summary.pending_count', 1)
            ->assertJsonPath('meta.summary.overdue_count', 1);
        $this->assertNotContains($futureTask->id, collect($response->json('data'))->pluck('id')->all());

        $this->getJson(self::SUPER_ADMIN_URL.'?overdue=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $futureTask->id)
            ->assertJsonPath('meta.summary.pending_count', 1)
            ->assertJsonPath('meta.summary.overdue_count', 0);

        $this->postJson(self::SUPER_ADMIN_URL.'/'.$overdueTask->id.'/mark-escalation-seen')
            ->assertOk()
            ->assertJsonPath('data.status', DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.acknowledged_at', null);

        $freshTask = $overdueTask->fresh();
        $this->assertNotNull($freshTask->escalation_seen_by_superadmin_at);
        $this->assertNull($freshTask->acknowledged_at);
        $this->assertNull($freshTask->acknowledged_by);
    }

    public function test_super_admin_cannot_acknowledge_and_role_groups_remain_separate(): void
    {
        $laboratory = $this->laboratory();
        $kepalaLab = $this->user('tendik', 'kepala_lab', $laboratory);
        $task = $this->createTask(
            $this->user('tendik', 'laboran', $laboratory),
            $kepalaLab,
        );

        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson(self::SUPER_ADMIN_URL.'/'.$task->id.'/acknowledge')
            ->assertNotFound();
        $this->getJson(self::TENDIK_URL)
            ->assertForbidden();

        Sanctum::actingAs($kepalaLab);
        $this->getJson(self::SUPER_ADMIN_URL)
            ->assertForbidden();
    }

    public function test_service_rejects_private_file_references(): void
    {
        $laboratory = $this->laboratory();
        $delegatedActor = $this->user('tendik', 'laboran', $laboratory);
        $kepalaLab = $this->user('tendik', 'kepala_lab', $laboratory);

        $this->expectException(ValidationException::class);

        app(DelegatedActivityAcknowledgementService::class)->createTask(
            $this->taskPayload($delegatedActor, $kepalaLab, [
                'before_state' => [
                    'private_file' => '/' . 'storage' . '/private-document.pdf',
                ],
            ]),
        );
    }

    private function laboratory(?string $code = null): Laboratory
    {
        $code ??= fake()->unique()->bothify('ACK-###');

        return Laboratory::create([
            'name' => 'Laboratorium '.$code,
            'code' => $code,
        ]);
    }

    private function user(
        string $role,
        ?string $tendikRole = null,
        ?Laboratory $laboratory = null,
        array $attributes = [],
    ): User {
        return User::factory()->create(array_merge([
            'role' => $role,
            'tendik_role' => $role === 'tendik' ? $tendikRole : null,
            'laboratory_id' => $laboratory?->id,
            'role_level' => $role === 'super_admin' ? 'primary' : null,
            'nip' => $role === 'tendik' ? fake()->unique()->numerify('19########') : null,
            'status' => UserStatus::Active,
        ], $attributes));
    }

    private function createTask(
        User $delegatedActor,
        User $accountableUser,
        array $overrides = [],
    ): DelegatedActivityAcknowledgement {
        return app(DelegatedActivityAcknowledgementService::class)->createTask(
            $this->taskPayload($delegatedActor, $accountableUser, $overrides),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(User $delegatedActor, User $accountableUser, array $overrides = []): array
    {
        return array_merge([
            'domain_type' => 'room_management',
            'subject_type' => 'room',
            'subject_id' => 1,
            'delegated_actor_id' => $delegatedActor->id,
            'accountable_user_id' => $accountableUser->id,
            'accountable_role' => 'kepala_lab',
            'represented_scope_type' => 'laboratory',
            'represented_scope_id' => $accountableUser->laboratory_id,
            'activity_type' => 'lab_facility_changed',
            'activity_summary' => 'Perubahan fasilitas laboratorium membutuhkan peninjauan.',
            'internal_note' => 'Catatan internal untuk penanggung jawab lab.',
            'student_facing_note' => 'Perubahan fasilitas sudah dicatat.',
            'before_state' => ['facility_count' => 1],
            'after_state' => ['facility_count' => 2],
        ], $overrides);
    }
}
