<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\RoomStatusBlock;
use App\Support\BookingPaymentLedger;

class RoomChartCheckInCheckOutTest extends RoomChartTestCase
{
    private function pay(Booking $booking, float $amount): void
    {
        BookingPaymentLedger::recordPayment($booking, [
            'amount' => $amount,
            'method' => 'cash',
            'source' => 'deposit',
            'bill_total' => (float) $booking->total_price,
        ]);
    }

    public function test_check_in_on_arrival_day_marks_segments_and_room_occupied(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_in'])
            ->assertOk()
            ->assertJsonPath('status', 'checked_in');

        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'status' => 'checked_in']);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_check_in_on_split_stay_marks_only_the_arrival_room_occupied(): void
    {
        $this->actingWith();
        $first = $this->makeRoom('101');
        $extension = $this->makeRoom('102');
        $booking = $this->makeBooking($first, $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/split-stay", ['new_room_id' => $extension->id, 'new_check_out' => $this->day(4)])
            ->assertOk();

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_in'])->assertOk();

        $this->assertSame(2, $booking->segments()->where('status', 'checked_in')->count());
        $this->assertSame('occupied', $first->fresh()->status);
        $this->assertSame('available', $extension->fresh()->status, 'Guest does not reach the extension room until ' . $this->day(2) . '.');
    }

    public function test_chart_marks_split_stay_room_occupied_only_once_guest_has_moved_in(): void
    {
        $this->actingWith();
        $movedIn = $this->makeRoom('102');
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->postJson("/api/bookings/{$booking->id}/split-stay", ['new_room_id' => $movedIn->id, 'new_check_out' => $this->day(2)])
            ->assertOk();

        $later = $this->makeRoom('202');
        $other = $this->makeBooking($this->makeRoom('201'), $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $this->postJson("/api/bookings/{$other->id}/split-stay", ['new_room_id' => $later->id, 'new_check_out' => $this->day(3)])
            ->assertOk();

        $this->getJson('/api/bookings/chart')->assertOk();

        $this->assertSame('occupied', $movedIn->fresh()->status);
        $this->assertSame('available', $later->fresh()->status);

        $movedIn->update(['status' => 'pending_inspection']);
        $this->getJson('/api/bookings/chart')->assertOk();
        $this->assertSame('pending_inspection', $movedIn->fresh()->status, 'Chart load must not undo a checkout inspection request.');
    }

    public function test_split_stay_move_hands_first_room_to_housekeeping_on_move_day(): void
    {
        $this->actingWith();
        $first = $this->makeRoom('101');
        $extension = $this->makeRoom('102');
        $booking = $this->makeBooking($first, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->postJson("/api/bookings/{$booking->id}/split-stay", ['new_room_id' => $extension->id, 'new_check_out' => $this->day(2)])
            ->assertOk();

        $this->getJson('/api/bookings/chart')->assertOk();

        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $first->id, 'status' => 'checked_out']);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $extension->id, 'status' => 'checked_in']);
        $this->assertSame('dirty', $first->fresh()->status);
        $this->assertSame('occupied', $extension->fresh()->status);

        $block = RoomStatusBlock::query()->where('room_id', $first->id)->where('is_active', true)->sole();
        $this->assertSame('dirty', $block->status);
        $this->assertSame($this->day(0), $block->start_date->toDateString());
        $this->assertSame('Auto: split stay room move', $block->note);
        $this->assertSame(0, RoomStatusBlock::query()->where('room_id', $extension->id)->count());
    }

    public function test_split_stay_checkout_dirties_only_the_last_room_when_first_room_was_resold(): void
    {
        $this->actingWith();
        $first = $this->makeRoom('101');
        $extension = $this->makeRoom('102');
        $booking = $this->makeBooking($first, $this->day(-4), $this->day(-2), ['status' => 'checked_in']);
        $this->postJson("/api/bookings/{$booking->id}/split-stay", ['new_room_id' => $extension->id, 'new_check_out' => $this->day(0)])
            ->assertOk();
        $this->makeBooking($first, $this->day(-2), $this->day(1), ['status' => 'checked_in']);
        $first->update(['status' => 'occupied']);
        $booking->refresh();
        $this->pay($booking, (float) $booking->total_price);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();

        $this->assertSame('occupied', $first->fresh()->status, 'The next guest is in room 101 now.');
        $this->assertSame(0, RoomStatusBlock::query()->where('room_id', $first->id)->where('is_active', true)->count());
        $this->assertSame('dirty', $extension->fresh()->status);
        $block = RoomStatusBlock::query()->where('room_id', $extension->id)->where('is_active', true)->sole();
        $this->assertSame($this->day(0), $block->start_date->toDateString());
        $this->assertSame('Auto: checkout', $block->note);
    }

    public function test_check_in_requires_edit_permission(): void
    {
        $this->actingWith(['reservation-view']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_in'])->assertForbidden();
    }

    public function test_check_in_rejected_before_arrival_day(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Check-in is only allowed on the guest\'s scheduled arrival date (today).');
    }

    public function test_early_check_in_flag_allows_future_arrival_but_not_past_arrival(): void
    {
        $this->actingWith(['reservation-edit']);
        config(['booking.allow_early_check_in' => true]);
        $future = $this->makeBooking($this->makeRoom('101'), $this->day(2), $this->day(4));
        $past = $this->makeBooking($this->makeRoom('102'), $this->day(-1), $this->day(2));

        $this->patchJson("/api/bookings/{$future->id}", ['status' => 'checked_in'])
            ->assertOk()
            ->assertJsonPath('status', 'checked_in');
        $this->patchJson("/api/bookings/{$past->id}", ['status' => 'checked_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Check-in is only allowed on the guest\'s scheduled arrival date (today).');
    }

    public function test_check_in_rejected_while_room_is_dirty(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));
        $this->makeBlock($room, 'dirty', $this->day(0), $this->day(1));

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #101 is currently marked Dirty. Complete housekeeping service or assign another clean room before check-in.');
    }

    public function test_check_in_allowed_when_dirty_block_ended_yesterday(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));
        $this->makeBlock($room, 'dirty', $this->day(-1), $this->day(0));

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_in'])->assertOk();
    }

    public function test_cancel_via_patch_is_rejected(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use POST /bookings/{id}/cancel to cancel a reservation (policy fee, deposit forfeit, and refund).');
    }

    public function test_room_change_via_patch_requires_room_transfer(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $other = $this->makeRoom('102');

        $this->patchJson("/api/bookings/{$booking->id}", ['room_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use Room Transfer in the reservation panel to move this guest (reason and rate options are required).');
    }

    public function test_guest_detail_edit_appends_audit_line(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->patchJson("/api/bookings/{$booking->id}", ['first_name' => 'Asha M'])->assertOk();

        $this->assertStringContainsString('[Guest / stay: First name: Asha → Asha M by Front Desk on', (string) $booking->fresh()->notes);
    }

    public function test_date_change_rejected_when_it_overlaps_another_booking(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(1), $this->day(3));
        $this->makeBooking($room, $this->day(3), $this->day(5));

        $this->patchJson("/api/bookings/{$booking->id}", ['check_in' => $this->day(1), 'check_out' => $this->day(4)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #101 is already reserved for the selected dates.');
    }

    public function test_checkout_blocked_until_folio_is_paid(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 4000);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Checkout not allowed until payment is fully paid');
    }

    public function test_checkout_after_full_payment_marks_room_dirty_and_creates_departure_block(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 4480);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])
            ->assertOk()
            ->assertJsonPath('status', 'checked_out');

        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'status' => 'checked_out']);
        $this->assertSame('dirty', $room->fresh()->status);

        $block = RoomStatusBlock::query()->where('room_id', $room->id)->where('is_active', true)->sole();
        $this->assertSame('dirty', $block->status);
        $this->assertSame($this->day(0), $block->start_date->toDateString());
        $this->assertSame($this->day(1), $block->end_date->toDateString());
        $this->assertSame('Auto: checkout', $block->note);
    }

    public function test_checkout_closes_pending_inspection_handoff(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 4480);
        $this->makeBlock($room, 'pending_inspection', $this->day(-2), $this->day(0), [
            'inspection_snapshot' => ['booking_id' => $booking->id],
        ]);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();

        $this->assertSame(0, RoomStatusBlock::query()->where('status', 'pending_inspection')->where('is_active', true)->count());
    }

    public function test_checkout_refund_requires_refund_method(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 5000);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out', 'refund_amount' => 520])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select how the refund will be issued (cash, card, UPI, or bank transfer).');

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out', 'refund_amount' => 520, 'refund_method' => 'upi'])
            ->assertOk();

        $this->assertDatabaseHas('booking_payments', [
            'booking_id' => $booking->id,
            'type' => 'refund',
            'method' => 'upi',
            'source' => 'checkout',
        ]);
        $this->assertSame(520.0, (float) $booking->fresh()->refund_amount);
    }

    public function test_checkout_before_scheduled_date_truncates_stay_to_today(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(2), ['status' => 'checked_in']);
        $this->pay($booking, 6720);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();

        $booking->refresh();
        $this->assertSame($this->day(0), (string) $booking->check_out);
        $this->assertStringContainsString('[Early CO: on ' . $this->day(0) . ' by Front Desk]', (string) $booking->notes);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'check_out' => $this->day(0), 'status' => 'checked_out']);
        $this->assertDatabaseHas('room_status_blocks', ['room_id' => $room->id, 'status' => 'dirty', 'start_date' => $this->day(0)]);
    }

    public function test_group_checkout_uses_pooled_balance_unless_room_scope_is_requested(): void
    {
        $this->actingWith(['reservation-edit']);
        $group = \App\Models\BookingGroup::query()->create(['name' => 'Team', 'status' => 'confirmed']);
        $a = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        $b = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        BookingPaymentLedger::recordPayment($b, ['amount' => 8960, 'method' => 'card', 'source' => 'deposit', 'bill_total' => 8960]);

        $this->patchJson("/api/bookings/{$a->id}", ['status' => 'checked_out', 'checkout_scope' => 'room'])
            ->assertStatus(422);

        $this->patchJson("/api/bookings/{$a->id}", ['status' => 'checked_out'])
            ->assertOk();
    }

    public function test_request_inspection_on_checkout_day_creates_pending_inspection_block(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/request-inspection")
            ->assertOk()
            ->assertJsonPath('message', 'Inspection requested.')
            ->assertJsonPath('block.status', 'pending_inspection');

        $block = RoomStatusBlock::query()->where('status', 'pending_inspection')->sole();
        $this->assertSame($booking->id, $block->inspection_snapshot['booking_id']);
        $this->assertSame('pending_inspection', $room->fresh()->status);
    }

    public function test_request_inspection_rejected_before_checkout_day(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(2), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/request-inspection")
            ->assertStatus(422)
            ->assertJsonPath('checkout_date', $this->day(2));
    }

    public function test_request_inspection_requires_checked_in_booking(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/request-inspection")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Inspection can only be requested for a checked-in booking.');
    }

    public function test_checkout_discount_rules(): void
    {
        $this->actingWith(['reservation-edit']);
        $confirmed = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $inHouse = $this->makeBooking($this->makeRoom('102'), $this->day(-1), $this->day(1), ['status' => 'checked_in']);

        $this->patchJson("/api/bookings/{$confirmed->id}", ['checkout_discount_amount' => 100, 'checkout_discount_reason' => 'Loyal guest'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Checkout discount can only be set while the guest is checked in.');

        $this->patchJson("/api/bookings/{$inHouse->id}", ['checkout_discount_amount' => 100, 'checkout_discount_reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A reason is required for checkout discounts (at least 3 characters).');

        $this->patchJson("/api/bookings/{$inHouse->id}", ['checkout_discount_amount' => 99999, 'checkout_discount_reason' => 'Loyal guest'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Discount cannot exceed the bill before discount (₹4480.00).');

        $this->patchJson("/api/bookings/{$inHouse->id}", ['checkout_discount_amount' => 480, 'checkout_discount_reason' => 'Loyal guest'])
            ->assertOk()
            ->assertJsonPath('checkout_discount_amount', '480.00');
    }

    public function test_checkout_discount_reduces_amount_needed_to_check_out(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), [
            'status' => 'checked_in',
            'checkout_discount_amount' => 480,
            'checkout_discount_reason' => 'Loyal guest',
        ]);
        $this->pay($booking, 4000);

        // Stored total_price (4480) is still compared via max(grand, total_price) — discount alone is not enough.
        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertStatus(422);
    }
}
