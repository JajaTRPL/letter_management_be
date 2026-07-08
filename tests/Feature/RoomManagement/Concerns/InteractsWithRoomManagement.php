<?php

namespace Tests\Feature\RoomManagement\Concerns;

use App\Enums\UserStatus;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Shared fixtures for Room Management API tests: two labs, one classroom,
 * one room per lab, and role helpers.
 */
trait InteractsWithRoomManagement
{
    protected Laboratory $labA;

    protected Laboratory $labB;

    protected Room $classroom;

    protected Room $labARoom;

    protected Room $labBRoom;

    protected function setUpRoomFixtures(): void
    {
        $this->labA = Laboratory::create(['name' => 'Lab RPL', 'code' => 'RPL-X']);
        $this->labB = Laboratory::create(['name' => 'Lab Jaringan', 'code' => 'TAJ-X']);
        $this->classroom = Room::factory()->classroom()->create();
        $this->labARoom = Room::factory()->laboratory($this->labA)->create();
        $this->labBRoom = Room::factory()->laboratory($this->labB)->create();
    }

    protected function actingAsSuperAdmin(): User
    {
        return $this->actingUser('super_admin');
    }

    protected function actingAsSarpras(): User
    {
        return $this->actingUser('tendik', 'sarpras');
    }

    protected function actingAsKalab(?int $laboratoryId = null): User
    {
        return $this->actingUser('tendik', 'kepala_lab', $laboratoryId ?? $this->labA->id);
    }

    protected function actingAsLaboran(?int $laboratoryId = null): User
    {
        return $this->actingUser('tendik', 'laboran', $laboratoryId ?? $this->labA->id);
    }

    protected function actingAsMahasiswa(): User
    {
        return $this->actingUser('mahasiswa');
    }

    protected function actingUser(string $role, ?string $tendikRole = null, ?int $laboratoryId = null): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'tendik_role' => $tendikRole,
            'laboratory_id' => $laboratoryId,
            'role_level' => $role === 'super_admin' ? 'primary' : null,
            // Tendik profiles need a NIP to pass the profile_complete
            // middleware on HTTP routes.
            'nip' => $role === 'tendik' ? fake()->unique()->numerify('19########') : null,
            'status' => UserStatus::Active,
        ]);

        if ($role === 'mahasiswa') {
            \App\Models\MahasiswaProfile::create([
                'user_id' => $user->id,
                'nim' => fake()->unique()->numerify('99/9####/SV/9####'),
            ]);
            $program = \App\Models\StudyProgram::firstOrCreate(
                ['code' => 'TRPL-RM'],
                ['name' => 'Program Uji Room Management', 'department_id' => \App\Models\Department::firstOrCreate(
                    ['code' => 'DTEDI-RM'],
                    ['name' => 'Departemen Uji Room Management'],
                )->id],
            );
            $user->update(['study_program_id' => $program->id]);
        }

        Sanctum::actingAs($user);

        return $user;
    }
}
