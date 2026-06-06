<?php

namespace Database\Seeders;

use App\Models\HousekeepingChecklistItem;
use Illuminate\Database\Seeder;

class HousekeepingChecklistSeeder extends Seeder
{
    public function run(): void
    {
        $daily = [
            ['task_key' => 'change_sheets', 'task_name' => 'Change sheets', 'display_order' => 1, 'required' => true, 'estimated_minutes' => 8],
            ['task_key' => 'clean_bathroom', 'task_name' => 'Clean bathroom', 'display_order' => 2, 'required' => true, 'estimated_minutes' => 10],
            ['task_key' => 'vacuum_floor', 'task_name' => 'Vacuum / mop floor', 'display_order' => 3, 'required' => true, 'estimated_minutes' => 6],
            ['task_key' => 'dust_surfaces', 'task_name' => 'Dust surfaces', 'display_order' => 4, 'required' => true, 'estimated_minutes' => 5],
            ['task_key' => 'trash_removed', 'task_name' => 'Remove trash', 'display_order' => 5, 'required' => true, 'estimated_minutes' => 2],
        ];

        foreach ($daily as $row) {
            HousekeepingChecklistItem::updateOrCreate(
                [
                    'category' => HousekeepingChecklistItem::CATEGORY_DAILY_ROOM_CLEANING,
                    'task_key' => $row['task_key'],
                ],
                [
                    'task_name' => $row['task_name'],
                    'display_order' => $row['display_order'],
                    'required' => $row['required'],
                    'is_active' => true,
                    'estimated_minutes' => $row['estimated_minutes'],
                ],
            );
        }

        $checkout = [
            ['task_key' => 'walls_paint_ok', 'task_name' => 'Walls / paint OK', 'section' => 'room_condition', 'display_order' => 1],
            ['task_key' => 'flooring_ok', 'task_name' => 'Flooring OK', 'section' => 'room_condition', 'display_order' => 2],
            ['task_key' => 'furniture_intact', 'task_name' => 'Furniture intact', 'section' => 'room_condition', 'display_order' => 3],
            ['task_key' => 'windows_doors_ok', 'task_name' => 'Windows & doors OK', 'section' => 'room_condition', 'display_order' => 4],
            ['task_key' => 'odor_none', 'task_name' => 'No odor', 'section' => 'room_condition', 'display_order' => 5],
            ['task_key' => 'check_mattress', 'task_name' => 'Check mattress', 'section' => 'room_condition', 'display_order' => 6],
            ['task_key' => 'check_remote', 'task_name' => 'Check remote', 'section' => 'room_condition', 'display_order' => 7],
            ['task_key' => 'bedding_complete', 'task_name' => 'Bedding complete', 'section' => 'linen_check', 'display_order' => 8],
            ['task_key' => 'towels_stocked', 'task_name' => 'Towels stocked', 'section' => 'linen_check', 'display_order' => 9],
            ['task_key' => 'robes_slippers', 'task_name' => 'Robes / slippers OK', 'section' => 'linen_check', 'display_order' => 10],
            ['task_key' => 'stains_reported', 'task_name' => 'Stains to report', 'section' => 'linen_check', 'display_order' => 11, 'required' => false],
            ['task_key' => 'check_damages', 'task_name' => 'Check damages', 'section' => 'room_condition', 'display_order' => 12],
            ['task_key' => 'check_minibar', 'task_name' => 'Check minibar', 'section' => 'general', 'display_order' => 13],
            ['task_key' => 'verify_room_stock', 'task_name' => 'Verify room stock', 'section' => 'general', 'display_order' => 14],
            ['task_key' => 'hvac_issue', 'task_name' => 'HVAC', 'section' => 'maintenance_flags', 'display_order' => 15, 'required' => false],
            ['task_key' => 'plumbing_issue', 'task_name' => 'Plumbing', 'section' => 'maintenance_flags', 'display_order' => 16, 'required' => false],
            ['task_key' => 'electrical_issue', 'task_name' => 'Electrical', 'section' => 'maintenance_flags', 'display_order' => 17, 'required' => false],
            ['task_key' => 'tv_wifi_issue', 'task_name' => 'TV / Wi‑Fi', 'section' => 'maintenance_flags', 'display_order' => 18, 'required' => false],
            ['task_key' => 'security_safe_issue', 'task_name' => 'Safe / lock', 'section' => 'maintenance_flags', 'display_order' => 19, 'required' => false],
        ];

        foreach ($checkout as $row) {
            HousekeepingChecklistItem::updateOrCreate(
                [
                    'category' => HousekeepingChecklistItem::CATEGORY_CHECKOUT_INSPECTION,
                    'task_key' => $row['task_key'],
                ],
                [
                    'task_name' => $row['task_name'],
                    'section' => $row['section'] ?? null,
                    'display_order' => $row['display_order'],
                    'required' => $row['required'] ?? true,
                    'is_active' => true,
                ],
            );
        }

        $turnover = [
            ['task_key' => 'change_sheets', 'task_name' => 'Change sheets', 'display_order' => 1],
            ['task_key' => 'clean_bathroom', 'task_name' => 'Clean bathroom', 'display_order' => 2],
            ['task_key' => 'vacuum_floor', 'task_name' => 'Vacuum / mop floor', 'display_order' => 3],
            ['task_key' => 'dust_surfaces', 'task_name' => 'Dust surfaces', 'display_order' => 4],
            ['task_key' => 'trash_removed', 'task_name' => 'Remove trash', 'display_order' => 5],
        ];

        foreach ($turnover as $row) {
            HousekeepingChecklistItem::updateOrCreate(
                [
                    'category' => HousekeepingChecklistItem::CATEGORY_TURNOVER_CLEANING,
                    'task_key' => $row['task_key'],
                ],
                [
                    'task_name' => $row['task_name'],
                    'display_order' => $row['display_order'],
                    'required' => true,
                    'is_active' => true,
                ],
            );
        }
    }
}
