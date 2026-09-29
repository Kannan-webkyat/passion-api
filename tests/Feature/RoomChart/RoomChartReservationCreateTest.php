<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\Room;

class RoomChartReservationCreateTest extends RoomChartTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'phone' => '9876543210',
            'email' => 'asha@example.com',
            'adults_count' => 2,
            'children_count' => 0,
            'infants_count' => 0,
            'extra_beds_count' => 0,
            'check_in' => $this->day(0),
            'check_out' => $this->day(2),
            'total_price' => 4480,
            'rate_plan_id' => $this->dayPlan->id,
            'notes' => 'Late arrival',
        ], $overrides);
    }

    public function test_creates_confirmed_booking_with_segment_and_audit_note(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $response = $this->postJson('/api/bookings', $this->payload($room))
            ->assertCreated()
            ->assertJsonPath('room.room_number', '101');

        $booking = Booking::query()->findOrFail($response->json('id'));
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame($this->day(0), (string) $booking->check_in);
        $this->assertSame($this->day(2), (string) $booking->check_out);
        $this->assertSame($this->day(0) . ' 00:00:00', $booking->check_in_at->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('[Reservation created: Room #101 · Deluxe · ' . $this->day(0) . ' → ' . $this->day(2), $booking->notes);
        $this->assertStringContainsString('Late arrival', $booking->notes);

        $this->assertDatabaseHas('booking_segments', [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => 'confirmed',
        ]);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_requires_reservation_create_permission(): void
    {
        $this->actingWith(['reservation-view', 'reservation-edit']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room))->assertForbidden();
    }

    public function test_rejects_reservation_in_the_past(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, ['check_in' => $this->day(-1), 'check_out' => $this->day(1)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.check_in.0', 'Reservations cannot be created for past dates.');
    }

    public function test_day_booking_requires_check_out(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $payload = $this->payload($room);
        unset($payload['check_out']);

        $this->postJson('/api/bookings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'check_out is required for day bookings.');
    }

    public function test_walk_in_check_in_only_allowed_for_today(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $other = $this->makeRoom('102');

        $this->postJson('/api/bookings', $this->payload($room, [
            'status' => 'checked_in',
            'check_in' => $this->day(1),
            'check_out' => $this->day(3),
        ]))->assertStatus(422)
            ->assertJsonPath('message', 'Check-in is only allowed on the guest\'s scheduled arrival date (today).');

        $id = $this->postJson('/api/bookings', $this->payload($other, ['status' => 'checked_in']))
            ->assertCreated()
            ->json('id');

        $this->assertSame('occupied', $other->fresh()->status);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $id, 'status' => 'checked_in']);
    }

    public function test_rejects_overlapping_reservation_on_same_room(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(1), $this->day(3));

        $this->postJson('/api/bookings', $this->payload($room))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #101 is already reserved for the selected dates.');
    }

    public function test_allows_back_to_back_reservation_starting_on_previous_checkout_day(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->postJson('/api/bookings', $this->payload($room, ['check_in' => $this->day(2), 'check_out' => $this->day(4)]))
            ->assertCreated();
    }

    public function test_cancelled_and_checked_out_stays_do_not_block_the_room(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(2), ['status' => 'cancelled']);

        $this->postJson('/api/bookings', $this->payload($room))->assertCreated();
    }

    public function test_rejects_room_under_maintenance_or_on_hold(): void
    {
        $this->actingWith(['reservation-create']);
        $maintenance = $this->makeRoom('101');
        $held = $this->makeRoom('102');
        $this->makeBlock($maintenance, 'maintenance', $this->day(1), $this->day(2));
        $this->makeBlock($held, 'on_hold', $this->day(0), $this->day(1));

        $this->postJson('/api/bookings', $this->payload($maintenance))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #101 is under maintenance.');

        $this->postJson('/api/bookings', $this->payload($held))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #102 is on hold and cannot be booked for these dates.');
    }

    public function test_dirty_room_accepts_confirmed_booking_but_rejects_walk_in(): void
    {
        $this->actingWith(['reservation-create']);
        $forReservation = $this->makeRoom('101');
        $forWalkIn = $this->makeRoom('102');
        $this->makeBlock($forReservation, 'dirty', $this->day(0), $this->day(1));
        $this->makeBlock($forWalkIn, 'dirty', $this->day(0), $this->day(1));

        $this->postJson('/api/bookings', $this->payload($forReservation))->assertCreated();

        $this->postJson('/api/bookings', $this->payload($forWalkIn, ['status' => 'checked_in']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #102 requires cleaning before check-in.');
    }

    public function test_rejects_breakfast_count_above_guest_count(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, ['adult_breakfast_count' => 3]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Breakfast counts cannot exceed guest counts.');
    }

    public function test_rejects_guest_mix_above_room_capacity(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, ['adults_count' => 4, 'extra_beds_count' => 1]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total guests (4) exceeds max capacity (3) for this room type.');

        $this->postJson('/api/bookings', $this->payload($room, ['adults_count' => 3, 'extra_beds_count' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('message', '1 extra bed(s) required for this guest count.');
    }

    public function test_rejects_invalid_guest_gstin(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, ['guest_gstin' => 'NOT-A-GSTIN']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('guest_gstin');

        $this->postJson('/api/bookings', $this->payload($room, ['guest_gstin' => ' 29abcde1234f1z5 ']))
            ->assertCreated()
            ->assertJsonPath('guest_gstin', '29ABCDE1234F1Z5');
    }

    public function test_initial_deposit_is_recorded_in_payment_ledger(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $id = $this->postJson('/api/bookings', $this->payload($room, [
            'deposit_amount' => 1000,
            'payment_method' => 'upi',
        ]))->assertCreated()->json('id');

        $this->assertDatabaseHas('booking_payments', [
            'booking_id' => $id,
            'type' => 'payment',
            'method' => 'upi',
            'source' => 'booking_create',
        ]);
        $booking = Booking::query()->findOrFail($id);
        $this->assertSame(1000.0, (float) $booking->deposit_amount);
        $this->assertSame('partial', $booking->payment_status);
    }

    public function test_estimated_arrival_before_standard_check_in_marks_early_check_in(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $id = $this->postJson('/api/bookings', $this->payload($room, ['estimated_arrival_time' => '10:30']))
            ->assertCreated()
            ->json('id');

        $this->assertStringStartsWith('10:30', (string) Booking::query()->findOrFail($id)->early_checkin_time);
    }

    public function test_multi_room_booking_requires_group_permission(): void
    {
        $this->actingWith(['reservation-create']);
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');

        $payload = $this->payload($a, ['room_ids' => [$a->id, $b->id]]);
        unset($payload['room_id']);

        $this->postJson('/api/bookings', $payload)->assertForbidden();
    }

    public function test_multi_room_booking_creates_group_prices_each_room_and_keeps_deposit_on_first(): void
    {
        $this->actingWith(['reservation-create-group']);
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');

        $payload = $this->payload($a, [
            'room_ids' => [$a->id, $b->id],
            'group_name' => 'Rao Wedding',
            'total_price' => 99999,
            'deposit_amount' => 2000,
            'payment_method' => 'cash',
        ]);
        unset($payload['room_id']);

        $rows = $this->postJson('/api/bookings', $payload)->assertCreated()->json();

        $this->assertCount(2, $rows);
        $this->assertDatabaseHas('booking_groups', ['name' => 'Rao Wedding']);
        $bookings = Booking::query()->orderBy('id')->get();
        $this->assertSame([4480.0, 4480.0], $bookings->map(fn($b) => (float) $b->total_price)->all());
        $this->assertSame([2000.0, 0.0], $bookings->map(fn($b) => (float) $b->deposit_amount)->all());
        $this->assertSame(1, $bookings->pluck('booking_group_id')->unique()->count());
    }

    /**
     * phone / email / notes are optional in validation, but the group master insert reads them
     * directly from $validated.
     */
    public function test_group_booking_without_optional_contact_fields_is_created(): void
    {
        $this->actingWith(['reservation-create-group']);
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');

        $payload = $this->payload($a, ['room_ids' => [$a->id, $b->id]]);
        unset($payload['room_id'], $payload['phone'], $payload['email'], $payload['notes']);

        $this->postJson('/api/bookings', $payload)->assertCreated();
    }

    /**
     * children_count / extra_beds_count are "nullable" in validation, but the segment insert reads
     * them directly from the validated array.
     */
    public function test_single_booking_without_optional_guest_counts_is_created(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $payload = $this->payload($room);
        unset($payload['children_count'], $payload['extra_beds_count'], $payload['infants_count']);

        $this->postJson('/api/bookings', $payload)->assertCreated();
    }

    public function test_hourly_package_computes_checkout_and_total_server_side(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $plan = $this->makeHourlyPlan();

        $id = $this->postJson('/api/bookings', $this->payload($room, [
            'booking_unit' => 'hour_package',
            'rate_plan_id' => $plan->id,
            'check_in' => $this->day(0) . ' 12:00:00',
            'check_out' => null,
            'total_price' => 1,
        ]))->assertCreated()->json('id');

        $booking = Booking::query()->findOrFail($id);
        $this->assertSame(1120.0, (float) $booking->total_price);
        $this->assertSame($this->day(0) . ' 12:00:00', $booking->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->day(0) . ' 15:00:00', $booking->check_out_at->format('Y-m-d H:i:s'));
    }

    public function test_hourly_package_bills_overtime_in_steps(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $plan = $this->makeHourlyPlan(['grace_minutes' => 15]);

        $id = $this->postJson('/api/bookings', $this->payload($room, [
            'booking_unit' => 'hour_package',
            'rate_plan_id' => $plan->id,
            'check_in' => $this->day(0) . ' 12:00:00',
            'check_out' => $this->day(0) . ' 16:30:00',
        ]))->assertCreated()->json('id');

        // 90 min overtime − 15 min grace = 75 min → 2 × 60-min steps → 2h × ₹300 = ₹600; (1000 + 600) × 1.12
        $this->assertSame(1792.0, (float) Booking::query()->findOrFail($id)->total_price);
    }

    public function test_hourly_package_rejects_checkout_before_package_end(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $plan = $this->makeHourlyPlan();

        $this->postJson('/api/bookings', $this->payload($room, [
            'booking_unit' => 'hour_package',
            'rate_plan_id' => $plan->id,
            'check_in' => $this->day(0) . ' 12:00:00',
            'check_out' => $this->day(0) . ' 14:00:00',
        ]))->assertStatus(422)
            ->assertJsonPath('message', 'Checkout cannot be earlier than package end time (3h).');
    }

    public function test_available_rooms_excludes_booked_hard_blocked_and_inactive_rooms(): void
    {
        $this->actingWith(['reservation-create']);
        $booked = $this->makeRoom('101');
        $maintenance = $this->makeRoom('102');
        $dirty = $this->makeRoom('103');
        $free = $this->makeRoom('104');
        $this->makeRoom('105', null, ['is_active' => false]);

        $this->makeBooking($booked, $this->day(1), $this->day(3));
        $this->makeBlock($maintenance, 'maintenance', $this->day(0), $this->day(1));
        $this->makeBlock($dirty, 'dirty', $this->day(0), $this->day(1));

        $ids = collect($this->getJson('/api/bookings/available-rooms?check_in=' . $this->day(0) . '&check_out=' . $this->day(2))
            ->assertOk()
            ->json())->pluck('id')->sort()->values()->all();

        $this->assertSame([$dirty->id, $free->id], $ids);
    }

    public function test_available_rooms_can_exclude_the_booking_being_edited(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $ids = collect($this->getJson('/api/bookings/available-rooms?check_in=' . $this->day(0) . '&check_out=' . $this->day(3) . '&exclude_booking_id=' . $booking->id)
            ->assertOk()
            ->json())->pluck('id')->all();

        $this->assertSame([$room->id], $ids);
    }

    public function test_guest_search_returns_latest_guest_by_phone(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(1), ['first_name' => 'Old', 'phone' => '9000011111'])
            ->forceFill(['created_at' => now()->subDays(5)])
            ->save();
        $this->makeBooking($room, $this->day(3), $this->day(4), ['first_name' => 'New', 'phone' => '9000011111']);

        $this->getJson('/api/bookings/guest-search?phone=11111')
            ->assertOk()
            ->assertJsonPath('first_name', 'New');

        $this->getJson('/api/bookings/guest-search?phone=12')->assertStatus(422);
        $this->getJson('/api/bookings/guest-search?phone=55555')->assertNotFound();
    }
}
