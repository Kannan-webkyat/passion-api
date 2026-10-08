<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;

class RoomArchiveTest extends RoomChartTestCase
{
    public function test_delete_archives_room_keeps_history_and_restore_brings_it_back(): void
    {
        $this->actingWith(['rooms-view', 'rooms-delete', 'reservation-view']);
        $room = $this->makeRoom('801');
        $past = $this->makeBooking($room, $this->day(-5), $this->day(-3), ['status' => 'checked_out']);
        $block = $this->makeBlock($room, 'maintenance', $this->day(2), $this->day(4));

        $this->deleteJson("/api/rooms/{$room->id}")->assertNoContent();

        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('bookings', ['id' => $past->id]);
        $this->assertDatabaseHas('room_status_blocks', ['id' => $block->id, 'is_active' => false]);
        $this->assertSame('801', Booking::query()->find($past->id)->room?->room_number);

        $this->getJson('/api/rooms?include_inactive=1')
            ->assertOk()
            ->assertJsonMissing(['room_number' => '801']);
        $this->getJson('/api/rooms?archived=1')
            ->assertOk()
            ->assertJsonPath('0.id', $room->id);

        $this->postJson("/api/rooms/{$room->id}/restore")
            ->assertOk()
            ->assertJsonPath('id', $room->id)
            ->assertJsonPath('deleted_at', null);

        $this->assertNotSoftDeleted('rooms', ['id' => $room->id]);
    }

    public function test_room_with_upcoming_stay_cannot_be_archived(): void
    {
        $this->actingWith(['rooms-delete']);
        $room = $this->makeRoom('802');
        $this->makeBooking($room, $this->day(1), $this->day(3));

        $this->deleteJson("/api/rooms/{$room->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot archive Room #802 while it has a current or upcoming stay. Move or cancel that stay first.');

        $this->assertNotSoftDeleted('rooms', ['id' => $room->id]);
    }

    public function test_adding_an_archived_room_number_points_to_restore(): void
    {
        $this->actingWith(['rooms-create', 'rooms-delete']);
        $room = $this->makeRoom('803');
        $room->delete();

        $this->postJson('/api/rooms', [
            'room_number' => '803',
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Room #803 is archived. Restore it from Archived rooms instead of adding it again.');
    }

    public function test_room_of_archived_type_cannot_be_restored(): void
    {
        $this->actingWith(['rooms-delete']);
        $type = $this->makeRoomType(['name' => 'Old Type']);
        $room = $this->makeRoom('804', $type);
        $room->delete();
        $type->delete();

        $this->postJson("/api/rooms/{$room->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #804 belongs to an archived room type. Restore the room type first.');
    }
}
