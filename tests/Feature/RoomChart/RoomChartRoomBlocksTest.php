<?php

namespace Tests\Feature\RoomChart;

class RoomChartRoomBlocksTest extends RoomChartTestCase
{
    public function test_hold_and_maintenance_need_their_own_permissions(): void
    {
        $room = $this->makeRoom('101');

        $this->actingWith(['reservation-hold-room']);
        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'maintenance', 'start_date' => $this->day(1), 'end_date' => $this->day(2), 'note' => 'AC',
        ])->assertForbidden();

        $this->actingWith(['reservation-maintenance-room']);
        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(1), 'end_date' => $this->day(2), 'note' => 'VIP',
        ])->assertForbidden();
    }

    public function test_create_hold_block(): void
    {
        $this->actingWith(['reservation-hold-room']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(0), 'end_date' => $this->day(2), 'note' => 'VIP arrival',
        ])->assertCreated()->assertJsonPath('status', 'on_hold');

        $this->assertDatabaseHas('room_status_blocks', ['room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(0), 'end_date' => $this->day(2), 'is_active' => 1]);
        $this->assertSame('on_hold', $room->fresh()->status);
    }

    public function test_block_validation_rules(): void
    {
        $this->actingWith(['reservation-hold-room', 'reservation-maintenance-room']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/room-status-blocks', ['room_id' => $room->id, 'status' => 'maintenance', 'start_date' => $this->day(1), 'end_date' => $this->day(2)])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson('/api/room-status-blocks', ['room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(-1), 'end_date' => $this->day(2), 'note' => 'x'])
            ->assertStatus(422)->assertJsonPath('errors.start_date.0', 'Room holds cannot be created for past dates.');
        $this->postJson('/api/room-status-blocks', ['room_id' => $room->id, 'status' => 'maintenance', 'start_date' => $this->day(-1), 'end_date' => $this->day(2), 'note' => 'x'])
            ->assertStatus(422)->assertJsonPath('errors.start_date.0', 'Maintenance blocks can only start today or in the future.');
        $this->postJson('/api/room-status-blocks', ['room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(2), 'end_date' => $this->day(2), 'note' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_block_rejected_when_reservation_overlaps(): void
    {
        $this->actingWith(['reservation-maintenance-room']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(1), $this->day(3));

        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'maintenance', 'start_date' => $this->day(2), 'end_date' => $this->day(4), 'note' => 'Plumbing',
        ])->assertStatus(422)->assertJsonPath('message', 'Cannot mark Room #101 as maintenance because it already has a reservation in this date range.');
    }

    public function test_block_can_start_on_departure_day_of_previous_stay(): void
    {
        $this->actingWith(['reservation-maintenance-room']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(1), $this->day(3));

        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'maintenance', 'start_date' => $this->day(3), 'end_date' => $this->day(4), 'note' => 'Plumbing',
        ])->assertCreated();
    }

    public function test_block_rejected_when_another_block_overlaps(): void
    {
        $this->actingWith(['reservation-hold-room']);
        $room = $this->makeRoom('101');
        $this->makeBlock($room, 'maintenance', $this->day(1), $this->day(3));

        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(2), 'end_date' => $this->day(4), 'note' => 'VIP',
        ])->assertStatus(422)->assertJsonPath('message', 'Room already has an active status block in this period.');

        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(3), 'end_date' => $this->day(4), 'note' => 'VIP',
        ])->assertCreated();
    }

    public function test_release_block_makes_room_available(): void
    {
        $this->actingWith(['reservation-hold-room']);
        $room = $this->makeRoom('101', null, ['status' => 'on_hold']);
        $block = $this->makeBlock($room, 'on_hold', $this->day(0), $this->day(2));

        $this->putJson("/api/room-status-blocks/{$block->id}", ['is_active' => false])->assertOk();

        $this->assertFalse((bool) $block->fresh()->is_active);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_delete_block_needs_matching_permission(): void
    {
        $room = $this->makeRoom('101');
        $block = $this->makeBlock($room, 'maintenance', $this->day(0), $this->day(2));

        $this->actingWith(['reservation-hold-room']);
        $this->deleteJson("/api/room-status-blocks/{$block->id}")->assertForbidden();

        $this->actingWith(['reservation-maintenance-room']);
        $this->deleteJson("/api/room-status-blocks/{$block->id}")->assertNoContent();
        $this->assertDatabaseMissing('room_status_blocks', ['id' => $block->id]);
    }

    public function test_update_block_dates_checks_reservations(): void
    {
        $this->actingWith(['reservation-hold-room']);
        $room = $this->makeRoom('101');
        $block = $this->makeBlock($room, 'on_hold', $this->day(0), $this->day(2));
        $this->makeBooking($room, $this->day(3), $this->day(5));

        $this->putJson("/api/room-status-blocks/{$block->id}", ['end_date' => $this->day(4)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot update block for Room #101 — a reservation overlaps this date range.');
    }

    public function test_index_filters_by_window_and_status(): void
    {
        $this->actingWith(['reservation-hold-room']);
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');
        $this->makeBlock($a, 'on_hold', $this->day(1), $this->day(3));
        $this->makeBlock($b, 'maintenance', $this->day(1), $this->day(3));
        $this->makeBlock($b, 'maintenance', $this->day(10), $this->day(12));

        $this->getJson('/api/room-status-blocks?start=' . $this->day(0) . '&end=' . $this->day(5) . '&status=maintenance')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.room_id', $b->id);
    }

    /**
     * store() copies the block status onto rooms.status even when the block starts in the future,
     * so a hold for next week turns an occupied room "on_hold" today.
     */
    public function test_future_hold_does_not_overwrite_current_room_status(): void
    {
        $this->actingWith(['reservation-hold-room']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-1), $this->day(2), ['status' => 'checked_in']);

        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id, 'status' => 'on_hold', 'start_date' => $this->day(5), 'end_date' => $this->day(7), 'note' => 'VIP',
        ])->assertCreated();

        $this->assertSame('occupied', $room->fresh()->status);
    }

    /**
     * update()/destroy() reset rooms.status to "available" unconditionally.
     */
    public function test_releasing_future_hold_keeps_room_occupied(): void
    {
        $this->actingWith(['reservation-hold-room']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-1), $this->day(2), ['status' => 'checked_in']);
        $block = $this->makeBlock($room, 'on_hold', $this->day(5), $this->day(7));

        $this->deleteJson("/api/room-status-blocks/{$block->id}")->assertNoContent();

        $this->assertSame('occupied', $room->fresh()->status);
    }
}
