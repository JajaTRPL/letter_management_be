<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class UserAngkatanSortTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs($this->activeUser([
            'role' => 'super_admin',
            'role_level' => 'primary',
        ]));
    }

    public function test_sort_by_angkatan_desc_orders_newest_cohort_first(): void
    {
        $this->actingAsSuperAdmin();

        [$oldest] = $this->completeMahasiswa([], ['nim' => '22/111111/SV/11111']);
        [$newest] = $this->completeMahasiswa([], ['nim' => '24/333333/SV/33333']);
        [$middle] = $this->completeMahasiswa([], ['nim' => '23/222222/SV/22222']);

        $resp = $this->getJson('/api/super-admin/users?role=mahasiswa&sort_by=angkatan&sort_dir=desc');

        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->all();

        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    public function test_sort_by_angkatan_asc_orders_oldest_cohort_first(): void
    {
        $this->actingAsSuperAdmin();

        [$oldest] = $this->completeMahasiswa([], ['nim' => '22/111111/SV/11111']);
        [$newest] = $this->completeMahasiswa([], ['nim' => '24/333333/SV/33333']);
        [$middle] = $this->completeMahasiswa([], ['nim' => '23/222222/SV/22222']);

        $resp = $this->getJson('/api/super-admin/users?role=mahasiswa&sort_by=angkatan&sort_dir=asc');

        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->all();

        $this->assertSame([$oldest->id, $middle->id, $newest->id], $ids);
    }

    public function test_sort_by_angkatan_puts_users_without_nim_last(): void
    {
        $this->actingAsSuperAdmin();

        [$withNim] = $this->completeMahasiswa([], ['nim' => '24/333333/SV/33333']);
        $withoutNim = $this->activeUser(['role' => 'mahasiswa']);

        $resp = $this->getJson('/api/super-admin/users?role=mahasiswa&sort_by=angkatan&sort_dir=desc');

        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->all();

        $this->assertSame([$withNim->id, $withoutNim->id], $ids);
    }

    public function test_default_sort_is_unaffected_by_angkatan_join(): void
    {
        $this->actingAsSuperAdmin();

        $this->activeUser(['role' => 'tendik']);

        $resp = $this->getJson('/api/super-admin/users?role=tendik');

        $resp->assertOk()->assertJsonStructure([
            'message',
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
    }
}
