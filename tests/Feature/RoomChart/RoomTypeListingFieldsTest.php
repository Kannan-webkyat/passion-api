<?php

namespace Tests\Feature\RoomChart;

class RoomTypeListingFieldsTest extends RoomChartTestCase
{
    public function test_room_type_stores_bedrooms_washrooms_and_weekday_weekend_prices(): void
    {
        $this->actingWith(['room-types-create', 'room-types-edit', 'room-types-view']);

        $created = $this->postJson('/api/room-types', [
            'name' => 'Suite',
            'extra_bed_cost' => 500,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 1,
            'child_sharing_limit' => 1,
            'capacity' => 4,
            'bedrooms' => 2,
            'washrooms' => 2,
            'weekday_price' => 4500,
            'weekend_price' => 5200,
        ]);

        $created->assertCreated()
            ->assertJsonPath('bedrooms', 2)
            ->assertJsonPath('washrooms', 2)
            ->assertJsonPath('weekday_price', '4500.00')
            ->assertJsonPath('weekend_price', '5200.00');

        $id = $created->json('id');

        $this->putJson("/api/room-types/{$id}", [
            'bedrooms' => 3,
            'weekend_price' => 6000,
        ])->assertOk()
            ->assertJsonPath('bedrooms', 3)
            ->assertJsonPath('washrooms', 2)
            ->assertJsonPath('weekday_price', '4500.00')
            ->assertJsonPath('weekend_price', '6000.00');
    }

    public function test_room_type_create_requires_listing_fields(): void
    {
        $this->actingWith(['room-types-create']);

        $this->postJson('/api/room-types', [
            'name' => 'Suite',
            'extra_bed_cost' => 500,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 0,
            'child_sharing_limit' => 0,
            'capacity' => 2,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['bedrooms', 'washrooms', 'weekday_price', 'weekend_price']);
    }

    public function test_room_type_stores_child_age_from_and_to(): void
    {
        $this->actingWith(['room-types-create', 'room-types-edit']);

        $created = $this->postJson('/api/room-types', [
            'name' => 'Deluxe',
            'extra_bed_cost' => 500,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 1,
            'child_sharing_limit' => 1,
            'capacity' => 4,
            'bedrooms' => 1,
            'washrooms' => 1,
            'weekday_price' => 0,
            'weekend_price' => 0,
            'child_age_from' => 2,
            'child_age_limit' => 12,
        ]);

        $created->assertCreated()
            ->assertJsonPath('child_age_from', 2)
            ->assertJsonPath('child_age_limit', 12);

        $id = $created->json('id');

        $this->putJson("/api/room-types/{$id}", [
            'child_age_from' => 14,
            'child_age_limit' => 12,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['child_age_from']);
    }
}
