<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSegment;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class DailyCleaningExternalAssignmentTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    public function test_only_the_assigned_staff_member_can_start_a_reservice(): void
    {
        $attendant = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        $other = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        $room = $this->createRoom('207');
        $today = Carbon::today();
        $booking = Booking::query()->create([
            'room_id' => $room->id,
            'status' => 'checked_in',
            'first_name' => 'Anurag',
            'last_name' => 'Mohan',
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDay()->toDateString(),
            'check_in_at' => $today->copy()->subDay(),
            'check_out_at' => $today->copy()->addDay(),
        ]);
        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => 'checked_in',
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDay()->toDateString(),
            'check_in_at' => $today->copy()->subDay(),
            'check_out_at' => $today->copy()->addDay(),
        ]);
        $release = $this->createActiveRelease($room, [
            'booking_id' => $booking->id,
            'service_type' => 'other',
            'service_subtype' => 'rerelease',
            'window_start' => now()->subHour(),
            'window_end' => now()->addHours(3),
        ]);

        $payload = [
            'room_id' => $room->id,
            'service_date' => $today->toDateString(),
            'status' => 'in_progress',
        ];

        Sanctum::actingAs($attendant);
        $this->postJson('/api/housekeeping/daily-cleaning/status', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Assign a housekeeping staff member before starting this cleaning.');

        $release->assigned_to = $attendant->id;
        $release->save();

        Sanctum::actingAs($other);
        $this->postJson('/api/housekeeping/daily-cleaning/status', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the assigned staff member can start this cleaning.');

        Sanctum::actingAs($attendant);
        $this->postJson('/api/housekeeping/daily-cleaning/status', $payload)
            ->assertOk();

        $this->assertDatabaseHas('daily_room_cleanings', [
            'room_id' => $room->id,
            'status' => 'in_progress',
            'started_by' => $attendant->id,
        ]);
    }

    public function test_daily_service_cannot_start_until_staff_is_assigned(): void
    {
        $attendant = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        $room = $this->createRoom('209');
        $today = Carbon::today();
        $booking = Booking::query()->create([
            'room_id' => $room->id,
            'status' => 'checked_in',
            'first_name' => 'Anurag',
            'last_name' => 'Mohan',
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDay()->toDateString(),
            'check_in_at' => $today->copy()->subDay(),
            'check_out_at' => $today->copy()->addDay(),
        ]);
        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => 'checked_in',
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDay()->toDateString(),
            'check_in_at' => $today->copy()->subDay(),
            'check_out_at' => $today->copy()->addDay(),
        ]);
        $release = $this->createActiveRelease($room, [
            'booking_id' => $booking->id,
            'window_start' => now()->subHour(),
            'window_end' => now()->addHours(3),
        ]);

        $payload = [
            'room_id' => $room->id,
            'service_date' => $today->toDateString(),
            'status' => 'in_progress',
        ];

        Sanctum::actingAs($attendant);
        $this->postJson('/api/housekeeping/daily-cleaning/status', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Assign a housekeeping staff member in the daily cleaning schedule before starting this cleaning.');
        $this->assertDatabaseMissing('daily_room_cleanings', [
            'room_id' => $room->id,
            'status' => 'in_progress',
        ]);

        $release->assigned_to = $attendant->id;
        $release->save();

        $this->postJson('/api/housekeeping/daily-cleaning/status', $payload)
            ->assertOk();

        $this->assertDatabaseHas('daily_room_cleanings', [
            'room_id' => $room->id,
            'status' => 'in_progress',
        ]);
    }
}
