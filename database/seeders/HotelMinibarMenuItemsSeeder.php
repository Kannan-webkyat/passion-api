<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuSubCategory;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HotelMinibarMenuItemsSeeder extends Seeder
{
    private function codeFor(InventoryItem $item): string
    {
        $base = strtoupper((string) $item->sku);
        $base = preg_replace('/[^A-Z0-9_\\-]/', '', (string) $base) ?: 'MB_ITEM';
        return 'MB-' . substr($base, 0, 25);
    }

    private function guessPrice(InventoryItem $item): float
    {
        $cost = (float) ($item->cost_price ?? 0);
        if ($cost <= 0) return 100.00;
        $p = $cost * 2.5;
        return max(30.00, round($p, 2));
    }

    public function run(): void
    {
        $hasTaxRate = Schema::hasColumn('menu_items', 'tax_rate');
        $hasDirectSale = Schema::hasColumn('menu_items', 'is_direct_sale');
        $hasRequiresProduction = Schema::hasColumn('menu_items', 'requires_production');
        $hasInventoryItemId = Schema::hasColumn('menu_items', 'inventory_item_id');

        $cat = MenuCategory::firstOrCreate(
            ['name' => 'Minibar'],
            ['is_active' => true]
        );

        $subFood = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $cat->id, 'name' => 'Food'],
            ['description' => 'Minibar food items', 'is_active' => true]
        );

        $subDrinks = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $cat->id, 'name' => 'Drinks'],
            ['description' => 'Minibar drink items', 'is_active' => true]
        );

        $outlet = RestaurantMaster::where('name', '=', 'OTTAAL', 'and')->first()
            ?: RestaurantMaster::query()->orderBy('id', 'asc')->first();

        $items = InventoryItem::query()
            ->where('sku', 'like', 'MB\\_%', 'and')
            ->orWhere(function ($q) {
                $q->where('is_direct_sale', '=', true, 'and')
                    ->where('name', '!=', '', 'and');
            })
            ->orderBy('sku', 'asc')
            ->get();

        foreach ($items as $inv) {
            $code = $this->codeFor($inv);
            $price = $this->guessPrice($inv);

            $name = trim((string) $inv->name);
            $subId = null;
            if (Str::contains(strtolower($name), ['soda', 'juice', 'water', 'alcohol'])) {
                $subId = $subDrinks->id;
            } else {
                $subId = $subFood->id;
            }

            $create = [
                'name' => $name ?: $code,
                'menu_category_id' => $cat->id,
                'menu_sub_category_id' => $subId,
                'price' => $price,
                'fixed_ept' => 0,
                'type' => 'Veg',
                'is_active' => true,
            ];
            if ($hasTaxRate) $create['tax_rate'] = 5;
            if ($hasDirectSale) $create['is_direct_sale'] = true;
            if ($hasRequiresProduction) $create['requires_production'] = false;
            if ($hasInventoryItemId) $create['inventory_item_id'] = $inv->id;

            $menu = MenuItem::firstOrCreate(
                ['item_code' => $code],
                $create
            );

            $update = [
                'price' => $menu->price > 0 ? $menu->price : $price,
            ];
            if ($hasDirectSale) $update['is_direct_sale'] = true;
            if ($hasRequiresProduction) $update['requires_production'] = false;
            if ($hasInventoryItemId) $update['inventory_item_id'] = $inv->id;
            $menu->update($update);

            if ($outlet) {
                RestaurantMenuItem::updateOrCreate(
                    ['menu_item_id' => $menu->id, 'restaurant_master_id' => $outlet->id],
                    ['price' => $menu->price, 'fixed_ept' => 0, 'is_active' => true, 'price_tax_inclusive' => true]
                );
            }
        }
    }
}
