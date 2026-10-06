<?php

namespace Database\Seeders;

use App\Models\InventoryTax;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

/**
 * Passions hotel room types (only stay, breakfast, breakfast + 1 meal, all meals) and second-floor rooms 201–212.
 * Idempotent: matches on room type name, room number, and (room type, meal plan, billing unit).
 * Requires InventoryTaxSeeder (GST 5% accommodation tax).
 */
class RoomTypeRoomSeeder extends Seeder
{
    public function run(): void
    {
        $taxId = InventoryTax::where('name', 'GST 5%')->value('id')
            ?? InventoryTax::where('name', 'GST 5% (Local)')->value('id');

        $shared = [
            'description' => null,
            'is_active' => true,
            'tax_id' => $taxId,
            'breakfast_price' => 250.00,
            'child_breakfast_price' => 125.00,
            'adult_meal_price' => 400.00,
            'child_meal_price' => 200.00,
            'child_age_from' => 0,
            'child_age_limit' => 12,
            'extra_bed_cost' => 1000.00,
            'child_extra_bed_cost' => 500.00,
            'bedrooms' => 1,
            'washrooms' => 1,
            'early_check_in_fee' => 500.00,
            'early_check_in_type' => 'flat_fee',
            'early_check_in_buffer_minutes' => 120,
            'late_check_out_fee' => 500.00,
            'late_check_out_type' => 'flat_fee',
            'late_check_out_buffer_minutes' => 120,
            'base_occupancy' => 2,
            'capacity' => 4,
            'extra_bed_capacity' => 1,
            'child_sharing_limit' => 1,
            'bed_config' => null,
        ];

        $types = [
            ['name' => 'Deluxe Room', 'night_rate' => 2250.00],
            ['name' => 'Junior Suite', 'night_rate' => 3750.00],
            ['name' => 'Premium Deluxe', 'night_rate' => 2750.00],
            ['name' => 'Family', 'night_rate' => 3250.00, 'child_breakfast_price' => 175.00],
        ];

        $mealPlans = [
            ['name' => 'Only stay', 'meal_plan_type' => 'room_only'],
            ['name' => 'Breakfast included', 'meal_plan_type' => 'breakfast'],
            ['name' => 'Breakfast + 1 meal', 'meal_plan_type' => 'half_board'],
            ['name' => 'All meals included', 'meal_plan_type' => 'full_board'],
        ];

        $kingBed = '1 King Bed';
        $rooms = [
            ['room_number' => '201', 'type' => 'Family', 'bed_config' => '2 Queen Size Bed'],
            ['room_number' => '202', 'type' => 'Premium Deluxe', 'bed_config' => $kingBed],
            ['room_number' => '203', 'type' => 'Premium Deluxe', 'bed_config' => $kingBed],
            ['room_number' => '204', 'type' => 'Premium Deluxe', 'bed_config' => $kingBed],
            ['room_number' => '205', 'type' => 'Premium Deluxe', 'bed_config' => $kingBed],
            ['room_number' => '206', 'type' => 'Premium Deluxe', 'bed_config' => $kingBed],
            ['room_number' => '207', 'type' => 'Deluxe Room', 'bed_config' => $kingBed],
            ['room_number' => '208', 'type' => 'Premium Deluxe', 'bed_config' => $kingBed],
            ['room_number' => '209', 'type' => 'Deluxe Room', 'bed_config' => $kingBed],
            ['room_number' => '210', 'type' => 'Junior Suite', 'bed_config' => $kingBed],
            ['room_number' => '211', 'type' => 'Deluxe Room', 'bed_config' => $kingBed],
            ['room_number' => '212', 'type' => 'Deluxe Room', 'bed_config' => $kingBed],
        ];

        $typeIds = [];
        foreach ($types as $def) {
            $nightRate = $def['night_rate'];
            unset($def['night_rate']);

            $roomType = RoomType::updateOrCreate(
                ['name' => $def['name']],
                array_merge($shared, $def, [
                    'weekday_price' => $nightRate,
                    'weekend_price' => $nightRate,
                ])
            );
            $typeIds[$roomType->name] = $roomType->id;

            foreach ($mealPlans as $plan) {
                $existing = RatePlan::query()
                    ->where('room_type_id', $roomType->id)
                    ->where('billing_unit', 'day')
                    ->where('meal_plan_type', $plan['meal_plan_type'])
                    ->first();

                if ($existing) {
                    $existing->update([
                        'name' => $plan['name'],
                        'base_price' => $nightRate,
                        'is_active' => true,
                    ]);
                    continue;
                }

                RatePlan::updateOrCreate(
                    [
                        'room_type_id' => $roomType->id,
                        'name' => $plan['name'],
                        'billing_unit' => 'day',
                    ],
                    [
                        'base_price' => $nightRate,
                        'meal_plan_type' => $plan['meal_plan_type'],
                        'is_active' => true,
                    ]
                );
            }
        }

        foreach ($rooms as $row) {
            Room::updateOrCreate(
                ['room_number' => $row['room_number']],
                [
                    'room_type_id' => $typeIds[$row['type']],
                    'floor' => '2nd',
                    'bed_config' => $row['bed_config'],
                    'view_type' => 'standard',
                    'is_smoking_allowed' => false,
                    'is_active' => true,
                    'status' => 'available',
                ]
            );
        }
    }
}
