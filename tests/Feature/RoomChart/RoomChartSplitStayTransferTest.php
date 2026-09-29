<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Support\BookingPaymentLedger;
use Carbon\Carbon;

/**
 * Room transfer on a booking that was already split into a second room by a stay extension.
 */
class RoomChartSplitStayTransferTest extends RoomChartTestCase
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

    private function splitInto(Booking $booking, Room $room, string $newCheckOut, array $overrides = []): Booking
    {
        $this->postJson("/api/bookings/{$booking->id}/split-stay", array_merge([
            'new_room_id' => $room->id,
            'new_check_out' => $newCheckOut,
        ], $overrides))->assertOk();

        return $booking->fresh();
    }

    private function segmentOn(Booking $booking, Room $room): BookingSegment
    {
        return BookingSegment::query()
            ->where('booking_id', $booking->id)
            ->where('room_id', $room->id)
            ->sole();
    }

    // ── Checked-in guest, still in the first room ───────────────────────────

    public function test_checked_in_transfer_moves_current_room_and_keeps_extension_segment(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $booking = $this->splitInto($booking, $b, $this->day(3));
        $this->assertSame(8960.0, (float) $booking->total_price);

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($c->id, ['from_room_id' => $a->id]))
            ->assertOk();

        $this->assertSame(3, BookingSegment::query()->where('booking_id', $booking->id)->count());
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $a->id, 'status' => 'checked_out', 'check_out' => $this->day(0)]);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $c->id, 'status' => 'checked_in', 'check_in' => $this->day(0), 'check_out' => $this->day(1)]);

        $extension = $this->segmentOn($booking, $b);
        $this->assertSame($this->day(1), (string) $extension->check_in);
        $this->assertSame($this->day(3), (string) $extension->check_out);
        $this->assertSame('checked_in', $extension->status);
        $this->assertEqualsWithDelta(4480.0, (float) $extension->total_price, 0.01);

        $booking->refresh();
        $this->assertSame($this->day(3), (string) $booking->check_out);
        $this->assertEqualsWithDelta(8960.0, (float) $booking->total_price, 0.01, 'Keep-existing transfer must not change the split stay total.');
        $this->assertSame((int) $c->id, (int) $booking->room_id);
        $this->assertSame('dirty', $a->fresh()->status);
        $this->assertSame('occupied', $c->fresh()->status);
        $this->assertSame('available', $b->fresh()->status, 'The extension room is not vacated by the transfer.');
    }

    public function test_checked_in_transfer_without_from_room_moves_current_segment(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $booking = $this->splitInto($booking, $b, $this->day(3));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($c->id))->assertOk();

        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $a->id, 'status' => 'checked_out']);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $c->id, 'check_out' => $this->day(1)]);
        $this->assertSame($this->day(1), (string) $this->segmentOn($booking, $b)->check_in);
    }

    public function test_checked_in_transfer_into_own_extension_room_for_remaining_nights(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b] = [$this->makeRoom('101'), $this->makeRoom('102')];
        $booking = $this->makeBooking($a, $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $booking = $this->splitInto($booking, $b, $this->day(3));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($b->id, ['from_room_id' => $a->id]))
            ->assertOk();

        $onB = BookingSegment::query()->where('booking_id', $booking->id)->where('room_id', $b->id)->orderBy('check_in_at')->get();
        $this->assertCount(2, $onB);
        $this->assertTrue(
            Carbon::parse($onB[0]->check_out_at)->lte(Carbon::parse($onB[1]->check_in_at)),
            'Segments on the extension room must not overlap.',
        );
        $this->assertSame($this->day(3), (string) $booking->fresh()->check_out);
    }

    public function test_checked_in_transfer_is_allowed_when_target_is_only_booked_in_extension_window(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $booking = $this->splitInto($booking, $b, $this->day(3));
        $this->makeBooking($c, $this->day(1), $this->day(4));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($c->id, ['from_room_id' => $a->id]))
            ->assertOk();
    }

    // ── Checked-in guest, future extension segment selected ─────────────────

    public function test_checked_in_transfer_of_future_extension_segment_swaps_it_in_place(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $d] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('104')];
        $booking = $this->makeBooking($a, $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $booking = $this->splitInto($booking, $b, $this->day(3));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($d->id, ['from_room_id' => $b->id]))
            ->assertOk();

        $this->assertSame(2, BookingSegment::query()->where('booking_id', $booking->id)->count());
        $current = $this->segmentOn($booking, $a);
        $this->assertSame('checked_in', $current->status);
        $this->assertSame($this->day(1), (string) $current->check_out);
        $moved = $this->segmentOn($booking, $d);
        $this->assertSame($this->day(1), (string) $moved->check_in);
        $this->assertSame($this->day(3), (string) $moved->check_out);

        $booking->refresh();
        $this->assertSame((int) $a->id, (int) $booking->room_id, 'Guest is still in the first room.');
        $this->assertEqualsWithDelta(8960.0, (float) $booking->total_price, 0.01);
        $this->assertSame('occupied', $a->fresh()->status);
        $this->assertSame('available', $d->fresh()->status, 'Future room must not be marked occupied yet.');
        $this->assertSame(0, RoomStatusBlock::query()->where('room_id', $b->id)->where('is_active', true)->count(), 'Nobody slept in the old extension room.');
    }

    // ── Checked-in guest, already moved into the extension room ─────────────

    public function test_transfer_after_guest_moved_into_extension_room_leaves_first_room_alone(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(-3), $this->day(-1), ['status' => 'checked_in']);
        $booking = $this->splitInto($booking, $b, $this->day(2));
        $a->update(['status' => 'available']);

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($c->id, ['from_room_id' => $b->id]))
            ->assertOk();

        $first = $this->segmentOn($booking, $a);
        $this->assertSame($this->day(-1), (string) $first->check_out);
        $this->assertEqualsWithDelta(4480.0, (float) $first->total_price, 0.01);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $b->id, 'status' => 'checked_out', 'check_out' => $this->day(0)]);
        $this->assertDatabaseHas('booking_segments', ['booking_id' => $booking->id, 'room_id' => $c->id, 'check_out' => $this->day(2)]);
        $this->assertSame('available', $a->fresh()->status);
        $this->assertSame('dirty', $b->fresh()->status);
        $this->assertEqualsWithDelta(11200.0, (float) $booking->fresh()->total_price, 0.01);
    }

    public function test_transfer_from_room_with_no_remaining_stay_is_rejected(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(-3), $this->day(-1), ['status' => 'checked_in']);
        $booking = $this->splitInto($booking, $b, $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($c->id, ['from_room_id' => $a->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transfer: stay segment has already ended.');

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($c->id, ['from_room_id' => $this->makeRoom('109')->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This booking has no stay in the selected room.');
    }

    // ── Reserved (not yet arrived) ──────────────────────────────────────────

    public function test_pre_arrival_transfer_from_arrival_room_moves_first_segment(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(1), $this->day(3));
        $booking = $this->splitInto($booking, $b, $this->day(5));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($c->id, ['from_room_id' => $a->id]))
            ->assertOk();

        $this->assertSame(2, BookingSegment::query()->where('booking_id', $booking->id)->count());
        $moved = $this->segmentOn($booking, $c);
        $this->assertSame($this->day(1), (string) $moved->check_in);
        $this->assertSame($this->day(3), (string) $moved->check_out);
        $this->assertSame($this->day(3), (string) $this->segmentOn($booking, $b)->check_in);

        $booking->refresh();
        $this->assertSame((int) $c->id, (int) $booking->room_id);
        $this->assertEqualsWithDelta(8960.0, (float) $booking->total_price, 0.01);
    }

    public function test_pre_arrival_transfer_from_arrival_room_ignores_conflicts_in_extension_window(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(1), $this->day(3));
        $booking = $this->splitInto($booking, $b, $this->day(5));
        $this->makeBooking($c, $this->day(3), $this->day(6));

        $this->postJson("/api/bookings/{$booking->id}/preview-room-transfer", $this->payload($c->id, ['from_room_id' => $a->id]))
            ->assertOk();
    }

    public function test_pre_arrival_transfer_of_extension_segment_keeps_arrival_room(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $d] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('104')];
        $booking = $this->makeBooking($a, $this->day(1), $this->day(3));
        $booking = $this->splitInto($booking, $b, $this->day(5));

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", $this->payload($d->id, ['from_room_id' => $b->id]))
            ->assertOk();

        $this->assertSame($this->day(3), (string) $this->segmentOn($booking, $d)->check_in);
        $this->assertSame($this->day(1), (string) $this->segmentOn($booking, $a)->check_in);
        $this->assertSame((int) $a->id, (int) $booking->fresh()->room_id, 'Arrival room must stay on the booking.');
    }

    public function test_keep_existing_transfer_of_complimentary_extension_stays_free(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($a, $this->day(1), $this->day(3));
        $booking = $this->splitInto($booking, $b, $this->day(5), ['complimentary_upgrade' => true]);
        $this->assertSame(4480.0, (float) $booking->total_price);

        $this->postJson("/api/bookings/{$booking->id}/preview-room-transfer", $this->payload($c->id, ['from_room_id' => $b->id]))
            ->assertOk()
            ->assertJson(['old_total' => 4480, 'new_total' => 4480, 'delta' => 0]);
    }

    // ── Check-out after split + transfer ────────────────────────────────────

    public function test_checkout_after_split_and_transfer_does_not_dirty_room_already_resold(): void
    {
        $this->actingWith(['reservation-edit']);
        [$a, $b, $c] = [$this->makeRoom('101'), $this->makeRoom('102'), $this->makeRoom('103')];
        $booking = $this->makeBooking($c, $this->day(-3), $this->day(0), ['status' => 'checked_in', 'total_price' => 6720]);
        BookingSegment::query()->where('booking_id', $booking->id)->delete();
        $segment = fn (Room $room, int $from, int $to, string $status, float $total) => BookingSegment::query()->create([
            'booking_id' => $booking->id, 'room_id' => $room->id, 'status' => $status,
            'check_in' => $this->day($from), 'check_out' => $this->day($to),
            'check_in_at' => Carbon::parse($this->day($from)), 'check_out_at' => Carbon::parse($this->day($to)),
            'rate_plan_id' => $this->dayPlan->id, 'total_price' => $total,
        ]);
        $segment($a, -3, -2, 'checked_out', 2240);
        $segment($c, -2, -1, 'checked_in', 2240);
        $segment($b, -1, 0, 'checked_in', 2240);

        $nextGuest = $this->makeBooking($a, $this->day(-1), $this->day(2), ['status' => 'checked_in']);
        $this->assertSame('occupied', $a->fresh()->status);

        BookingPaymentLedger::recordPayment($booking, [
            'amount' => 6720, 'method' => 'cash', 'source' => 'deposit', 'bill_total' => 6720,
        ]);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();

        $this->assertSame('occupied', $a->fresh()->status, 'Room 101 was vacated days ago and now hosts another guest.');
        $this->assertSame(0, RoomStatusBlock::query()->where('room_id', $a->id)->where('is_active', true)->count());
        $this->assertSame('dirty', $b->fresh()->status);
        $this->assertSame('checked_in', $nextGuest->fresh()->status);
    }
}
