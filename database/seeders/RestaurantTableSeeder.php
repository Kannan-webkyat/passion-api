<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\RestaurantMaster;
use App\Models\RestaurantCombo;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use App\Models\RestaurantTable;
use App\Models\TableCategory;
use App\Models\InventoryLocation;

class RestaurantTableSeeder extends Seeder
{
    private const ADDRESS = 'EDATHUVA - CHAMPAKKULAM ROAD NEAR EDATHUA POLIC STATION';
    private const EMAIL = 'passionshotel@gmail.com';
    private const PHONE = '9496428888';
    private const GSTIN = '32AQOPP9995P2ZG';
    private const FSSAI = '00111111111';

    public function run(): void
    {
        // Remove old outlets (keep only OTTAAL and BAR)
        $keepNames = ['OTTAAL', 'BAR'];
        RestaurantMaster::whereNotIn('name', $keepNames)->each(function ($r) {
            $rmiIds = RestaurantMenuItem::where('restaurant_master_id', $r->id)->pluck('id');
            RestaurantMenuItemVariant::whereIn('restaurant_menu_item_id', $rmiIds)->delete();
            RestaurantMenuItem::where('restaurant_master_id', $r->id)->delete();
            RestaurantCombo::where('restaurant_master_id', $r->id)->delete();
            RestaurantTable::where('restaurant_master_id', $r->id)->delete();
            $r->delete();
        });

        // ── OTTAAL (Restaurant) ─────────────────────────────────────────────
        $ottaal = RestaurantMaster::updateOrCreate(
            ['name' => 'OTTAAL'],
            [
                'floor'        => null,
                'description'  => 'Restaurant',
                'is_active'    => true,
                'address'      => self::ADDRESS,
                'email'        => self::EMAIL,
                'phone'        => self::PHONE,
                'gstin'        => self::GSTIN,
                'fssai'        => self::FSSAI,
            ]
        );

        // ── BAR (Champions) ─────────────────────────────────────────────────
        $bar = RestaurantMaster::updateOrCreate(
            ['name' => 'BAR'],
            [
                'floor'        => null,
                'description'  => 'Champions',
                'is_active'    => true,
                'address'      => self::ADDRESS,
                'email'        => self::EMAIL,
                'phone'        => self::PHONE,
                'gstin'        => self::GSTIN,
                'fssai'        => self::FSSAI,
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

        // ── Tables — OTTAAL ─────────────────────────────────────────────────
        $ottaalTables = [
            ['T-01', $twoSeater,  2],
            ['T-02', $twoSeater,  2],
            ['T-03', $fourSeater, 4],
            ['T-04', $fourSeater, 4],
            ['T-05', $fourSeater, 4],
            ['T-06', $sixSeater,  6],
        ];

        foreach ($ottaalTables as [$num, $cat, $cap]) {
            RestaurantTable::firstOrCreate(
                ['table_number' => $num, 'restaurant_master_id' => $ottaal->id],
                [
                    'category_id' => $cat->id,
                    'capacity'    => $cap,
                    'status'      => 'available',
                    'location'    => null,
                ]
            );
        }

        // ── Tables — BAR ─────────────────────────────────────────────────────
        $barTables = [
            ['B-01', $twoSeater,  2],
            ['B-02', $fourSeater, 4],
        ];

        foreach ($barTables as [$num, $cat, $cap]) {
            RestaurantTable::firstOrCreate(
                ['table_number' => $num, 'restaurant_master_id' => $bar->id],
                [
                    'category_id' => $cat->id,
                    'capacity'    => $cap,
                    'status'      => 'available',
                    'location'    => null,
                ]
            );
        }

        // Map outlets to kitchen and lock BAR to Bar & Lounge department
        $kitchen = InventoryLocation::where('name', 'Kitchen')->first();
        $barStore = InventoryLocation::where('name', 'Bar Store')->first();
        $barDept = Department::where('code', 'BAR')->first();
        if ($kitchen) {
            $ottaal->update(['kitchen_location_id' => $kitchen->id]);
        }
        if ($barStore) {
            $bar->update(['kitchen_location_id' => $barStore->id]);
        } elseif ($kitchen) {
            $bar->update(['kitchen_location_id' => $kitchen->id]);
        }
        if ($barDept) {
            $bar->update(['department_id' => $barDept->id]);
        }

        $this->command->info('Restaurant & Table seeder completed.');
        $this->command->info('  Outlets     : 2 (OTTAAL, BAR)');
        $this->command->info('  Tables      : ' . RestaurantTable::count());
    }
}
