<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\Room;
use App\Support\BookingPaymentLedger;

class BookingStatusMoneyIntegrityTest extends RoomChartTestCase
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
            'adults_count' => 2,
            'check_in' => $this->day(0),
            'check_out' => $this->day(2),
            'total_price' => 4480,
            'rate_plan_id' => $this->dayPlan->id,
        ], $overrides);
    }

    public function test_create_accepts_only_open_statuses(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, ['status' => 'checked_out']))->assertStatus(422);
        $this->postJson('/api/bookings', $this->payload($room, ['status' => 'cancelled']))->assertStatus(422);
    }

    public function test_create_prices_single_day_booking_on_server_and_ignores_client_payment_status(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'total_price' => 1,
            'payment_status' => 'paid',
        ]))->assertCreated();

        $this->assertSame(4480.0, (float) $response->json('total_price'));
        $this->assertSame('pending', $response->json('payment_status'));
    }

    public function test_day_booking_needs_a_nightly_rate_plan(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, ['rate_plan_id' => null, 'total_price' => 10]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select a nightly rate plan for room #101.');
        $this->assertSame(0, Booking::count());
    }

    public function test_create_rejects_rate_plan_of_another_room_type(): void
    {
        $this->actingWith(['reservation-create']);
        $otherPlan = $this->makeRatePlan($this->makeRoomType(['name' => 'Suite']), ['base_price' => 100]);

        $this->postJson('/api/bookings', $this->payload($this->makeRoom('101'), ['rate_plan_id' => $otherPlan->id]))
            ->assertStatus(422);
        $this->assertSame(0, Booking::count());
    }

    public function test_create_with_empty_room_ids_falls_back_to_room_id(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, ['room_ids' => []]))
            ->assertCreated()
            ->assertJsonPath('room_id', $room->id);
    }

    public function test_create_validates_room_occupancy(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, [
            'room_occupancy' => [$room->id => ['adults' => 0]],
        ]))->assertStatus(422);

        $this->postJson('/api/bookings', $this->payload($room, [
            'room_occupancy' => [$room->id => ['adults' => 1, 'adult_breakfast' => 2]],
        ]))->assertStatus(422);
        $this->assertSame(0, Booking::count());
    }

    public function test_closed_booking_cannot_be_reopened_or_moved_backwards(): void
    {
        $this->actingWith(['reservation-edit']);
        $out = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_out']);
        $in = $this->makeBooking($this->makeRoom('102'), $this->day(-1), $this->day(1), ['status' => 'checked_in']);

        $this->patchJson("/api/bookings/{$out->id}", ['status' => 'checked_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A checked out reservation cannot be changed to checked in.');
        $this->patchJson("/api/bookings/{$in->id}", ['status' => 'confirmed'])->assertStatus(422);

        $this->assertSame('checked_out', $out->fresh()->status);
        $this->assertSame('checked_in', $in->fresh()->status);
    }

    public function test_resending_current_status_does_not_repeat_checkout_effects(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101', null, ['status' => 'available']);
        $booking = $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_out']);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();

        $this->assertSame('available', $room->fresh()->status);
        $this->assertDatabaseMissing('room_status_blocks', ['room_id' => $room->id, 'status' => 'dirty']);
    }

    public function test_closed_booking_freezes_money_and_stay_fields_but_allows_contact_edits(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_out']);

        $this->patchJson("/api/bookings/{$booking->id}", ['total_price' => 1])->assertStatus(422);
        $this->patchJson("/api/bookings/{$booking->id}", ['adults_count' => 1])->assertStatus(422);
        $this->patchJson("/api/bookings/{$booking->id}", ['phone' => '9000000000', 'guest_gstin' => null])->assertOk();

        $this->assertSame('9000000000', $booking->fresh()->phone);
        $this->assertSame(4480.0, (float) $booking->fresh()->total_price);
    }

    public function test_deposit_cannot_be_lowered_and_refund_needs_checkout(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->pay($booking, 2000);

        $this->patchJson("/api/bookings/{$booking->id}", ['deposit_amount' => 500])->assertStatus(422);
        $this->patchJson("/api/bookings/{$booking->id}", ['refund_amount' => 500, 'refund_method' => 'cash'])->assertStatus(422);

        $fresh = $booking->fresh();
        $this->assertSame(2000.0, (float) $fresh->deposit_amount);
        $this->assertSame(0.0, (float) $fresh->refund_amount);
    }

    public function test_checkout_refund_is_capped_at_overpayment(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 5000);
        $this->completeCheckoutInspection($booking);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out', 'refund_amount' => 900, 'refund_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Refund cannot exceed the amount received over the bill (₹520.00).');

        $this->assertSame('checked_in', $booking->fresh()->status);
        $this->assertDatabaseMissing('booking_payments', ['booking_id' => $booking->id, 'type' => 'refund']);
    }

    public function test_room_change_checks_target_room_availability(): void
    {
        $this->actingWith(['reservation-edit']);
        $taken = $this->makeRoom('102');
        $this->makeBooking($taken, $this->day(1), $this->day(3));
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3), ['status' => 'pending']);

        $this->patchJson("/api/bookings/{$booking->id}", ['room_id' => $taken->id])->assertStatus(422);
        $this->assertNotSame($taken->id, (int) $booking->fresh()->room_id);

        $free = $this->makeRoom('103');
        $this->patchJson("/api/bookings/{$booking->id}", ['room_id' => $free->id])->assertOk();
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $free->id]);
    }

    public function test_price_change_recomputes_payment_status(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->pay($booking, 4480);
        $this->assertSame('paid', $booking->fresh()->payment_status);

        $this->patchJson("/api/bookings/{$booking->id}", [
            'adults_count' => 3,
            'extra_beds_count' => 1,
            'total_price' => 1,
            'payment_status' => 'paid',
        ])->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame(5600.0, (float) $fresh->total_price);
        $this->assertSame('partial', $fresh->payment_status);
    }

    public function test_client_total_is_ignored_and_guest_change_keeps_negotiated_rate(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2), ['total_price' => 4000]);

        $this->patchJson("/api/bookings/{$booking->id}", ['total_price' => 1])->assertOk();
        $this->assertSame(4000.0, (float) $booking->fresh()->total_price);

        $this->patchJson("/api/bookings/{$booking->id}", ['adults_count' => 3, 'extra_beds_count' => 1])->assertOk();
        $this->assertSame(5120.0, (float) $booking->fresh()->total_price);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'total_price' => 5120]);
    }

    public function test_destroy_only_removes_open_unpaid_bookings(): void
    {
        $this->actingWith(['reservation-delete']);
        $inHouse = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $paid = $this->makeBooking($this->makeRoom('102'), $this->day(1), $this->day(2));
        $this->pay($paid, 500);
        $open = $this->makeBooking($this->makeRoom('103'), $this->day(1), $this->day(2));

        $this->deleteJson("/api/bookings/{$inHouse->id}")->assertStatus(422);
        $this->deleteJson("/api/bookings/{$paid->id}")->assertStatus(422);
        $this->deleteJson("/api/bookings/{$open->id}")->assertNoContent();

        $this->assertNotNull($inHouse->fresh());
        $this->assertNotNull($paid->fresh());
        $this->assertNull($open->fresh());
    }
}
