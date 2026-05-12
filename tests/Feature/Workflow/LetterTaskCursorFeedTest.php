<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LetterTaskCursorFeedTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_no_param_endpoints_preserve_r06a_response_shapes(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $this->scholarshipApplication(null, ['assigned_to' => $tendik->id]);

        $dashboard = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('stats', $dashboard);
        $this->assertArrayHasKey('tasks', $dashboard);
        $this->assertArrayNotHasKey('meta', $dashboard);

        $riwayat = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('tasks', $riwayat);
        $this->assertArrayNotHasKey('meta', $riwayat);

        $kaprodi = $this->akademik('kaprodi');
        $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $akademik = $this->actingAs($kaprodi, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json();

        $this->assertSame('per_letter_type', $akademik['meta']['limit_scope']);
        $this->assertSame(100, $akademik['meta']['per_type_limit']);
        $this->assertArrayNotHasKey('pagination_type', $akademik['meta']);
    }

    public function test_tendik_dashboard_cursor_mode_paginates_without_duplicates_or_missing_rows(): void
    {
        $tendik = $this->tendikPersuratan([
            ScholarshipApplication::LETTER_TYPE,
            SuratPengantarMagangApplication::LETTER_TYPE,
            SuratKeteranganAktifApplication::LETTER_TYPE,
            ProsesLuarNegeriApplication::LETTER_TYPE,
        ]);

        $expected = collect([
            $this->atSortTime($this->scholarshipApplication(null, ['assigned_to' => $tendik->id]), '2026-05-12 10:00:00'),
            $this->atSortTime($this->magangApplication(null, ['assigned_to' => $tendik->id]), '2026-05-12 09:00:00'),
            $this->atSortTime($this->aktifApplication(null, ['assigned_to' => $tendik->id]), '2026-05-12 08:00:00'),
            $this->atSortTime($this->prosesLuarNegeriApplication(null, ['assigned_to' => $tendik->id]), '2026-05-12 07:00:00'),
            $this->atSortTime($this->scholarshipApplication(null, [
                'assigned_to' => $tendik->id,
                'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ]), '2026-05-12 06:00:00'),
        ])->map(fn (Model $task): string => $this->modelKey($task))->sort()->values()->all();

        $pages = $this->fetchCursorPages($tendik, '/api/tendik/dashboard/tasks', 2);

        $this->assertSame([2, 2, 1], $pages->pluck('count')->all());
        $this->assertSame([true, true, false], $pages->pluck('has_more')->all());
        $this->assertSame([2, 2, 2], $pages->pluck('page_size')->all());

        $actual = $pages
            ->flatMap(fn (array $page): array => $page['tasks'])
            ->map(fn (array $row): string => $this->rowKey($row))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual);
        $this->assertSameSize($actual, array_unique($actual));
    }

    public function test_tendik_dashboard_cursor_mode_uses_stable_tie_breakers(): void
    {
        $tendik = $this->tendikPersuratan([
            ScholarshipApplication::LETTER_TYPE,
            SuratPengantarMagangApplication::LETTER_TYPE,
            SuratKeteranganAktifApplication::LETTER_TYPE,
            ProsesLuarNegeriApplication::LETTER_TYPE,
        ]);
        $time = '2026-05-12 10:00:00';

        $olderScholarship = $this->atSortTime($this->scholarshipApplication(null, ['assigned_to' => $tendik->id]), $time);
        $this->atSortTime($this->magangApplication(null, ['assigned_to' => $tendik->id]), $time);
        $this->atSortTime($this->aktifApplication(null, ['assigned_to' => $tendik->id]), $time);
        $this->atSortTime($this->prosesLuarNegeriApplication(null, ['assigned_to' => $tendik->id]), $time);
        $newerScholarship = $this->atSortTime($this->scholarshipApplication(null, ['assigned_to' => $tendik->id]), $time);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=10')
            ->assertOk()
            ->json('tasks');

        $this->assertSame([
            ProsesLuarNegeriApplication::LETTER_TYPE,
            SuratKeteranganAktifApplication::LETTER_TYPE,
            SuratPengantarMagangApplication::LETTER_TYPE,
            ScholarshipApplication::LETTER_TYPE,
            ScholarshipApplication::LETTER_TYPE,
        ], collect($tasks)->pluck('letter_type')->all());

        $scholarshipIds = collect($tasks)
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->pluck('id')
            ->all();

        $this->assertSame([$newerScholarship->id, $olderScholarship->id], $scholarshipIds);
    }

    public function test_tendik_cursor_preserves_assignment_rules_and_beasiswa_active_asymmetry(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $otherTendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $unassignedSubmitted = $this->scholarshipApplication();
        $unassignedApprovedTendik = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);
        $assignedApprovedTendik = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);
        $assignedRejected = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_REJECTED,
        ]);
        $assignedToOther = $this->scholarshipApplication(null, [
            'assigned_to' => $otherTendik->id,
        ]);
        $magang = $this->magangApplication();

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=20')
            ->assertOk()
            ->json('tasks');

        $this->assertTaskPresent($tasks, ScholarshipApplication::LETTER_TYPE, $unassignedSubmitted->id);
        $this->assertTaskPresent($tasks, ScholarshipApplication::LETTER_TYPE, $assignedApprovedTendik->id);
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $unassignedApprovedTendik->id);
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $assignedRejected->id);
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $assignedToOther->id);
        $this->assertTaskMissing($tasks, SuratPengantarMagangApplication::LETTER_TYPE, $magang->id);
    }

    public function test_tendik_riwayat_cursor_preserves_history_bucket(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $visible = collect([
            $this->scholarshipApplication(null, ['assigned_to' => $tendik->id, 'status' => ScholarshipApplication::STATUS_REVISION]),
            $this->scholarshipApplication(null, ['assigned_to' => $tendik->id, 'status' => ScholarshipApplication::STATUS_REJECTED]),
            $this->scholarshipApplication(null, ['assigned_to' => $tendik->id, 'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI]),
            $this->scholarshipApplication(null, ['assigned_to' => $tendik->id, 'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW]),
            $this->scholarshipApplication(null, ['assigned_to' => $tendik->id, 'status' => ScholarshipApplication::STATUS_COMPLETED]),
        ]);
        $submitted = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);
        $approvedTendik = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat?page_size=20')
            ->assertOk()
            ->json('tasks');

        $visible->each(fn (ScholarshipApplication $task) => $this->assertTaskPresent($tasks, ScholarshipApplication::LETTER_TYPE, $task->id));
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $submitted->id);
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $approvedTendik->id);
    }

    public function test_cursor_mode_tasks_preserve_existing_dto_fields(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'scholarship_name' => 'Beasiswa DTO',
        ]);

        $task = collect($this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=1')
            ->assertOk()
            ->json('tasks'))->firstWhere('id', $application->id);

        foreach ([
            'id',
            'submitted_at',
            'student_name',
            'nim',
            'type',
            'letter_type',
            'letter_label',
            'category',
            'status',
            'is_overdue',
            'docx_url',
            'scholarship_name',
        ] as $field) {
            $this->assertArrayHasKey($field, $task);
        }

        $this->assertArrayNotHasKey('_sort_at', $task);
        $this->assertArrayNotHasKey('sort_timestamp', $task);
    }

    public function test_akademik_cursor_mode_preserves_prodi_scope_stats_and_meta(): void
    {
        [$trpl, $otherProgram] = $this->twoProgramsInDifferentDepartments();
        [$trplStudent] = $this->completeMahasiswa([], [], $trpl);
        [$otherStudent] = $this->completeMahasiswa([], [], $otherProgram);

        $visibleA = $this->scholarshipApplication($trplStudent, ['status' => ScholarshipApplication::STATUS_APPROVED_TENDIK]);
        $visibleB = $this->magangApplication($trplStudent, ['status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK]);
        $visibleC = $this->aktifApplication($trplStudent, ['status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK]);
        $outOfScope = $this->scholarshipApplication($otherStudent, ['status' => ScholarshipApplication::STATUS_APPROVED_TENDIK]);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $trpl->id]);
        $response = $this->actingAs($kaprodi, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks?page_size=2')
            ->assertOk()
            ->assertJsonCount(2, 'tasks')
            ->assertJsonPath('stats.total_incoming', 3)
            ->assertJsonPath('stats.needs_verification', 3)
            ->assertJsonPath('meta.displayed_tasks', 2)
            ->assertJsonPath('meta.total_matching_tasks', 3)
            ->assertJsonPath('meta.is_limited', true)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.limit_scope', 'global_cursor_page')
            ->assertJsonPath('meta.pagination_type', 'cursor')
            ->assertJsonPath('meta.page_size', 2)
            ->assertJsonPath('meta.has_more', true);

        $this->assertNull($response->json('meta.per_type_limit'));
        $tasks = $response->json('tasks');

        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $outOfScope->id);
        $this->assertNotEmpty(array_intersect(
            [$this->modelKey($visibleA), $this->modelKey($visibleB), $this->modelKey($visibleC)],
            collect($tasks)->map(fn (array $row): string => $this->rowKey($row))->all()
        ));
    }

    public function test_akademik_cursor_mode_preserves_department_scope(): void
    {
        $department = $this->department(['name' => 'DTEDI']);
        $trpl = $this->studyProgram($department, ['name' => 'TRPL']);
        $tre = $this->studyProgram($department, ['name' => 'TRE']);
        $otherProgram = $this->studyProgram($this->department(['name' => 'Other']), ['name' => 'Other Program']);

        [$trplStudent] = $this->completeMahasiswa([], [], $trpl);
        [$treStudent] = $this->completeMahasiswa([], [], $tre);
        [$otherStudent] = $this->completeMahasiswa([], [], $otherProgram);

        $trplTask = $this->aktifApplication($trplStudent, ['status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI]);
        $treTask = $this->prosesLuarNegeriApplication($treStudent, ['status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI]);
        $otherTask = $this->scholarshipApplication($otherStudent, ['status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI]);

        $kadep = $this->akademik('kadep', ['department_id' => $department->id]);
        $tasks = $this->actingAs($kadep, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks?page_size=10')
            ->assertOk()
            ->assertJsonPath('stats.total_incoming', 2)
            ->json('tasks');

        $this->assertTaskPresent($tasks, SuratKeteranganAktifApplication::LETTER_TYPE, $trplTask->id);
        $this->assertTaskPresent($tasks, ProsesLuarNegeriApplication::LETTER_TYPE, $treTask->id);
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $otherTask->id);
    }

    public function test_page_size_validation_and_clamping(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $this->atSortTime($this->scholarshipApplication(null, ['assigned_to' => $tendik->id]), '2026-05-12 10:00:00');
        $this->atSortTime($this->scholarshipApplication(null, ['assigned_to' => $tendik->id]), '2026-05-12 09:00:00');

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page_size']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=10.5')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page_size']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?' . http_build_query(['page_size' => [25]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page_size']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=0')
            ->assertOk()
            ->assertJsonPath('meta.page_size', 1);

        $negativeResponse = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=-10')
            ->assertOk()
            ->assertJsonPath('meta.page_size', 1);

        $this->assertLessThanOrEqual(1, count($negativeResponse->json('tasks')));

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=101')
            ->assertOk()
            ->assertJsonPath('meta.page_size', 100);
    }

    public function test_cursor_validation_and_default_cursor_page_size(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        for ($i = 0; $i < 30; $i++) {
            $this->atSortTime(
                $this->scholarshipApplication(null, ['assigned_to' => $tendik->id]),
                Carbon::parse('2026-05-12 10:00:00')->subMinutes($i)->format('Y-m-d H:i:s')
            );
        }

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?cursor=not-base64')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?' . http_build_query(['cursor' => ['abc']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?cursor=' . $this->base64UrlJson(['version' => 2]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?cursor=' . $this->base64UrlJson([
                'version' => 1,
                'letter_type' => ScholarshipApplication::LETTER_TYPE,
                'source_id' => 1,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?cursor=' . $this->base64UrlJson([
                'version' => 1,
                'sort_at' => '2026-05-12 10:00:00',
                'source_id' => 1,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?cursor=' . $this->base64UrlJson([
                'version' => 1,
                'sort_at' => '2026-05-12 10:00:00',
                'letter_type' => ScholarshipApplication::LETTER_TYPE,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $cursor = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=1')
            ->assertOk()
            ->json('meta.next_cursor');

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?cursor=' . urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('meta.page_size', 25)
            ->assertJsonCount(25, 'tasks');
    }

    public function test_forged_cursor_does_not_bypass_assignment_visibility(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $otherTendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $cursorBoundary = $this->atSortTime($this->scholarshipApplication(null, [
            'assigned_to' => $otherTendik->id,
        ]), '2026-05-12 12:00:00');
        $unauthorizedNextRow = $this->atSortTime($this->scholarshipApplication(null, [
            'assigned_to' => $otherTendik->id,
        ]), '2026-05-12 11:00:00');
        $authorizedRow = $this->atSortTime($this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
        ]), '2026-05-12 10:00:00');

        $cursor = $this->base64UrlJson([
            'version' => 1,
            'sort_at' => '2026-05-12 12:00:00',
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'source_id' => $cursorBoundary->id,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=10&cursor=' . $cursor)
            ->assertOk()
            ->json('tasks');

        $this->assertTaskPresent($tasks, ScholarshipApplication::LETTER_TYPE, $authorizedRow->id);
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $cursorBoundary->id);
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $unauthorizedNextRow->id);
    }

    public function test_final_cursor_page_returns_null_next_cursor(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        for ($i = 0; $i < 3; $i++) {
            $this->atSortTime(
                $this->scholarshipApplication(null, ['assigned_to' => $tendik->id]),
                Carbon::parse('2026-05-12 10:00:00')->subMinutes($i)->format('Y-m-d H:i:s')
            );
        }

        $firstCursor = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=2')
            ->assertOk()
            ->assertJsonPath('meta.has_more', true)
            ->json('meta.next_cursor');

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?' . http_build_query([
                'page_size' => 2,
                'cursor' => $firstCursor,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);
    }

    private function fetchCursorPages(User $user, string $endpoint, int $pageSize): \Illuminate\Support\Collection
    {
        $pages = collect();
        $cursor = null;

        do {
            $query = ['page_size' => $pageSize];
            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $response = $this->actingAs($user, 'sanctum')
                ->getJson($endpoint . '?' . http_build_query($query))
                ->assertOk();

            $pages->push([
                'count' => count($response->json('tasks')),
                'has_more' => $response->json('meta.has_more'),
                'page_size' => $response->json('meta.page_size'),
                'tasks' => $response->json('tasks'),
            ]);

            $cursor = $response->json('meta.next_cursor');
        } while ($cursor);

        return $pages;
    }

    private function atSortTime(Model $model, string $time): Model
    {
        $model->forceFill([
            'submitted_at' => $time,
            'created_at' => $time,
            'updated_at' => $time,
        ])->save();

        return $model->refresh();
    }

    private function twoProgramsInDifferentDepartments(): array
    {
        return [
            $this->studyProgram($this->department(['name' => 'DTEDI'])),
            $this->studyProgram($this->department(['name' => 'Other Department'])),
        ];
    }

    private function assertTaskPresent(array $tasks, string $letterType, int $id): void
    {
        $this->assertTrue(
            collect($tasks)->contains(fn (array $row): bool => $row['letter_type'] === $letterType && $row['id'] === $id),
            "Expected {$letterType} task {$id} to be present."
        );
    }

    private function assertTaskMissing(array $tasks, string $letterType, int $id): void
    {
        $this->assertFalse(
            collect($tasks)->contains(fn (array $row): bool => $row['letter_type'] === $letterType && $row['id'] === $id),
            "Expected {$letterType} task {$id} to be absent."
        );
    }

    private function modelKey(Model $model): string
    {
        return match (true) {
            $model instanceof ScholarshipApplication => ScholarshipApplication::LETTER_TYPE . ':' . $model->id,
            $model instanceof SuratPengantarMagangApplication => SuratPengantarMagangApplication::LETTER_TYPE . ':' . $model->id,
            $model instanceof SuratKeteranganAktifApplication => SuratKeteranganAktifApplication::LETTER_TYPE . ':' . $model->id,
            $model instanceof ProsesLuarNegeriApplication => ProsesLuarNegeriApplication::LETTER_TYPE . ':' . $model->id,
        };
    }

    private function rowKey(array $row): string
    {
        return $row['letter_type'] . ':' . $row['id'];
    }

    private function base64UrlJson(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
