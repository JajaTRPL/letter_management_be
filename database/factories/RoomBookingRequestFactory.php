<?php

namespace Database\Factories;

use App\Enums\RoomBookingStatus;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomBookingRequest>
 */
class RoomBookingRequestFactory extends Factory
{
    public function definition(): array
    {
        $startAt = fake()->dateTimeBetween('+1 day', '+30 days');
        $endAt = (clone $startAt)->modify('+2 hours');

        return [
            'requester_id' => User::factory(),
            'room_id' => Room::factory(),
            'activity_name' => 'Test Activity '.fake()->numerify('####'),
            'purpose' => 'Test-only booking purpose.',
            'participant_count' => 10,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => RoomBookingStatus::Submitted,
            'reviewer_id' => null,
            'reviewed_at' => null,
            'revision_note' => null,
            'rejection_reason' => null,
            'cancellation_reason' => null,
        ];
    }

    public function status(RoomBookingStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function reviewedBy(User $reviewer): static
    {
        return $this->state(fn () => [
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
    }
}
