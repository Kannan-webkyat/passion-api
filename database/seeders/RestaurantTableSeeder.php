<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RestaurantMaster;
use App\Models\TableCategory;
use App\Models\RestaurantTable;
use App\Models\InventoryLocation;

class RestaurantTableSeeder extends Seeder
{
    public function run(): void
    {
        // ── Restaurants / Outlets ──────────────────────────────────────────
        $mainDining = RestaurantMaster::firstOrCreate(
            ['name' => 'The Grand Dining'],
            [
                'floor'       => 'Ground Floor',
                'description' => 'Main restaurant with à la carte and buffet service.',
                'is_active'   => true,
            ]
        );

        $rooftop = RestaurantMaster::firstOrCreate(
            ['name' => 'Skyline Rooftop'],
            [
                'floor'       => 'Rooftop - 5th Floor',
                'description' => 'Open-air rooftop bar and grill with panoramic views.',
                'is_active'   => true,
            ]
        );

        $poolside = RestaurantMaster::firstOrCreate(
            ['name' => 'Poolside Café'],
            [
                'floor'       => 'Ground Floor',
                'description' => 'Casual poolside dining with snacks and beverages.',
                'is_active'   => true,
            ]
        );

        // ── Table Categories ───────────────────────────────────────────────
        $twoSeater = TableCategory::firstOrCreate(
            ['name' => '2-Seater'],
            ['capacity' => 2, 'description' => 'Intimate table for couples.']
        );

        $fourSeater = TableCategory::firstOrCreate(
            ['name' => '4-Seater'],
            ['capacity' => 4, 'description' => 'Standard family table.']
        );

        $sixSeater = TableCategory::firstOrCreate(
            ['name' => '6-Seater'],
            ['capacity' => 6, 'description' => 'Large group table.']
        );

        $booth = TableCategory::firstOrCreate(
            ['name' => 'Booth'],
            ['capacity' => 4, 'description' => 'Semi-private booth seating.']
        );

        // ── Tables — The Grand Dining ──────────────────────────────────────
        $mainTables = [
            ['T-01', $twoSeater,  2,  'Window Side'],
            ['T-02', $twoSeater,  2,  'Window Side'],
            ['T-03', $fourSeater, 4,  'Centre Hall'],
            ['T-04', $fourSeater, 4,  'Centre Hall'],
            ['T-05', $fourSeater, 4,  'Centre Hall'],
            ['T-06', $sixSeater,  6,  'Private Corner'],
            ['T-07', $booth,      4,  'Entrance Lounge'],
            ['T-08', $booth,      4,  'Entrance Lounge'],
        ];

        foreach ($mainTables as [$num, $cat, $cap, $loc]) {
            RestaurantTable::firstOrCreate(
                ['table_number' => $num, 'restaurant_master_id' => $mainDining->id],
                [
                    'category_id' => $cat->id,
                    'capacity'    => $cap,
                    'status'      => 'available',
                    'location'    => $loc,
                ]
            );
        }

        // ── Tables — Skyline Rooftop ───────────────────────────────────────
        $rooftopTables = [
            ['R-01', $twoSeater,  2,  'East View'],
            ['R-02', $twoSeater,  2,  'East View'],
            ['R-03', $fourSeater, 4,  'Bar Adjacent'],
            ['R-04', $fourSeater, 4,  'Bar Adjacent'],
            ['R-05', $sixSeater,  6,  'Panorama Section'],
        ];

        foreach ($rooftopTables as [$num, $cat, $cap, $loc]) {
            RestaurantTable::firstOrCreate(
                ['table_number' => $num, 'restaurant_master_id' => $rooftop->id],
                [
                    'category_id' => $cat->id,
                    'capacity'    => $cap,
                    'status'      => 'available',
                    'location'    => $loc,
                ]
            );
        }

        // ── Tables — Poolside Café ─────────────────────────────────────────
        $poolTables = [
            ['P-01', $twoSeater,  2,  'Pool Deck'],
            ['P-02', $twoSeater,  2,  'Pool Deck'],
            ['P-03', $fourSeater, 4,  'Cabana Zone'],
            ['P-04', $fourSeater, 4,  'Cabana Zone'],
        ];

        foreach ($poolTables as [$num, $cat, $cap, $loc]) {
            RestaurantTable::firstOrCreate(
                ['table_number' => $num, 'restaurant_master_id' => $poolside->id],
                [
                    'category_id' => $cat->id,
                    'capacity'    => $cap,
                    'status'      => 'available',
                    'location'    => $loc,
                ]
            );
        }

        // Map outlets to kitchens (multi-kitchen)
        $rooftopKitchen = InventoryLocation::where('name', 'Rooftop Kitchen')->first();
        $poolBarKitchen = InventoryLocation::where('name', 'Pool Bar Kitchen')->first();
        if ($rooftopKitchen) {
            $mainDining->update(['kitchen_location_id' => $rooftopKitchen->id]);
            $rooftop->update(['kitchen_location_id' => $rooftopKitchen->id]);
        }
        if ($poolBarKitchen) {
            $poolside->update(['kitchen_location_id' => $poolBarKitchen->id]);
        }

        $this->command->info('Restaurant & Table seeder completed.');
        $this->command->info('  Restaurants : 3');
        $this->command->info('  Categories  : 4');
        $this->command->info('  Tables      : ' . RestaurantTable::count());
    }
}
