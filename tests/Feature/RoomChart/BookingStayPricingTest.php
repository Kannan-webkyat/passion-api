<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\RoomTypeSeason;
use Carbon\Carbon;

/**
 * Stay-change pricing: extend / split use seasonal rates and the GST-inclusive setting, early checkout
 * keeps the booked rate, transfers split by nights, early/late fees (no double early fee, percentage,
 * last room's policy), cancellation on net deposit and hourly extend guards.
 */
class BookingStayPricingTest extends RoomChartTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingWith();
    }

    private function seasonOverride(string $date, float $price): void
    {
        RoomTypeSeason::query()->create([
            'room_type_id' => $this->roomType->id,
            'season_name' => 'Peak',
            'start_date' => $date,
            'end_date' => $date,
            'adjustment_type' => 'override',
            'price_adjustment' => $price,
        ]);
    }

    private function hourlyBooking(): Booking
    {
        $plan = $this->makeHourlyPlan();

        return $this->makeBooking($this->makeRoom('201'), $this->day(0), $this->day(1), [
            'booking_unit' => 'hour_package',
            'rate_plan_id' => $plan->id,
            'check_in_at' => Carbon::parse($this->day(0) . ' 12:00:00'),
            'check_out_at' => Carbon::parse($this->day(0) . ' 15:00:00'),
            'total_price' => 1120,
            'status' => 'checked_in',
        ]);
    }

    public function test_extend_prices_added_nights_with_seasonal_rate(): void
    {
        $this->seasonOverride($this->day(2), 3000);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(3)])->assertOk();

        $this->assertEqualsWithDelta(4480 + 3360, (float) $booking->fresh()->total_price, 0.01);
    }

    public function test_extend_adds_no_tax_when_room_rates_include_gst(): void
    {
        $this->setting('room_rates_include_gst', '1');
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(3)])->assertOk();

        $this->assertEqualsWithDelta(4480 + 2000, (float) $booking->fresh()->total_price, 0.01);
    }

    public function test_night_extend_rejected_for_hourly_package(): void
    {
        $booking = $this->hourlyBooking();

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(2)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use Extend hours for an hourly package stay.');
    }

    public function test_split_segment_uses_seasonal_rate(): void
    {
        $this->seasonOverride($this->day(3), 3000);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $target = $this->makeRoom('102');

        $this->postJson("/api/bookings/{$booking->id}/split-stay", [
            'new_room_id' => $target->id,
            'new_check_out' => $this->day(4),
        ])->assertOk();

        $segment = BookingSegment::query()->where('booking_id', $booking->id)->where('room_id', $target->id)->firstOrFail();
        $this->assertEqualsWithDelta((2000 + 3000) * 1.12, (float) $segment->total_price, 0.01);
    }

    public function test_early_checkout_keeps_the_negotiated_rate(): void
    {
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(3), [
            'status' => 'checked_in',
            'total_price' => 6000,
        ]);

        $this->postJson("/api/bookings/{$booking->id}/early-checkout", [
            'new_check_out' => $this->day(1),
            'reason' => 'guest_changed_plans',
        ])->assertOk();

        $this->assertEqualsWithDelta(3000.0, (float) $booking->fresh()->total_price, 0.01, 'Half the nights of a ₹6000 stay.');
    }

    public function test_transfer_with_new_category_rate_splits_by_nights(): void
    {
        $suite = $this->makeRoomType(['name' => 'Suite']);
        $this->makeRatePlan($suite, ['base_price' => 3000]);
        $target = $this->makeRoom('301', $suite);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(2), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", [
            'new_room_id' => $target->id,
            'transfer_reason' => 'guest_request',
            'rate_mode' => 'apply_new_category',
        ])->assertOk();

        // One night used in the old room (6720 / 3) + two Suite nights at ₹3000 + 12%.
        $this->assertEqualsWithDelta(2240 + 6720, (float) $booking->fresh()->total_price, 0.01);
    }

    public function test_early_check_in_does_not_charge_the_fee_already_in_the_create_total(): void
    {
        $this->roomType->update(['early_check_in_fee' => 200, 'early_check_in_type' => 'per_hour']);
        $room = $this->makeRoom('101');

        $id = $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'phone' => '9876543210',
            'adults_count' => 2,
            'check_in' => $this->day(0),
            'check_out' => $this->day(2),
            'rate_plan_id' => $this->dayPlan->id,
            'estimated_arrival_time' => '12:00',
        ])->assertCreated()->json('id');
        $booking = Booking::findOrFail($id);
        $this->assertEqualsWithDelta((4000 + 400) * 1.12, (float) $booking->total_price, 0.01);

        $this->postJson("/api/bookings/{$id}/early-checkin", ['time' => '12:00'])->assertOk();
        $this->assertSame(0.0, (float) $booking->fresh()->extra_charges);

        $this->postJson("/api/bookings/{$id}/early-checkin", ['time' => '10:00'])->assertOk();
        $this->assertSame(400.0, (float) $booking->fresh()->extra_charges, 'Only the two extra hours over the booked arrival.');
        $this->assertStringContainsString('Fee: ₹800 (4h) applied. Added to folio: ₹400.', (string) $booking->fresh()->notes);

        $this->postJson("/api/bookings/{$id}/early-checkin", ['time' => '12:00'])->assertOk();
        $this->assertSame(0.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_percentage_early_and_late_fees_use_the_nightly_rate(): void
    {
        $this->roomType->update([
            'early_check_in_fee' => 50, 'early_check_in_type' => 'percentage',
            'late_check_out_fee' => 25, 'late_check_out_type' => 'percentage',
        ]);
        $arriving = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $departing = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$arriving->id}/early-checkin", ['time' => '10:00'])->assertOk();
        $this->postJson("/api/bookings/{$departing->id}/late-checkout", ['time' => '15:00'])->assertOk();

        $this->assertSame(1000.0, (float) $arriving->fresh()->extra_charges);
        $this->assertSame(500.0, (float) $departing->fresh()->extra_charges);
    }

    public function test_late_checkout_after_split_uses_the_last_rooms_policy(): void
    {
        $this->roomType->update(['late_check_out_fee' => 300, 'late_check_out_type' => 'flat_fee']);
        $suite = $this->makeRoomType(['name' => 'Suite', 'late_check_out_fee' => 900, 'late_check_out_type' => 'flat_fee']);
        $from = $this->makeRoom('101');
        $to = $this->makeRoom('301', $suite);
        $booking = $this->makeBooking($from, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $booking->segments()->update(['check_out' => $this->day(-1), 'check_out_at' => $this->day(-1) . ' 00:00:00', 'status' => 'checked_out']);
        BookingSegment::query()->create([
            'booking_id' => $booking->id, 'room_id' => $to->id, 'status' => 'checked_in',
            'check_in' => $this->day(-1), 'check_out' => $this->day(0),
            'check_in_at' => $this->day(-1) . ' 00:00:00', 'check_out_at' => $this->day(0) . ' 00:00:00',
            'rate_plan_id' => $booking->rate_plan_id, 'total_price' => 2240,
        ]);

        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '13:00'])->assertOk();

        $this->assertSame(900.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_cancellation_settles_on_deposit_net_of_refunds(): void
    {
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(5), $this->day(7), [
            'deposit_amount' => 2000,
            'refund_amount' => 500,
        ]);

        $this->postJson("/api/bookings/{$booking->id}/preview-cancellation", ['waive_fee' => true])
            ->assertOk()
            ->assertJson(['existing_deposit' => 1500, 'refund_due' => 1500]);
    }

    public function test_hourly_extend_rejected_for_closed_stay_and_blocked_room(): void
    {
        $booking = $this->hourlyBooking();
        $this->makeBlock($booking->room, 'maintenance', $this->day(0), $this->day(1));

        $this->postJson("/api/bookings/{$booking->id}/extend-hours", ['extend_minutes' => 60])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The room is on hold or under maintenance during the extra time.');

        $booking->update(['status' => 'checked_out']);
        $this->postJson("/api/bookings/{$booking->id}/extend-hours", ['extend_minutes' => 60])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot extend a cancelled or checked-out reservation.');
    }
}
