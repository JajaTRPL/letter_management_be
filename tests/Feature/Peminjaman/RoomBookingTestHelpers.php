<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Enums\UserStatus;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait RoomBookingTestHelpers
{
    protected function bookingUser(array $attributes = []): User
    {
        if (
            in_array($attributes['role'] ?? 'mahasiswa', ['tendik', 'akademik'], true)
            && !array_key_exists('nip', $attributes)
        ) {
            $attributes['nip'] = 'TEST-' . Str::uuid();
        }

        return User::factory()->create(array_merge([
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
        ], $attributes));
    }

    protected function reviewerUser(string $tendikRole, ?Laboratory $laboratory = null): User
    {
        return $this->bookingUser([
            'role' => 'tendik',
            'tendik_role' => $tendikRole,
            'laboratory_id' => $laboratory?->id,
        ]);
    }

    protected function bookingLaboratory(string $suffix = '01'): Laboratory
    {
        return Laboratory::create([
            'name' => "Test Laboratory {$suffix}",
            'code' => "TEST-LAB-{$suffix}",
            'department_id' => null,
        ]);
    }

    protected function classroom(array $attributes = []): Room
    {
        return Room::factory()->classroom()->create(array_merge([
            'capacity' => 40,
        ], $attributes));
    }

    protected function laboratoryRoom(
        Laboratory $laboratory,
        array $attributes = [],
    ): Room {
        return Room::factory()->laboratory($laboratory)->create(array_merge([
            'capacity' => 30,
        ], $attributes));
    }

    protected function roomBooking(
        Room $room,
        ?User $requester = null,
        RoomBookingStatus $status = RoomBookingStatus::Submitted,
        string $startAt = '2026-06-20 10:00:00',
        string $endAt = '2026-06-20 12:00:00',
        array $attributes = [],
    ): RoomBookingRequest {
        $requester ??= $this->bookingUser();

        return RoomBookingRequest::factory()
            ->for($requester, 'requester')
            ->for($room)
            ->create(array_merge([
                'status' => $status,
                'participant_count' => 10,
                'start_at' => Carbon::parse($startAt, config('app.timezone')),
                'end_at' => Carbon::parse($endAt, config('app.timezone')),
            ], $attributes));
    }
}
