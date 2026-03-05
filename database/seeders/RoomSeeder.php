<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $deluxe = \App\Models\RoomType::create([
            'name' => 'Deluxe Room',
            'description' => 'A comfortable standard room with essential amenities.',
            'base_price' => 2500,
            'capacity' => 2,
            'amenities' => ['Wifi', 'AC', 'TV', 'Mini Bar']
        ]);

        $suite = \App\Models\RoomType::create([
            'name' => 'Luxury Suite',
            'description' => 'Spacious suite with a balcony and premium furnishings.',
            'base_price' => 5000,
            'capacity' => 4,
            'amenities' => ['Wifi', 'AC', 'TV', 'Mini Bar', 'Bathtub', 'Balcony']
        ]);

        // Create Rooms for Deluxe
        for ($i = 101; $i <= 105; $i++) {
            \App\Models\Room::create([
                'room_number' => (string)$i,
                'room_type_id' => $deluxe->id,
                'floor' => '1st Floor',
                'status' => 'available'
            ]);
        }

        // Create Rooms for Suite
        for ($i = 201; $i <= 203; $i++) {
            \App\Models\Room::create([
                'room_number' => (string)$i,
                'room_type_id' => $suite->id,
                'floor' => '2nd Floor',
                'status' => 'available'
            ]);
        }
    }
}
