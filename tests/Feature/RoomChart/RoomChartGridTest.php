<?php

namespace Tests\Feature\RoomChart;

use Laravel\Sanctum\Sanctum;

class RoomChartGridTest extends RoomChartTestCase
{
    public function test_chart_requires_reservation_or_room_view_permission(): void
    {
        $this->makeRoom('101');

        Sanctum::actingAs($this->userWith([]));
        $this->getJson('/api/bookings/chart')->assertForbidden();

        Sanctum::actingAs($this->userWith(['view-rooms']));
        $this->getJson('/api/bookings/chart')->assertOk();
    }

    public function test_chart_defaults_to_a_fourteen_day_window_from_today(): void
    {
        $this->actingWith(['reservation-view']);

        $this->getJson('/api/bookings/chart')
            ->assertOk()
            ->assertJsonPath('start', self::TODAY)
            ->assertJsonPath('end', $this->day(13));
    }

    public function test_chart_returns_segments_and_blocks_and_hides_cancelled_stays(): void
    {
        $this->actingWith(['reservation-view']);
        $booked = $this->makeRoom('101');
        $maintenance = $this->makeRoom('102');
        $cancelled = $this->makeRoom('103');

        $booking = $this->makeBooking($booked, $this->day(0), $this->day(2));
        $this->makeBlock($maintenance, 'maintenance', $this->day(3), $this->day(5));
        $this->makeBooking($cancelled, $this->day(1), $this->day(3), ['status' => 'cancelled']);

        $rooms = collect($this->getJson('/api/bookings/chart?start=' . $this->day(0) . '&end=' . $this->day(6))
            ->assertOk()
            ->json('rooms'))->keyBy('room_number');

        $this->assertCount(3, $rooms);
        $this->assertCount(1, $rooms['101']['segments']);
        $this->assertSame($booking->id, $rooms['101']['segments'][0]['booking']['id']);
        $this->assertCount(1, $rooms['102']['status_blocks']);
        $this->assertSame('maintenance', $rooms['102']['status_blocks'][0]['status']);
        $this->assertCount(0, $rooms['103']['segments']);
    }

    public function test_chart_excludes_released_blocks(): void
    {
        $this->actingWith(['reservation-view']);
        $room = $this->makeRoom('101');
        $this->makeBlock($room, 'on_hold', $this->day(1), $this->day(2), ['is_active' => false]);

        $rooms = $this->getJson('/api/bookings/chart?start=' . $this->day(0) . '&end=' . $this->day(6))->json('rooms');

        $this->assertCount(0, $rooms[0]['status_blocks']);
    }

    /**
     * The grid's end date is inclusive (segments use end + 1 day), so a block that starts on the
     * last visible column must be returned too.
     */
    public function test_chart_includes_block_starting_on_last_visible_day(): void
    {
        $this->actingWith(['reservation-view']);
        $room = $this->makeRoom('101');
        $this->makeBlock($room, 'maintenance', $this->day(6), $this->day(7));

        $rooms = $this->getJson('/api/bookings/chart?start=' . $this->day(0) . '&end=' . $this->day(6))->json('rooms');

        $this->assertCount(1, $rooms[0]['status_blocks'], 'Maintenance block on the last chart day is missing from the chart payload.');
    }

    public function test_chart_includes_segment_arriving_on_last_visible_day(): void
    {
        $this->actingWith(['reservation-view']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(6), $this->day(8));

        $rooms = $this->getJson('/api/bookings/chart?start=' . $this->day(0) . '&end=' . $this->day(6))->json('rooms');

        $this->assertCount(1, $rooms[0]['segments']);
    }

    public function test_summary_counts_room_states_for_today(): void
    {
        $this->actingWith(['reservation-view']);
        $occupied = $this->makeRoom('101');
        $reserved = $this->makeRoom('102');
        $maintenance = $this->makeRoom('103');
        $dirty = $this->makeRoom('104');
        $cleaning = $this->makeRoom('105');
        $this->makeRoom('106');

        $this->makeBooking($occupied, $this->day(-1), $this->day(2), ['status' => 'checked_in']);
        $this->makeBooking($reserved, $this->day(0), $this->day(1));
        $this->makeBlock($maintenance, 'maintenance', $this->day(0), $this->day(3));
        $this->makeBlock($dirty, 'dirty', $this->day(0), $this->day(1));
        $this->makeBlock($cleaning, 'cleaning', $this->day(0), $this->day(1));

        $this->getJson('/api/bookings/summary')
            ->assertOk()
            ->assertJson([
                'total' => 6,
                'occupied' => 1,
                'reserved' => 1,
                'maintenance' => 1,
                'dirty' => 1,
                'cleaning' => 1,
                'available' => 1,
                'checkins_today' => 1,
                'checkouts_today' => 0,
            ]);
    }

    public function test_summary_treats_block_end_date_as_exclusive(): void
    {
        $this->actingWith(['reservation-view']);
        $room = $this->makeRoom('101');
        $this->makeBlock($room, 'dirty', $this->day(-1), $this->day(0));

        $this->getJson('/api/bookings/summary')
            ->assertOk()
            ->assertJsonPath('dirty', 0)
            ->assertJsonPath('available', 1);
    }

    /**
     * A room on hold cannot be sold (HARD_BLOCK_STATUSES), so the "Available" tile should not count it.
     */
    public function test_summary_does_not_count_room_on_hold_as_available(): void
    {
        $this->actingWith(['reservation-view']);
        $room = $this->makeRoom('101');
        $this->makeBlock($room, 'on_hold', $this->day(0), $this->day(2));

        $this->getJson('/api/bookings/summary')
            ->assertOk()
            ->assertJsonPath('available', 0);
    }
}
