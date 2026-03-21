<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $deluxe = \App\Models\RoomType::firstOrCreate(
            ['name' => 'Deluxe Room'],
            [
                'description' => 'A comfortable standard room with essential amenities.',
                'base_price' => 2500,
                'base_occupancy' => 2,
                'capacity' => 2,
                'is_active' => true,
                'amenities' => ['Wifi', 'AC', 'TV', 'Mini Bar'],
            ]
        );

        $suite = \App\Models\RoomType::firstOrCreate(
            ['name' => 'Luxury Suite'],
            [
                'description' => 'Spacious suite with a balcony and premium furnishings.',
                'base_price' => 5000,
                'base_occupancy' => 4,
                'capacity' => 4,
                'is_active' => true,
                'amenities' => ['Wifi', 'AC', 'TV', 'Mini Bar', 'Bathtub', 'Balcony'],
            ]
        );

        // Create Rooms for Deluxe
        for ($i = 101; $i <= 105; $i++) {
            \App\Models\Room::firstOrCreate(
                ['room_number' => (string) $i],
                [
                    'room_type_id' => $deluxe->id,
                    'floor' => '1st Floor',
                    'status' => 'available',
                    'is_active' => true,
                ]
            );
        }

        // Create Rooms for Suite
        for ($i = 201; $i <= 203; $i++) {
            \App\Models\Room::firstOrCreate(
                ['room_number' => (string) $i],
                [
                    'room_type_id' => $suite->id,
                    'floor' => '2nd Floor',
                    'status' => 'available',
                    'is_active' => true,
                ]
            );
        }
    }
}
