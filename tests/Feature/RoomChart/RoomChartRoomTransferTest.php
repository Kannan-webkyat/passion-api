<?php

namespace Tests\Feature\RoomChart;

use App\Models\BookingSegment;

class RoomChartRoomTransferTest extends RoomChartTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(int $roomId, array $overrides = []): array
    {
        return array_merge([
            'new_room_id' => $roomId,
            'transfer_reason' => 'guest_request',
            'rate_mode' => 'keep_existing',
        ], $overrides);
    }

    public function test_transfer_requires_edit_permission(): void
    {
        $this->actingWith(['reservation-view']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($this->makeRoom('102')->id))
            ->assertForbidden();
    }

    public function test_preview_pre_arrival_swap_reprices_without_saving(): void
    {
        $this->actingWith(['reservation-edit']);
        $suite = $this->makeRoomType(['name' => 'Suite']);
        $this->makeRatePlan($suite, ['base_price' => 3000]);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $target = $this->makeRoom('301', $suite);

        $this->postJson("/api/bookings/{$booking->id}/preview-room-transfer", $this->payload($target->id, ['rate_mode' => 'apply_new_category']))
            ->assertOk()
            ->assertJson(['old_total' => 4480, 'new_total' => 6720, 'delta' => 2240, 'is_complimentary_upgrade' => false]);

        $this->assertSame((int) $booking->room_id, (int) $booking->fresh()->room_id);
    }

    public function test_apply_new_category_uses_the_same_meal_plan_on_the_new_type(): void
    {
        $this->actingWith(['reservation-edit']);
        $fullBoard = $this->makeRatePlan($this->roomType, [
            'name' => 'All meals included',
            'meal_plan_type' => 'full_board',
            'base_price' => 2000,
        ]);
        $suite = $this->makeRoomType([
            'name' => 'Junior Suite',
            'breakfast_price' => 0,
            'adult_meal_price' => 400,
            'child_meal_price' => 0,
        ]);
        $this->makeRatePlan($suite, ['name' => 'Only stay', 'meal_plan_type' => 'room_only', 'base_price' => 3000]);
        $allMeals = $this->makeRatePlan($suite, ['name' => 'All meals included', 'meal_plan_type' => 'full_board', 'base_price' => 3000]);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3), ['rate_plan_id' => $fullBoard->id]);
        $target = $this->makeRoom('301', $suite);

        // 2 nights × (₹3,000 room + 2 adults × ₹400 × 2 meals) × 12% GST.
        $this->postJson("/api/bookings/{$booking->id}/preview-room-transfer", $this->payload($target->id, ['rate_mode' => 'apply_new_category']))
            ->assertOk()
            ->assertJson(['old_total' => 4480, 'new_total' => 10304, 'delta' => 5824]);

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($target->id, ['rate_mode' => 'apply_new_category']))
            ->assertOk();

        $this->assertSame((int) $allMeals->id, (int) $booking->fresh()->rate_plan_id);
        $this->assertSame(10304.0, (float) $booking->fresh()->total_price);
    }

    public function test_pre_arrival_swap_moves_segment_in_place_and_records_history(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $target = $this->makeRoom('102');

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($target->id))
            ->assertOk()
            ->assertJsonPath('transfers.0.to_room_number', '102')
            ->assertJsonPath('transfers.0.transfer_reason_label', 'Guest request');

        $this->assertSame(1, BookingSegment::query()->where('booking_id', $booking->id)->count());
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $target->id, 'status' => 'confirmed']);
        $booking->refresh();
        $this->assertSame((int) $target->id, (int) $booking->room_id);
        $this->assertSame(4480.0, (float) $booking->total_price);
        $this->assertStringContainsString('[Room Transfer: #101 (Deluxe) → #102 (Deluxe) | Reason: Guest request | Rate: keep existing', (string) $booking->notes);
    }

    public function test_mid_stay_transfer_closes_old_segment_and_marks_old_room_dirty(): void
    {
        $this->actingWith(['reservation-edit']);
        $from = $this->makeRoom('101');
        $to = $this->makeRoom('102');
        $booking = $this->makeBooking($from, $this->day(-1), $this->day(2), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($to->id, ['transfer_reason' => 'ac_issue']))
            ->assertOk();

        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $from->id, 'status' => 'checked_out', 'check_out' => $this->day(0)]);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $to->id, 'status' => 'checked_in', 'check_out' => $this->day(2)]);
        $this->assertSame('dirty', $from->fresh()->status);
        $this->assertSame('occupied', $to->fresh()->status);
        $this->assertDatabaseHas('room_status_blocks', [
            'room_id' => $from->id, 'status' => 'dirty', 'start_date' => $this->day(0), 'end_date' => $this->day(1),
            'note' => 'Auto: room transfer', 'is_active' => 1,
        ]);
        $this->assertEqualsWithDelta(6720.0, (float) $booking->fresh()->total_price, 0.01, 'Keep-existing transfer must not change the stay total.');
    }

    public function test_transfer_rejected_to_blocked_or_booked_room(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $maintenance = $this->makeRoom('102');
        $this->makeBlock($maintenance, 'maintenance', $this->day(2), $this->day(4));
        $booked = $this->makeRoom('103');
        $this->makeBooking($booked, $this->day(2), $this->day(5));

        $dirty = $this->makeRoom('104');
        $this->makeBlock($dirty, 'dirty', $this->day(1), $this->day(2));

        $expected = [
            [$maintenance, 'Room #102 is under maintenance.'],
            [$booked, 'Room #103 is already booked for the remaining stay dates.'],
            [$dirty, 'Room #104 is dirty. Housekeeping must clean it before the guest can move in.'],
        ];
        foreach ($expected as [$room, $message]) {
            $this->postJson("/api/bookings/{$booking->id}/preview-room-transfer", $this->payload($room->id))
                ->assertStatus(422)
                ->assertJsonPath('message', $message);
            $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($room->id))
                ->assertStatus(422)
                ->assertJsonPath('message', $message);
        }
    }

    public function test_transfer_input_validation(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(1), $this->day(3));
        $target = $this->makeRoom('102');

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($target->id, ['transfer_reason' => 'bored']))
            ->assertStatus(422)->assertJsonValidationErrors('transfer_reason');

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($target->id, ['transfer_reason' => 'other']))
            ->assertStatus(422)->assertJsonValidationErrors('internal_notes');

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($room->id))
            ->assertStatus(422)->assertJsonPath('message', 'Select a different room than the current one.');

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($target->id, ['rate_mode' => 'apply_new_category']))
            ->assertStatus(422)->assertJsonPath('message', 'Apply new category rate is only needed when moving to a different room type.');
    }

    public function test_transfer_rejected_for_cancelled_booking(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3), ['status' => 'cancelled']);

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($this->makeRoom('102')->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room transfer is only allowed for confirmed or checked-in bookings.');
    }

    /**
     * pre_arrival_swap compares "now" with check_in_at (midnight), so a guest arriving today who
     * has not checked in yet is treated as a mid-stay move: the original segment is closed as a
     * zero-length checked_out stay on the old room instead of being swapped in place.
     */
    public function test_transfer_for_arrival_today_not_yet_checked_in_swaps_in_place(): void
    {
        $this->actingWith(['reservation-edit']);
        $from = $this->makeRoom('101');
        $to = $this->makeRoom('102');
        $booking = $this->makeBooking($from, $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($to->id))->assertOk();

        $this->assertSame(1, BookingSegment::query()->where('booking_id', $booking->id)->count(), 'Arrival-day swap should not leave a checked_out segment on the old room.');
    }

    public function test_list_room_transfers_returns_history_and_reason_catalog(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($this->makeRoom('102')->id))->assertOk();

        $this->getJson("/api/bookings/{$booking->id}/room-transfers")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.from_room_number', '101')
            ->assertJsonPath('items.0.performed_by_name', 'Front Desk')
            ->assertJsonCount(8, 'reasons');
    }
}
