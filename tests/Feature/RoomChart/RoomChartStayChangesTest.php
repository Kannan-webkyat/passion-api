<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingSegment;
use Carbon\Carbon;

class RoomChartStayChangesTest extends RoomChartTestCase
{
    // ── Extend stay ─────────────────────────────────────────────────────────

    public function test_extend_adds_nights_to_total_segment_and_audit_note(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(3)])->assertOk();

        $booking->refresh();
        $this->assertSame($this->day(3), (string) $booking->check_out);
        $this->assertSame(6720.0, (float) $booking->total_price);
        $this->assertStringContainsString('[Extension: ' . $this->day(2) . ' → ' . $this->day(3) . ' by Front Desk', (string) $booking->notes);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'check_out' => $this->day(3), 'total_price' => 6720]);
    }

    public function test_extend_rejects_date_not_after_current_checkout(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(2)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_check_out');
    }

    public function test_extend_returns_conflict_when_room_is_booked_next(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));
        $next = $this->makeBooking($room, $this->day(2), $this->day(4));

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(3)])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Room Conflict Detected')
            ->assertJsonPath('conflict.id', $next->id);
    }

    public function test_extend_rejected_when_room_is_on_hold(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));
        $this->makeBlock($room, 'on_hold', $this->day(2), $this->day(4));

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(3)])
            ->assertStatus(409)
            ->assertJsonPath('on_hold', true);
    }

    /**
     * Maintenance is a HARD_BLOCK status for every other sell path, but extend only checks on_hold.
     */
    public function test_extend_rejected_when_room_is_under_maintenance(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));
        $this->makeBlock($room, 'maintenance', $this->day(2), $this->day(4));

        $status = $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(3)])->status();

        $this->assertContains($status, [409, 422], "Extension into a maintenance block returned HTTP {$status}.");
    }

    public function test_extend_rejected_for_cancelled_or_checked_out_booking(): void
    {
        $this->actingWith(['reservation-edit']);
        $cancelled = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2), ['status' => 'cancelled']);
        $departed = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_out']);

        $this->postJson("/api/bookings/{$cancelled->id}/extend", ['new_check_out' => $this->day(3)])->assertStatus(422);
        $this->postJson("/api/bookings/{$departed->id}/extend", ['new_check_out' => $this->day(2)])->assertStatus(422);
    }

    /**
     * Rate plans store meals in meal_plan_type; the API reads a non-existent includes_breakfast attribute.
     */
    public function test_extend_prices_breakfast_for_breakfast_meal_plan(): void
    {
        $this->actingWith(['reservation-edit']);
        $cp = $this->makeRatePlan($this->roomType, ['name' => 'CP', 'meal_plan_type' => 'breakfast']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2), ['rate_plan_id' => $cp->id]);
        $before = (float) $booking->total_price;

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(3)])->assertOk();

        // (₹2000 room + 2 adults × ₹300 breakfast) × 1.12 GST
        $this->assertEqualsWithDelta(2912.0, (float) $booking->fresh()->total_price - $before, 0.01);
    }

    // ── Hourly extension ────────────────────────────────────────────────────

    private function makeHourlyBooking(): Booking
    {
        $plan = $this->makeHourlyPlan();
        $room = $this->makeRoom('201');

        return $this->makeBooking($room, $this->day(0), $this->day(0), [
            'booking_unit' => 'hour_package',
            'rate_plan_id' => $plan->id,
            'check_in_at' => Carbon::parse($this->day(0) . ' 12:00:00'),
            'check_out_at' => Carbon::parse($this->day(0) . ' 15:00:00'),
            'check_out' => $this->day(1),
            'total_price' => 1120,
            'status' => 'checked_in',
        ]);
    }

    public function test_preview_extend_hours_returns_delta_without_saving(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeHourlyBooking();

        $this->postJson("/api/bookings/{$booking->id}/preview-extend-hours", ['extend_minutes' => 60])
            ->assertOk()
            ->assertJson(['current_total' => 1120, 'new_total' => 1456, 'delta' => 336, 'has_conflict' => false]);

        $this->assertSame(1120.0, (float) $booking->fresh()->total_price);
    }

    public function test_extend_hours_updates_checkout_and_total(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeHourlyBooking();

        $this->postJson("/api/bookings/{$booking->id}/extend-hours", ['extend_minutes' => 60])->assertOk();

        $booking->refresh();
        $this->assertSame($this->day(0) . ' 16:00:00', $booking->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(1456.0, (float) $booking->total_price);
        $this->assertStringContainsString('[Hourly Extension: +1h', (string) $booking->notes);
    }

    public function test_extend_hours_reports_conflict_with_next_hourly_guest(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeHourlyBooking();
        $next = Booking::query()->create([
            'room_id' => $booking->room_id, 'first_name' => 'Next', 'last_name' => 'Guest', 'status' => 'confirmed',
            'check_in' => $this->day(0), 'check_out' => $this->day(1), 'booking_unit' => 'hour_package',
            'check_in_at' => Carbon::parse($this->day(0) . ' 15:30:00'), 'check_out_at' => Carbon::parse($this->day(0) . ' 18:30:00'),
        ]);
        BookingSegment::query()->create([
            'booking_id' => $next->id, 'room_id' => $booking->room_id, 'status' => 'confirmed',
            'check_in' => $this->day(0), 'check_out' => $this->day(1),
            'check_in_at' => $next->check_in_at, 'check_out_at' => $next->check_out_at,
        ]);

        $this->postJson("/api/bookings/{$booking->id}/preview-extend-hours", ['extend_minutes' => 60])
            ->assertOk()
            ->assertJsonPath('has_conflict', true);

        $this->postJson("/api/bookings/{$booking->id}/extend-hours", ['extend_minutes' => 60])->assertStatus(409);
    }

    public function test_extend_hours_rejected_for_day_booking_and_when_overtime_not_allowed(): void
    {
        $this->actingWith(['reservation-edit']);
        $day = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$day->id}/extend-hours", ['extend_minutes' => 60])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This extension endpoint is only for hourly package bookings.');

        $hourly = $this->makeHourlyBooking();
        $hourly->ratePlan->update(['overtime_hour_price' => null]);
        $this->postJson("/api/bookings/{$hourly->id}/extend-hours", ['extend_minutes' => 60])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Overtime is not allowed for this package.');
    }

    // ── Early checkout ──────────────────────────────────────────────────────

    public function test_preview_early_checkout_recalculates_charges_and_credit(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(3), [
            'status' => 'checked_in',
            'deposit_amount' => 8960,
        ]);

        $this->postJson("/api/bookings/{$booking->id}/preview-early-checkout", ['new_check_out' => $this->day(1)])
            ->assertOk()
            ->assertJson([
                'original_nights' => 4,
                'new_nights' => 2,
                'original_room_charges' => 8960,
                'updated_room_charges' => 4480,
                'credit_balance' => 4480,
                'additional_due' => 0,
            ]);
    }

    public function test_apply_early_checkout_shortens_stay_and_audits(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(3), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/early-checkout", [
            'new_check_out' => $this->day(1),
            'reason' => 'guest_changed_plans',
        ])->assertOk()->assertJsonPath('suggest_settle_folio', true);

        $booking->refresh();
        $this->assertSame($this->day(1), (string) $booking->check_out);
        $this->assertSame(4480.0, (float) $booking->total_price);
        $this->assertStringContainsString('[Early Checkout: ' . $this->day(3) . ' → ' . $this->day(1) . ' | Reason: Guest changed plans', (string) $booking->notes);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'check_out' => $this->day(1)]);
    }

    public function test_early_checkout_guards(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(3), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/preview-early-checkout", ['new_check_out' => $this->day(-1)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'For in-house guests, early checkout cannot be before today.');

        $this->postJson("/api/bookings/{$booking->id}/preview-early-checkout", ['new_check_out' => $this->day(3)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'New checkout must be before the scheduled checkout date.');

        $this->postJson("/api/bookings/{$booking->id}/early-checkout", ['new_check_out' => $this->day(1), 'reason' => 'bored'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * Split / transferred stays: early checkout recomputes only the last segment but writes that
     * amount as the whole booking total, dropping the earlier segment's charges.
     */
    public function test_early_checkout_on_split_stay_keeps_earlier_segment_charges(): void
    {
        $this->actingWith(['reservation-edit']);
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');
        $booking = $this->makeBooking($a, $this->day(-2), $this->day(3), ['status' => 'checked_in', 'total_price' => 11200]);
        BookingSegment::query()->where('booking_id', $booking->id)->update([
            'check_out' => $this->day(0),
            'check_out_at' => Carbon::parse($this->day(0)),
            'total_price' => 4480,
            'status' => 'checked_out',
        ]);
        BookingSegment::query()->create([
            'booking_id' => $booking->id, 'room_id' => $b->id, 'status' => 'checked_in',
            'check_in' => $this->day(0), 'check_out' => $this->day(3),
            'check_in_at' => Carbon::parse($this->day(0)), 'check_out_at' => Carbon::parse($this->day(3)),
            'rate_plan_id' => $this->dayPlan->id, 'total_price' => 6720,
        ]);

        $this->postJson("/api/bookings/{$booking->id}/early-checkout", [
            'new_check_out' => $this->day(1),
            'reason' => 'guest_changed_plans',
        ])->assertOk();

        // Room 101: 2 nights (4480) + Room 102: 1 night (2240)
        $this->assertSame(6720.0, (float) $booking->fresh()->total_price);
    }

    // ── Early check-in / late checkout ──────────────────────────────────────

    public function test_early_check_in_charges_per_hour_fee(): void
    {
        $this->actingWith(['reservation-edit']);
        $this->roomType->update(['early_check_in_fee' => 500, 'early_check_in_type' => 'per_hour', 'early_check_in_buffer_minutes' => 0]);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => '11:00'])->assertOk();

        $booking->refresh();
        $this->assertStringStartsWith('11:00', (string) $booking->early_checkin_time);
        $this->assertSame(1500.0, (float) $booking->extra_charges, 'Early check-in 3h before 14:00 at ₹500/h should add ₹1500.');
    }

    public function test_early_check_in_repeated_does_not_stack_fee(): void
    {
        $this->actingWith(['reservation-edit']);
        $this->roomType->update(['early_check_in_fee' => 500, 'early_check_in_type' => 'per_hour']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => '11:00'])->assertOk();
        $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => '11:00'])->assertOk();

        $this->assertSame(1500.0, (float) $booking->fresh()->extra_charges);
    }

    public function test_early_check_in_conflicts_with_guest_still_in_room(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => '09:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Early check-in conflicts with a previous guest still occupying the room.');
    }

    public function test_late_checkout_charges_per_hour_fee(): void
    {
        $this->actingWith(['reservation-edit']);
        $this->roomType->update(['late_check_out_fee' => 200, 'late_check_out_type' => 'per_hour', 'late_check_out_buffer_minutes' => 0]);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(0), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '14:00'])->assertOk();

        $booking->refresh();
        $this->assertStringStartsWith('14:00', (string) $booking->late_checkout_time);
        $this->assertSame(600.0, (float) $booking->extra_charges, 'Late checkout 3h after 11:00 at ₹200/h should add ₹600.');
    }

    public function test_late_checkout_back_to_standard_time_clears_it(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(0), [
            'status' => 'checked_in',
            'late_checkout_time' => '14:00',
        ]);

        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '11:00'])->assertOk();

        $booking->refresh();
        $this->assertNull($booking->late_checkout_time);
        $this->assertStringContainsString('[Late CO cleared: 11:00', (string) $booking->notes);
    }

    public function test_late_checkout_conflicts_with_next_arrival(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '15:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Late checkout conflicts with the next guest\'s check-in on the same day.');
    }

    // ── Split stay ──────────────────────────────────────────────────────────

    public function test_split_stay_adds_segment_on_new_room(): void
    {
        $this->actingWith(['reservation-edit']);
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');
        $booking = $this->makeBooking($a, $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/split-stay", [
            'new_room_id' => $b->id,
            'new_check_out' => $this->day(4),
        ])->assertOk();

        $booking->refresh();
        $this->assertSame($this->day(4), (string) $booking->check_out);
        $this->assertSame(8960.0, (float) $booking->total_price);
        $this->assertStringContainsString('[Split Stay: Room #102 from ' . $this->day(2) . ' to ' . $this->day(4), (string) $booking->notes);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $b->id, 'check_in' => $this->day(2), 'check_out' => $this->day(4)]);
    }

    public function test_split_stay_complimentary_upgrade_is_free(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/split-stay", [
            'new_room_id' => $this->makeRoom('102')->id,
            'new_check_out' => $this->day(4),
            'complimentary_upgrade' => true,
        ])->assertOk();

        $this->assertSame(4480.0, (float) $booking->fresh()->total_price);
    }

    public function test_split_stay_does_not_add_gst_when_room_rates_include_gst(): void
    {
        $this->actingWith(['reservation-edit']);
        $this->setting('room_rates_include_gst', '1');
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $b = $this->makeRoom('102');

        $this->postJson("/api/bookings/{$booking->id}/split-stay", [
            'new_room_id' => $b->id,
            'new_check_out' => $this->day(4),
        ])->assertOk();

        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $b->id, 'total_price' => 4000]);
        $this->assertSame(8480.0, (float) $booking->fresh()->total_price);
    }

    public function test_split_stay_rejects_same_room(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/split-stay", ['new_room_id' => $room->id, 'new_check_out' => $this->day(4)])
            ->assertStatus(422);
    }

    /**
     * Known gap (see 50-hotel-domain rule): splitStay() has no availability check.
     */
    public function test_split_stay_rejects_room_already_reserved(): void
    {
        $this->actingWith(['reservation-edit']);
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');
        $booking = $this->makeBooking($a, $this->day(0), $this->day(2));
        $this->makeBooking($b, $this->day(2), $this->day(5));

        $this->postJson("/api/bookings/{$booking->id}/split-stay", ['new_room_id' => $b->id, 'new_check_out' => $this->day(4)])
            ->assertStatus(422);
    }

    // ── Change check-in date ────────────────────────────────────────────────

    public function test_preview_change_check_in_returns_new_dates_without_saving(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->postJson("/api/bookings/{$booking->id}/preview-change-check-in", ['new_check_in' => $this->day(4), 'keep_nights' => true])
            ->assertOk()
            ->assertJsonPath('new_check_in', $this->day(4))
            ->assertJsonPath('new_check_out', $this->day(6))
            ->assertJsonPath('new_nights', 2);

        $this->assertSame($this->day(1), (string) $booking->fresh()->check_in);
    }

    public function test_change_check_in_keeping_nights_moves_checkout_and_keeps_total(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $this->postJson("/api/bookings/{$booking->id}/change-check-in", ['new_check_in' => $this->day(4), 'keep_nights' => true])
            ->assertOk()
            ->assertJsonPath('booking.id', $booking->id);

        $booking->refresh();
        $this->assertSame($this->day(4), (string) $booking->check_in);
        $this->assertSame($this->day(6), (string) $booking->check_out);
        $this->assertSame(4480.0, (float) $booking->total_price);
        $this->assertStringContainsString('[Check-in date: ' . $this->day(1) . ' → ' . $this->day(4), (string) $booking->notes);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'check_in' => $this->day(4), 'check_out' => $this->day(6)]);
    }

    public function test_change_check_in_without_keeping_nights_reprices_by_booked_nightly_rate(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(4));

        $this->postJson("/api/bookings/{$booking->id}/change-check-in", ['new_check_in' => $this->day(2), 'keep_nights' => false])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($this->day(2), (string) $booking->check_in);
        $this->assertSame($this->day(4), (string) $booking->check_out);
        $this->assertSame(4480.0, (float) $booking->total_price);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'check_in' => $this->day(2), 'total_price' => 4480]);
    }

    public function test_change_check_in_rejected_when_room_is_taken_on_new_dates(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(3), $this->day(5));
        $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/change-check-in", ['new_check_in' => $this->day(1), 'keep_nights' => false])
            ->assertStatus(422);

        $this->assertSame($this->day(3), (string) $booking->fresh()->check_in);
    }

    public function test_change_check_in_rejected_for_past_date_checked_in_booking_and_without_permission(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $inHouse = $this->makeBooking($this->makeRoom('102'), $this->day(0), $this->day(2), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/change-check-in", ['new_check_in' => $this->day(-1), 'keep_nights' => true])
            ->assertStatus(422);
        $this->postJson("/api/bookings/{$inHouse->id}/change-check-in", ['new_check_in' => $this->day(1), 'keep_nights' => true])
            ->assertStatus(422);

        $this->actingWith([]);
        $this->postJson("/api/bookings/{$booking->id}/change-check-in", ['new_check_in' => $this->day(2), 'keep_nights' => true])
            ->assertForbidden();
    }
}
