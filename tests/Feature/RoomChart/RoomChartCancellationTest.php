<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Support\BookingPaymentLedger;

class RoomChartCancellationTest extends RoomChartTestCase
{
    private function deposit(Booking $booking, float $amount): Booking
    {
        BookingPaymentLedger::recordPayment($booking, [
            'amount' => $amount,
            'method' => 'upi',
            'source' => 'deposit',
            'bill_total' => (float) $booking->total_price,
        ]);

        return $booking->fresh();
    }

    public function test_cancellation_requires_delete_permission(): void
    {
        $this->actingWith(['reservation-view', 'reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(3), $this->day(5));

        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation")->assertForbidden();
        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'guest_request'])->assertForbidden();
    }

    public function test_preview_inside_free_window_has_no_fee(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(3), $this->day(5));

        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation")
            ->assertOk()
            ->assertJson(['within_free_window' => true, 'policy_fee' => 0, 'effective_fee' => 0, 'can_cancel' => true]);
    }

    public function test_preview_outside_free_window_charges_first_night_against_deposit(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->deposit($this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2)), 3000);

        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation")
            ->assertOk()
            ->assertJson([
                'within_free_window' => false,
                'policy_fee' => 2240,
                'existing_deposit' => 3000,
                'forfeited_from_deposit' => 2240,
                'refund_due' => 760,
                'balance_due' => 0,
            ]);
    }

    public function test_preview_supports_fee_override_waiver_and_other_fee_types(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation", ['fee_override' => 500])
            ->assertOk()->assertJsonPath('effective_fee', 500);
        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation", ['waive_fee' => true])
            ->assertOk()->assertJson(['effective_fee' => 0, 'fee_waived' => true]);

        $this->setting('cancellation_fee_type', 'percent');
        $this->setting('cancellation_fee_value', 25);
        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation")
            ->assertOk()->assertJsonPath('policy_fee', 1120);
    }

    public function test_cancel_refunds_deposit_excess_and_releases_inventory(): void
    {
        $this->actingWith(['reservation-delete']);
        $room = $this->makeRoom('101');
        $booking = $this->deposit($this->makeBooking($room, $this->day(0), $this->day(2)), 3000);

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'plans_changed'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select how the deposit refund will be issued (cash, card, UPI, or bank transfer).');

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'plans_changed', 'refund_method' => 'upi'])
            ->assertOk()
            ->assertJson(['settlement' => ['cancellation_fee' => 2240, 'refund_amount' => 760, 'refund_method' => 'upi', 'payment_status' => 'refunded']]);

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('plans_changed', $booking->cancellation_reason);
        $this->assertStringContainsString('[Cancellation: Plans changed | Fee ₹2240.00 (forfeit ₹2240.00) | Refund ₹760.00 via upi by Front Desk', (string) $booking->notes);
        $this->assertDatabaseMissing('booking_segments', ['booking_id' => $booking->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('booking_payments', ['booking_id' => $booking->id, 'type' => 'refund', 'amount' => 760, 'source' => 'cancellation']);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_cancel_guards(): void
    {
        $this->actingWith(['reservation-delete']);
        $inHouse = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $this->postJson("/api/bookings/{$inHouse->id}/cancel", ['reason' => 'guest_request'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending or confirmed reservations can be cancelled. In-house stays must be checked out.');

        $booking = $this->makeBooking($this->makeRoom('102'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'other'])
            ->assertStatus(422)->assertJsonPath('message', 'Please add a short note when reason is Other.');
        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'guest_request', 'additional_collected' => 500])
            ->assertStatus(422)->assertJsonPath('message', 'Cancellation fee exceeds deposit. Collect the balance due, or confirm waiving the remaining balance.');
        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'guest_request', 'additional_collected' => 2240])
            ->assertStatus(422)->assertJsonPath('message', 'Select the payment method used to collect the remaining cancellation fee.');
    }

    public function test_cancel_with_unpaid_fee_needs_explicit_balance_waiver(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'guest_request'])
            ->assertStatus(422)
            ->assertJsonPath('preview.balance_due', 2240);

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'guest_request', 'confirm_balance_waived' => true])
            ->assertOk()
            ->assertJson(['settlement' => ['cancellation_fee' => 0, 'balance_waived' => true]]);
    }

    public function test_cancel_collects_fee_with_payment_method(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/cancel", [
            'reason' => 'guest_request',
            'additional_collected' => 2240,
            'additional_payment_method' => 'card',
        ])->assertOk()->assertJsonPath('settlement.payment_status', 'paid');

        $this->assertDatabaseHas('booking_payments', ['booking_id' => $booking->id, 'type' => 'payment', 'amount' => 2240, 'source' => 'cancellation', 'method' => 'card']);
    }

    public function test_cancel_releases_on_hold_blocks_in_stay_window(): void
    {
        $this->actingWith(['reservation-delete']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(3), $this->day(5));
        $hold = $this->makeBlock($room, 'on_hold', $this->day(3), $this->day(5));

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'guest_request'])->assertOk();

        $this->assertFalse((bool) $hold->fresh()->is_active);
    }

    /**
     * BookingCancellationPolicy::arrivalAt() documents "check-in day + standard check-in time", but
     * day bookings always carry check_in_at = midnight, so the window is measured to 00:00.
     * Arrival tomorrow at 14:00 is 28h away (outside the 24h fee window), yet a fee is charged.
     */
    public function test_free_window_is_measured_to_standard_check_in_time(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation")
            ->assertOk()
            ->assertJsonPath('within_free_window', true);
    }

    /**
     * Cancelling a future stay sets rooms.status = available for the room even if another guest
     * is currently checked in there.
     */
    public function test_cancel_future_stay_keeps_room_occupied_by_in_house_guest(): void
    {
        $this->actingWith(['reservation-delete']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-1), $this->day(2), ['status' => 'checked_in']);
        $future = $this->makeBooking($room, $this->day(4), $this->day(6));

        $this->postJson("/api/bookings/{$future->id}/cancel", ['reason' => 'guest_request'])->assertOk();

        $this->assertSame('occupied', $room->fresh()->status);
    }
}
