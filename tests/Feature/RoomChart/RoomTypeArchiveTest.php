<?php

namespace Tests\Feature\RoomChart;

use App\Models\RoomType;

class RoomTypeArchiveTest extends RoomChartTestCase
{
    public function test_delete_archives_room_type_and_restore_brings_it_back(): void
    {
        $this->actingWith(['room-types-view', 'room-types-delete']);
        $type = $this->makeRoomType(['name' => 'Old Suite']);
        $plan = $type->ratePlans()->first();

        $this->deleteJson("/api/room-types/{$type->id}")->assertNoContent();

        $this->assertSoftDeleted('room_types', ['id' => $type->id]);
        if ($plan) {
            $this->assertDatabaseHas('rate_plans', ['id' => $plan->id]);
        }
        $this->getJson('/api/room-types?include_inactive=1')
            ->assertOk()
            ->assertJsonMissing(['id' => $type->id, 'name' => 'Old Suite']);
        $this->getJson('/api/room-types?archived=1')
            ->assertOk()
            ->assertJsonPath('0.id', $type->id);

        $this->postJson("/api/room-types/{$type->id}/restore")
            ->assertOk()
            ->assertJsonPath('id', $type->id)
            ->assertJsonPath('deleted_at', null);

        $this->assertNotSoftDeleted('room_types', ['id' => $type->id]);
    }

    public function test_room_type_with_rooms_cannot_be_archived(): void
    {
        $this->actingWith(['room-types-delete']);
        $type = $this->makeRoomType();
        $this->makeRoom('901', $type);

        $this->deleteJson("/api/room-types/{$type->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot archive room type as it has existing rooms assigned to it. Move or delete those rooms first.');

        $this->assertNotSoftDeleted('room_types', ['id' => $type->id]);
    }

    public function test_restore_requires_delete_permission(): void
    {
        $this->actingWith(['room-types-view']);
        $type = $this->makeRoomType();
        RoomType::query()->whereKey($type->id)->first()->delete();

        $this->postJson("/api/room-types/{$type->id}/restore")->assertForbidden();
    }
}
