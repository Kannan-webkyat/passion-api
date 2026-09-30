<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\Room;

/**
 * POST /bookings/{id}/early-checkin and /late-checkout: fee maths per room-type policy, re-apply and
 * undo, same-day turnover conflicts (including split / transferred stays) and status guards.
 * Standard times default to 14:00 check-in and 11:00 check-out.
 */
class RoomChartEarlyCheckInLateCheckoutTest extends RoomChartTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingWith(['reservation-edit']);
    }

    private function earlyCheckin(Booking $booking, string $time): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => $time]);
    }

    private function lateCheckout(Booking $booking, string $time): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => $time]);
    }

    private function earlyPolicy(float $fee, string $type, int $buffer = 0): void
    {
        $this->roomType->update(['early_check_in_fee' => $fee, 'early_check_in_type' => $type, 'early_check_in_buffer_minutes' => $buffer]);
    }

    private function latePolicy(float $fee, string $type, int $buffer = 0): void
    {
        $this->roomType->update(['late_check_out_fee' => $fee, 'late_check_out_type' => $type, 'late_check_out_buffer_minutes' => $buffer]);
    }

    private function arrivingToday(array $overrides = []): Booking
    {
        return $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2), $overrides);
    }

    private function departingToday(array $overrides = []): Booking
    {
        return $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), array_merge(['status' => 'checked_in'], $overrides));
    }

    /**
     * Guest stayed in $from until $moveDay, then moved to $to until check-out; bookings.room_id stays $from.
     */
    private function splitStay(Room $from, Room $to, string $checkIn, string $moveDay, string $checkOut, string $status = 'checked_in'): Booking
    {
        $booking = $this->makeBooking($from, $checkIn, $checkOut, ['status' => $status]);
        $booking->segments()->update([
            'check_out' => $moveDay,
            'check_out_at' => $moveDay . ' 00:00:00',
            'status' => 'checked_out',
        ]);
        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $to->id,
            'check_in' => $moveDay,
            'check_out' => $checkOut,
            'check_in_at' => $moveDay . ' 00:00:00',
            'check_out_at' => $checkOut . ' 00:00:00',
            'rate_plan_id' => $booking->rate_plan_id,
            'adults_count' => 2,
            'children_count' => 0,
            'extra_beds_count' => 0,
            'total_price' => 2240,
            'status' => $status,
        ]);

        return $booking->fresh();
    }

    // ── Early check-in: fees ────────────────────────────────────────────────

    public function test_early_check_in_per_minute_fee(): void
    {
        $this->earlyPolicy(10, 'per_minute');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '13:30')->assertOk();

        $this->assertSame(300.0, (float) $booking->fresh()->extra_charges, '30 minutes early at ₹10/min.');
    }

    public function test_early_check_in_flat_fee_ignores_how_early(): void
    {
        $this->earlyPolicy(800, 'flat_fee');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '08:00')->assertOk();

        $this->assertSame(800.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_early_check_in_per_hour_rounds_a_started_hour_up(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '12:59')->assertOk();

        $this->assertSame(1000.0, (float) $booking->fresh()->extra_charges, '61 minutes early bills 2 started hours.');
    }

    public function test_early_check_in_buffer_is_free_time(): void
    {
        $this->earlyPolicy(500, 'per_hour', 60);
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '11:00')->assertOk();

        $this->assertSame(1000.0, (float) $booking->fresh()->extra_charges, '3h early minus 1h buffer = 2 billable hours.');
    }

    public function test_early_check_in_inside_buffer_saves_time_without_fee(): void
    {
        $this->earlyPolicy(500, 'per_hour', 60);
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '13:15')->assertOk();

        $booking->refresh();
        $this->assertStringStartsWith('13:15', (string) $booking->early_checkin_time);
        $this->assertSame(0.0, (float) $booking->extra_charges);
    }

    public function test_early_check_in_without_a_fee_policy_saves_time_only(): void
    {
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '10:00')->assertOk();

        $booking->refresh();
        $this->assertStringStartsWith('10:00', (string) $booking->early_checkin_time);
        $this->assertSame(0.0, (float) $booking->extra_charges);
    }

    public function test_early_check_in_uses_property_standard_check_in_time(): void
    {
        $this->setting('standard_check_in_time', '12:00');
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '11:00')->assertOk();

        $this->assertSame(500.0, (float) $booking->fresh()->extra_charges, '1h before a 12:00 standard check-in.');
    }

    public function test_early_check_in_adds_to_existing_extra_charges(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday(['extra_charges' => 300]);

        $this->earlyCheckin($booking, '11:00')->assertOk();

        $this->assertSame(1800.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_early_check_in_writes_audit_note_with_fee(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '11:00')->assertOk();

        $this->assertStringContainsString(
            '[Early CI: 11:00 by Front Desk on 2026-10-10 10:00:00] Fee: ₹1500 (3h) applied.',
            (string) $booking->fresh()->notes
        );
    }

    // ── Early check-in: change and undo ─────────────────────────────────────

    public function test_early_check_in_moved_later_replaces_the_fee(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '11:00')->assertOk();
        $this->earlyCheckin($booking, '13:00')->assertOk();

        $booking->refresh();
        $this->assertStringStartsWith('13:00', (string) $booking->early_checkin_time);
        $this->assertSame(500.0, (float) $booking->extra_charges);
    }

    public function test_early_check_in_set_to_standard_time_clears_it_and_removes_the_fee(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '11:00')->assertOk();
        $this->earlyCheckin($booking, '14:00')->assertOk();

        $booking->refresh();
        $this->assertNull($booking->early_checkin_time);
        $this->assertSame(0.0, (float) $booking->extra_charges);
    }

    public function test_early_check_in_after_standard_time_is_not_saved(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '15:00')->assertOk();

        $booking->refresh();
        $this->assertNull($booking->early_checkin_time);
        $this->assertSame(0.0, (float) $booking->extra_charges);
    }

    public function test_early_check_in_reapplied_after_undo_charges_once(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '11:00')->assertOk();
        $this->earlyCheckin($booking, '14:00')->assertOk();
        $this->earlyCheckin($booking, '11:00')->assertOk();

        $this->assertSame(1500.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_early_check_in_does_not_refund_a_fee_it_did_not_charge(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday(['early_checkin_time' => '10:00', 'extra_charges' => 0]);

        $this->earlyCheckin($booking, '12:00')->assertOk();

        $this->assertSame(1000.0, (float) $booking->fresh()->extra_charges, 'A time set at booking creation carries no fee in extra_charges.');
    }

    // ── Early check-in: same-day turnover ───────────────────────────────────

    public function test_early_check_in_allowed_when_previous_guest_already_checked_out(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_out']);
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '09:00')->assertOk();
    }

    public function test_early_check_in_blocked_until_previous_guests_late_checkout_time(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in', 'late_checkout_time' => '13:00']);
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '12:30')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Early check-in conflicts with a previous guest still occupying the room.');
        $this->earlyCheckin($booking, '13:30')->assertOk();
    }

    public function test_early_check_in_allowed_after_previous_guests_standard_check_out(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '10:30')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Early check-in conflicts with a previous guest still occupying the room.');
        $this->earlyCheckin($booking, '11:00')->assertStatus(422);
        $this->earlyCheckin($booking, '12:00')->assertOk();
        $this->assertStringStartsWith('12:00', (string) $booking->fresh()->early_checkin_time);
    }

    public function test_early_check_in_uses_property_standard_check_out_for_previous_guest(): void
    {
        $this->setting('standard_check_out_time', '12:00');
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '11:30')->assertStatus(422);
        $this->earlyCheckin($booking, '12:30')->assertOk();
    }

    public function test_early_check_in_blocked_by_in_house_hourly_guest_without_late_checkout(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(0), [
            'status' => 'checked_in',
            'booking_unit' => 'hour_package',
            'rate_plan_id' => $this->makeHourlyPlan()->id,
        ]);
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '12:00')->assertStatus(422);
    }

    public function test_early_check_in_ignores_guests_in_other_rooms_or_leaving_other_days(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->makeBooking($room, $this->day(-3), $this->day(-1), ['status' => 'checked_in']);
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '09:00')->assertOk();
    }

    public function test_early_check_in_blocked_by_split_stay_guest_leaving_this_room_today(): void
    {
        $room = $this->makeRoom('101');
        $this->splitStay($this->makeRoom('102'), $room, $this->day(-3), $this->day(-1), $this->day(0));
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '09:00')->assertStatus(422);
    }

    public function test_early_check_in_ignores_split_stay_guest_who_already_moved_out_of_this_room(): void
    {
        $room = $this->makeRoom('101');
        $this->splitStay($room, $this->makeRoom('102'), $this->day(-3), $this->day(-1), $this->day(0));
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->earlyCheckin($booking, '09:00')->assertOk();
    }

    // ── Early check-in: guards ──────────────────────────────────────────────

    public function test_early_check_in_rejected_for_cancelled_and_checked_out_bookings(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $cancelled = $this->arrivingToday(['status' => 'cancelled']);
        $checkedOut = $this->makeBooking($this->makeRoom('102'), $this->day(0), $this->day(2), ['status' => 'checked_out']);

        $this->earlyCheckin($cancelled, '11:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot set early check-in on a cancelled reservation.');
        $this->earlyCheckin($checkedOut, '11:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot set early check-in on a checked out reservation.');

        $this->assertSame(0.0, (float) $cancelled->fresh()->extra_charges);
        $this->assertSame(0.0, (float) $checkedOut->fresh()->extra_charges);
    }

    public function test_early_check_in_rejected_for_hourly_booking(): void
    {
        $booking = $this->arrivingToday(['booking_unit' => 'hour_package', 'rate_plan_id' => $this->makeHourlyPlan()->id]);

        $this->earlyCheckin($booking, '11:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Early check-in does not apply to hourly bookings. Use Extend hours instead.');
    }

    public function test_early_check_in_allowed_for_in_house_guest(): void
    {
        $this->earlyPolicy(500, 'per_hour');
        $booking = $this->arrivingToday(['status' => 'checked_in']);

        $this->earlyCheckin($booking, '12:00')->assertOk();

        $this->assertSame(1000.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_early_check_in_validates_time_format(): void
    {
        $booking = $this->arrivingToday();

        $this->earlyCheckin($booking, '9am')->assertStatus(422)->assertJsonValidationErrors('time');
        $this->postJson("/api/bookings/{$booking->id}/early-checkin", [])->assertStatus(422)->assertJsonValidationErrors('time');
    }

    // ── Late checkout: fees ─────────────────────────────────────────────────

    public function test_late_checkout_per_minute_fee(): void
    {
        $this->latePolicy(5, 'per_minute');
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '11:30')->assertOk();

        $this->assertSame(150.0, (float) $booking->fresh()->extra_charges, '30 minutes late at ₹5/min.');
    }

    public function test_late_checkout_flat_fee_ignores_how_late(): void
    {
        $this->latePolicy(700, 'flat_fee');
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '18:00')->assertOk();

        $this->assertSame(700.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_late_checkout_buffer_is_free_time(): void
    {
        $this->latePolicy(200, 'per_hour', 30);
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '13:00')->assertOk();

        $this->assertSame(400.0, (float) $booking->fresh()->extra_charges, '2h late minus 30 min buffer = 2 started hours.');
    }

    public function test_late_checkout_inside_buffer_saves_time_without_fee(): void
    {
        $this->latePolicy(200, 'per_hour', 60);
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '11:45')->assertOk();

        $booking->refresh();
        $this->assertStringStartsWith('11:45', (string) $booking->late_checkout_time);
        $this->assertSame(0.0, (float) $booking->extra_charges);
    }

    public function test_late_checkout_uses_property_standard_check_out_time(): void
    {
        $this->setting('standard_check_out_time', '12:00');
        $this->latePolicy(200, 'per_hour');
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '14:00')->assertOk();

        $this->assertSame(400.0, (float) $booking->fresh()->extra_charges, '2h after a 12:00 standard check-out.');
    }

    public function test_late_checkout_reads_legacy_am_pm_standard_time(): void
    {
        $this->setting('standard_check_out_time', '12:00 PM');
        $this->latePolicy(200, 'per_hour');
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '14:00')->assertOk();

        $this->assertSame(400.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_late_checkout_writes_audit_note(): void
    {
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '14:00')->assertOk();

        $this->assertStringContainsString('[Late CO: 14:00 by Front Desk on 2026-10-10 10:00:00]', (string) $booking->fresh()->notes);
    }

    public function test_late_checkout_allowed_for_confirmed_booking_before_arrival(): void
    {
        $this->latePolicy(200, 'per_hour');
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->lateCheckout($booking, '13:00')->assertOk();

        $this->assertSame(400.0, (float) $booking->fresh()->extra_charges);
    }

    // ── Late checkout: change and undo ──────────────────────────────────────

    public function test_late_checkout_moved_earlier_replaces_the_fee(): void
    {
        $this->latePolicy(200, 'per_hour');
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '14:00')->assertOk();
        $this->lateCheckout($booking, '13:00')->assertOk();

        $booking->refresh();
        $this->assertStringStartsWith('13:00', (string) $booking->late_checkout_time);
        $this->assertSame(400.0, (float) $booking->extra_charges);
    }

    public function test_late_checkout_cleared_removes_the_fee(): void
    {
        $this->latePolicy(200, 'per_hour');
        $booking = $this->departingToday(['extra_charges' => 300]);

        $this->lateCheckout($booking, '14:00')->assertOk();
        $this->assertSame(900.0, (float) $booking->fresh()->extra_charges);

        $this->lateCheckout($booking, '11:00')->assertOk();

        $booking->refresh();
        $this->assertNull($booking->late_checkout_time);
        $this->assertSame(300.0, (float) $booking->extra_charges, 'Only the late fee is removed; other extras stay.');
    }

    public function test_late_checkout_clear_never_makes_extra_charges_negative(): void
    {
        $this->latePolicy(200, 'per_hour');
        $booking = $this->departingToday(['late_checkout_time' => '14:00', 'extra_charges' => 0]);

        $this->lateCheckout($booking, '10:00')->assertOk();

        $this->assertSame(0.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_late_checkout_repeated_does_not_stack_fee(): void
    {
        $this->latePolicy(200, 'per_hour');
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '14:00')->assertOk();
        $this->lateCheckout($booking, '14:00')->assertOk();

        $this->assertSame(600.0, (float) $booking->fresh()->extra_charges);
    }

    // ── Late checkout: same-day turnover ────────────────────────────────────

    public function test_late_checkout_allowed_when_next_guest_arrives_another_day(): void
    {
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $this->makeBooking($room, $this->day(2), $this->day(3));

        $this->lateCheckout($booking, '15:00')->assertOk();
    }

    public function test_late_checkout_ignores_same_day_arrival_in_another_room(): void
    {
        $booking = $this->departingToday();
        $this->makeBooking($this->makeRoom('102'), $this->day(0), $this->day(2));

        $this->lateCheckout($booking, '15:00')->assertOk();
    }

    public function test_late_checkout_allowed_before_next_guests_standard_check_in(): void
    {
        $this->latePolicy(200, 'per_hour');
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->lateCheckout($booking, '13:00')->assertOk();
        $this->lateCheckout($booking, '14:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Late checkout conflicts with the next guest\'s check-in on the same day.');

        $booking->refresh();
        $this->assertStringStartsWith('13:00', (string) $booking->late_checkout_time);
        $this->assertSame(400.0, (float) $booking->extra_charges);
    }

    public function test_late_checkout_uses_property_standard_check_in_for_next_guest(): void
    {
        $this->setting('standard_check_in_time', '12:00');
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->lateCheckout($booking, '11:30')->assertOk();
        $this->lateCheckout($booking, '12:30')->assertStatus(422);
    }

    public function test_late_checkout_blocked_by_same_day_hourly_arrival_without_early_check_in(): void
    {
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $this->makeBooking($room, $this->day(0), $this->day(0), [
            'booking_unit' => 'hour_package',
            'rate_plan_id' => $this->makeHourlyPlan()->id,
        ]);

        $this->lateCheckout($booking, '12:00')->assertStatus(422);
    }

    public function test_late_checkout_and_next_guests_early_check_in_can_both_be_set_without_overlap(): void
    {
        $room = $this->makeRoom('101');
        $leaving = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $arriving = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->lateCheckout($leaving, '12:00')->assertOk();
        $this->earlyCheckin($arriving, '12:30')->assertOk();
        $this->earlyCheckin($arriving, '11:30')->assertStatus(422);
        $this->lateCheckout($leaving, '13:00')->assertStatus(422);
    }

    public function test_late_checkout_allowed_before_next_guests_early_check_in(): void
    {
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $this->makeBooking($room, $this->day(0), $this->day(2), ['early_checkin_time' => '13:00']);

        $this->lateCheckout($booking, '12:00')->assertOk();
        $this->lateCheckout($booking, '13:30')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Late checkout conflicts with the next guest\'s check-in on the same day.');
    }

    public function test_late_checkout_blocked_by_split_stay_guest_moving_into_this_room_today(): void
    {
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $this->splitStay($this->makeRoom('102'), $room, $this->day(-1), $this->day(0), $this->day(2), 'confirmed');

        $this->lateCheckout($booking, '15:00')->assertStatus(422);
    }

    public function test_late_checkout_after_split_stay_checks_the_room_the_guest_leaves_from(): void
    {
        $first = $this->makeRoom('101');
        $second = $this->makeRoom('102');
        $booking = $this->splitStay($first, $second, $this->day(-2), $this->day(-1), $this->day(0));
        $this->makeBooking($first, $this->day(0), $this->day(2));

        $this->lateCheckout($booking, '15:00')->assertOk();

        $this->makeBooking($second, $this->day(0), $this->day(2));

        $this->lateCheckout($booking, '16:00')->assertStatus(422);
    }

    // ── Late checkout: guards ───────────────────────────────────────────────

    public function test_late_checkout_rejected_for_cancelled_and_checked_out_bookings(): void
    {
        $this->latePolicy(200, 'per_hour');
        $cancelled = $this->departingToday(['status' => 'cancelled']);
        $checkedOut = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_out']);

        $this->lateCheckout($cancelled, '14:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot set late checkout on a cancelled reservation.');
        $this->lateCheckout($checkedOut, '14:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot set late checkout on a checked out reservation.');

        $this->assertSame(0.0, (float) $cancelled->fresh()->extra_charges);
        $this->assertSame(0.0, (float) $checkedOut->fresh()->extra_charges);
    }

    public function test_late_checkout_rejected_for_hourly_booking(): void
    {
        $booking = $this->departingToday(['booking_unit' => 'hour_package', 'rate_plan_id' => $this->makeHourlyPlan()->id]);

        $this->lateCheckout($booking, '14:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Late checkout does not apply to hourly bookings. Use Extend hours instead.');
    }

    public function test_late_checkout_validates_time_format(): void
    {
        $booking = $this->departingToday();

        $this->lateCheckout($booking, '3 PM')->assertStatus(422)->assertJsonValidationErrors('time');
        $this->lateCheckout($booking, '25:00')->assertStatus(422)->assertJsonValidationErrors('time');
    }
}
