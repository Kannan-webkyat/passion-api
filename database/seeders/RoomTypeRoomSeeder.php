<?php

namespace Database\Seeders;

use App\Models\InventoryTax;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeSeason;
use Illuminate\Database\Seeder;

/**
 * Sample room types, rate plans (per night + hourly packages), seasonal rows, and rooms for Passions hotel.
 * Idempotent: matches on room type name, room number, and stable plan/season keys.
 * Requires InventoryTaxSeeder (GST 12% for accommodation tax when present).
 */
class RoomTypeRoomSeeder extends Seeder
{
    public function run(): void
    {
        $taxId = InventoryTax::where('name', 'GST 12% (Local)')->value('id');

        $amenitiesStandard = ['Wi-Fi', 'AC', 'TV', 'Hot water'];
        $amenitiesDeluxe = array_merge($amenitiesStandard, ['Mini fridge', 'Kettle']);
        $amenitiesFamily = array_merge($amenitiesDeluxe, ['Sofa bed', 'Extra wardrobe']);
        $amenitiesSuite = array_merge($amenitiesFamily, ['Living area', 'Bathtub']);

        $types = [
            [
                'name' => 'Standard',
                'description' => 'Twin-bed room for two guests; suitable for short stays.',
                'is_active' => true,
                'tax_id' => $taxId,
                'breakfast_price' => 150.00,
                'child_breakfast_price' => 100.00,
                'adult_lunch_price' => 350.00,
                'child_lunch_price' => 250.00,
                'adult_dinner_price' => 400.00,
                'child_dinner_price' => 280.00,
                'child_age_limit' => 12,
                'extra_bed_cost' => 800.00,
                'child_extra_bed_cost' => 600.00,
                'early_check_in_fee' => 500.00,
                'early_check_in_type' => 'flat_fee',
                'early_check_in_buffer_minutes' => 120,
                'late_check_out_fee' => 500.00,
                'late_check_out_type' => 'flat_fee',
                'late_check_out_buffer_minutes' => 120,
                'base_occupancy' => 2,
                'capacity' => 2,
                'extra_bed_capacity' => 1,
                'child_sharing_limit' => 1,
                'bed_config' => 'Twin',
                'amenities' => $amenitiesStandard,
                'rooms' => [
                    ['room_number' => '101', 'floor' => '1'],
                    ['room_number' => '102', 'floor' => '1'],
                    ['room_number' => '103', 'floor' => '1'],
                    ['room_number' => '104', 'floor' => '1'],
                    ['room_number' => '105', 'floor' => '1'],
                ],
                'rate_plans' => [
                    ['name' => 'Rack — room only', 'billing_unit' => 'day', 'base_price' => 2200.00, 'meal_plan_type' => 'room_only', 'is_active' => true],
                    ['name' => 'CP — breakfast', 'billing_unit' => 'day', 'base_price' => 2450.00, 'meal_plan_type' => 'breakfast', 'is_active' => true],
                    ['name' => 'MAP — half board', 'billing_unit' => 'day', 'base_price' => 3100.00, 'meal_plan_type' => 'half_board', 'is_active' => true],
                    [
                        'name' => 'Hourly 3h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 950.00, 'package_hours' => 3, 'package_price' => 950.00,
                        'grace_minutes' => 20, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 320.00, 'is_active' => true,
                    ],
                    [
                        'name' => 'Hourly 6h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 1550.00, 'package_hours' => 6, 'package_price' => 1550.00,
                        'grace_minutes' => 30, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 300.00, 'is_active' => true,
                    ],
                    [
                        'name' => 'Hourly 12h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 2450.00, 'package_hours' => 12, 'package_price' => 2450.00,
                        'grace_minutes' => 45, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 280.00, 'is_active' => true,
                    ],
                ],
                'seasons' => [
                    ['season_name' => 'Peak (Dec–Jan)', 'adjustment_type' => 'add_percent', 'price_adjustment' => 12.00],
                    ['season_name' => 'Off-season (Apr–May)', 'adjustment_type' => 'discount', 'price_adjustment' => 350.00],
                ],
            ],
            [
                'name' => 'Deluxe',
                'description' => 'Queen bed, more space; extra bed available on request.',
                'is_active' => true,
                'tax_id' => $taxId,
                'breakfast_price' => 200.00,
                'child_breakfast_price' => 120.00,
                'adult_lunch_price' => 400.00,
                'child_lunch_price' => 280.00,
                'adult_dinner_price' => 450.00,
                'child_dinner_price' => 300.00,
                'child_age_limit' => 12,
                'extra_bed_cost' => 1000.00,
                'child_extra_bed_cost' => 700.00,
                'early_check_in_fee' => 600.00,
                'early_check_in_type' => 'flat_fee',
                'early_check_in_buffer_minutes' => 120,
                'late_check_out_fee' => 600.00,
                'late_check_out_type' => 'flat_fee',
                'late_check_out_buffer_minutes' => 120,
                'base_occupancy' => 2,
                'capacity' => 3,
                'extra_bed_capacity' => 1,
                'child_sharing_limit' => 2,
                'bed_config' => 'Queen',
                'amenities' => $amenitiesDeluxe,
                'rooms' => [
                    ['room_number' => '201', 'floor' => '2'],
                    ['room_number' => '202', 'floor' => '2'],
                    ['room_number' => '203', 'floor' => '2'],
                    ['room_number' => '204', 'floor' => '2'],
                    ['room_number' => '205', 'floor' => '2'],
                    ['room_number' => '206', 'floor' => '2'],
                ],
                'rate_plans' => [
                    ['name' => 'Rack — room only', 'billing_unit' => 'day', 'base_price' => 3800.00, 'meal_plan_type' => 'room_only', 'is_active' => true],
                    ['name' => 'CP — breakfast', 'billing_unit' => 'day', 'base_price' => 4150.00, 'meal_plan_type' => 'breakfast', 'is_active' => true],
                    ['name' => 'AP — full board', 'billing_unit' => 'day', 'base_price' => 5200.00, 'meal_plan_type' => 'full_board', 'is_active' => true],
                    [
                        'name' => 'Hourly 3h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 1450.00, 'package_hours' => 3, 'package_price' => 1450.00,
                        'grace_minutes' => 20, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 450.00, 'is_active' => true,
                    ],
                    [
                        'name' => 'Hourly 6h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 2400.00, 'package_hours' => 6, 'package_price' => 2400.00,
                        'grace_minutes' => 30, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 420.00, 'is_active' => true,
                    ],
                    [
                        'name' => 'Hourly 12h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 3800.00, 'package_hours' => 12, 'package_price' => 3800.00,
                        'grace_minutes' => 45, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 400.00, 'is_active' => true,
                    ],
                ],
                'seasons' => [
                    ['season_name' => 'Peak (Dec–Jan)', 'adjustment_type' => 'add_percent', 'price_adjustment' => 15.00],
                    ['season_name' => 'Monsoon offer', 'adjustment_type' => 'add_fixed', 'price_adjustment' => 250.00],
                    ['season_name' => 'Mid-week value', 'adjustment_type' => 'discount', 'price_adjustment' => 400.00],
                ],
            ],
            [
                'name' => 'Family',
                'description' => 'Two queen beds or queen + single; fits up to four guests.',
                'is_active' => true,
                'tax_id' => $taxId,
                'breakfast_price' => 250.00,
                'child_breakfast_price' => 150.00,
                'adult_lunch_price' => 450.00,
                'child_lunch_price' => 300.00,
                'adult_dinner_price' => 500.00,
                'child_dinner_price' => 320.00,
                'child_age_limit' => 12,
                'extra_bed_cost' => 1200.00,
                'child_extra_bed_cost' => 800.00,
                'early_check_in_fee' => 700.00,
                'early_check_in_type' => 'flat_fee',
                'early_check_in_buffer_minutes' => 120,
                'late_check_out_fee' => 700.00,
                'late_check_out_type' => 'flat_fee',
                'late_check_out_buffer_minutes' => 120,
                'base_occupancy' => 4,
                'capacity' => 4,
                'extra_bed_capacity' => 2,
                'child_sharing_limit' => 2,
                'bed_config' => '2 Queen',
                'amenities' => $amenitiesFamily,
                'rooms' => [
                    ['room_number' => '301', 'floor' => '3'],
                    ['room_number' => '302', 'floor' => '3'],
                ],
                'rate_plans' => [
                    ['name' => 'Rack — room only', 'billing_unit' => 'day', 'base_price' => 5200.00, 'meal_plan_type' => 'room_only', 'is_active' => true],
                    ['name' => 'CP — breakfast', 'billing_unit' => 'day', 'base_price' => 5650.00, 'meal_plan_type' => 'breakfast', 'is_active' => true],
                    [
                        'name' => 'Hourly 6h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 3200.00, 'package_hours' => 6, 'package_price' => 3200.00,
                        'grace_minutes' => 30, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 520.00, 'is_active' => true,
                    ],
                    [
                        'name' => 'Hourly 12h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 5100.00, 'package_hours' => 12, 'package_price' => 5100.00,
                        'grace_minutes' => 45, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 500.00, 'is_active' => true,
                    ],
                ],
                'seasons' => [
                    ['season_name' => 'Peak (Dec–Jan)', 'adjustment_type' => 'add_percent', 'price_adjustment' => 10.00],
                    ['season_name' => 'Summer flat', 'adjustment_type' => 'override', 'price_adjustment' => 5800.00],
                ],
            ],
            [
                'name' => 'Suite',
                'description' => 'Separate living area; premium stay with best views.',
                'is_active' => true,
                'tax_id' => $taxId,
                'breakfast_price' => 350.00,
                'child_breakfast_price' => 200.00,
                'adult_lunch_price' => 550.00,
                'child_lunch_price' => 380.00,
                'adult_dinner_price' => 600.00,
                'child_dinner_price' => 400.00,
                'child_age_limit' => 12,
                'extra_bed_cost' => 1500.00,
                'child_extra_bed_cost' => 1000.00,
                'early_check_in_fee' => 1000.00,
                'early_check_in_type' => 'flat_fee',
                'early_check_in_buffer_minutes' => 180,
                'late_check_out_fee' => 1000.00,
                'late_check_out_type' => 'flat_fee',
                'late_check_out_buffer_minutes' => 180,
                'base_occupancy' => 2,
                'capacity' => 4,
                'extra_bed_capacity' => 2,
                'child_sharing_limit' => 2,
                'bed_config' => 'King + living',
                'amenities' => $amenitiesSuite,
                'rooms' => [
                    ['room_number' => '401', 'floor' => '4'],
                    ['room_number' => '402', 'floor' => '4'],
                ],
                'rate_plans' => [
                    ['name' => 'Rack — room only', 'billing_unit' => 'day', 'base_price' => 8500.00, 'meal_plan_type' => 'room_only', 'is_active' => true],
                    ['name' => 'CP — breakfast', 'billing_unit' => 'day', 'base_price' => 9100.00, 'meal_plan_type' => 'breakfast', 'is_active' => true],
                    ['name' => 'MAP — half board', 'billing_unit' => 'day', 'base_price' => 10200.00, 'meal_plan_type' => 'half_board', 'is_active' => true],
                    [
                        'name' => 'Hourly 6h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 5200.00, 'package_hours' => 6, 'package_price' => 5200.00,
                        'grace_minutes' => 30, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 750.00, 'is_active' => true,
                    ],
                    [
                        'name' => 'Hourly 12h', 'billing_unit' => 'hour_package', 'meal_plan_type' => 'room_only',
                        'base_price' => 8200.00, 'package_hours' => 12, 'package_price' => 8200.00,
                        'grace_minutes' => 45, 'overtime_step_minutes' => 60, 'overtime_hour_price' => 700.00, 'is_active' => true,
                    ],
                ],
                'seasons' => [
                    ['season_name' => 'Peak (Dec–Jan)', 'adjustment_type' => 'add_percent', 'price_adjustment' => 18.00],
                    ['season_name' => 'Festive surcharge', 'adjustment_type' => 'add_fixed', 'price_adjustment' => 1200.00],
                ],
            ],
        ];

        $y = (int) now()->year;

        foreach ($types as $def) {
            $roomRows = $def['rooms'];
            $ratePlans = $def['rate_plans'] ?? [];
            $seasonDefs = $def['seasons'] ?? [];
            unset($def['rooms'], $def['rate_plans'], $def['seasons']);

            $roomType = RoomType::updateOrCreate(
                ['name' => $def['name']],
                $def
            );

            foreach ($ratePlans as $plan) {
                $billing = $plan['billing_unit'] ?? 'day';
                $keyName = $plan['name'];
                RatePlan::updateOrCreate(
                    [
                        'room_type_id' => $roomType->id,
                        'name' => $keyName,
                        'billing_unit' => $billing,
                    ],
                    [
                        'base_price' => $plan['base_price'],
                        'meal_plan_type' => $plan['meal_plan_type'] ?? 'room_only',
                        'billing_unit' => $billing,
                        'package_hours' => $plan['package_hours'] ?? null,
                        'package_price' => $plan['package_price'] ?? null,
                        'grace_minutes' => $plan['grace_minutes'] ?? 0,
                        'overtime_step_minutes' => $plan['overtime_step_minutes'] ?? 60,
                        'overtime_hour_price' => $plan['overtime_hour_price'] ?? null,
                        'is_active' => $plan['is_active'] ?? true,
                    ]
                );
            }

            foreach ($seasonDefs as $s) {
                $start = $s['start_date'] ?? null;
                $end = $s['end_date'] ?? null;
                if ($start === null || $end === null) {
                    [$start, $end] = $this->defaultSeasonWindow($s['season_name'], $y);
                }
                RoomTypeSeason::firstOrCreate(
                    [
                        'room_type_id' => $roomType->id,
                        'season_name' => $s['season_name'],
                    ],
                    [
                        'start_date' => $start,
                        'end_date' => $end,
                        'adjustment_type' => $s['adjustment_type'] ?? 'override',
                        'price_adjustment' => (float) $s['price_adjustment'],
                    ]
                );
            }

            foreach ($roomRows as $row) {
                $attrs = $this->buildRoomAttributes($roomType, $row['room_number'], $row['floor']);
                Room::updateOrCreate(
                    ['room_number' => $row['room_number']],
                    array_merge($attrs, [
                        'room_type_id' => $roomType->id,
                        'is_active' => true,
                        'status' => 'available',
                    ])
                );
            }
        }

        $r401 = Room::where('room_number', '401')->first();
        $r402 = Room::where('room_number', '402')->first();
        if ($r401 && $r402 && $r401->connected_room_id === null) {
            $r401->update(['connected_room_id' => $r402->id]);
            $r402->update(['connected_room_id' => $r401->id]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function defaultSeasonWindow(string $seasonName, int $y): array
    {
        return match (true) {
            str_contains($seasonName, 'Peak') => [sprintf('%d-12-01', $y), sprintf('%d-01-15', $y + 1)],
            str_contains($seasonName, 'Off-season') => [sprintf('%d-04-01', $y), sprintf('%d-05-31', $y)],
            str_contains($seasonName, 'Monsoon') => [sprintf('%d-06-01', $y), sprintf('%d-09-30', $y)],
            str_contains($seasonName, 'Mid-week') => [sprintf('%d-07-01', $y), sprintf('%d-08-31', $y)],
            str_contains($seasonName, 'Summer flat') => [sprintf('%d-03-01', $y), sprintf('%d-05-31', $y)],
            str_contains($seasonName, 'Festive') => [sprintf('%d-11-15', $y), sprintf('%d-01-10', $y + 1)],
            default => [sprintf('%d-01-01', $y), sprintf('%d-12-31', $y)],
        };
    }

    /**
     * Deterministic per room number: varied view, smoking policy, bed spec, amenities.
     *
     * @return array<string, mixed>
     */
    private function buildRoomAttributes(RoomType $roomType, string $roomNumber, string $floor): array
    {
        fake()->seed(abs(crc32('passions-room-'.$roomNumber)));

        $views = ['standard', 'garden_view', 'sea_view', 'pool_view'];
        $viewType = fake()->randomElement($views);

        $bedOptions = match ($roomType->name) {
            'Standard' => ['Twin', 'Twin XL', 'Queen'],
            'Deluxe' => ['Queen', 'Queen + sofa', 'King'],
            'Family' => ['2 Queen', 'Queen + Twin', 'King + Twin'],
            'Suite' => ['King + living', 'King', 'King + twin rollaway'],
            default => [$roomType->bed_config ?? 'Queen'],
        };
        $bedConfig = fake()->randomElement($bedOptions);

        $extrasPool = [
            'Safe', 'Work desk', 'Iron & board', 'Hair dryer', 'Blackout curtains',
            'Bathrobe', 'Slippers', 'Coffee station', 'USB bedside ports', 'Rain shower',
        ];
        $baseAmenities = is_array($roomType->amenities) ? $roomType->amenities : [];
        $extraPick = fake()->randomElements(
            $extrasPool,
            fake()->numberBetween(2, min(5, count($extrasPool)))
        );
        $amenities = array_values(array_unique(array_merge($baseAmenities, $extraPick)));

        $internalNotes = fake()->boolean(35)
            ? fake()->randomElement([
                'VIP repeat guest preference: high floor.',
                'Connecting request possible with adjacent.',
                'Late checkout often requested — note in PMS.',
                'Pet allergy deep-clean on file.',
                'Anniversary setup requested once — upsell cake.',
            ])
            : null;

        return [
            'floor' => $floor,
            'bed_config' => $bedConfig,
            'view_type' => $viewType,
            'is_smoking_allowed' => fake()->boolean(28),
            'amenities' => $amenities,
            'internal_notes' => $internalNotes,
            'notes' => fake()->boolean(20) ? fake()->sentence(8) : null,
        ];
    }
}
