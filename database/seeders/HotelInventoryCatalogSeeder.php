<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HotelInventoryCatalogSeeder extends Seeder
{
    private function uomId(string $shortName, string $fallbackShort = 'PCS'): int
    {
        $uom = InventoryUom::where('short_name', '=', strtoupper($shortName), 'and')->first();
        if ($uom) return (int) $uom->id;

        $fallback = InventoryUom::where('short_name', '=', strtoupper($fallbackShort), 'and')->first();
        if ($fallback) return (int) $fallback->id;

        // last-resort: create PCS if missing (should be created by InventoryUomSeeder)
        $pcs = InventoryUom::firstOrCreate(['short_name' => 'PCS'], ['name' => 'Piece']);
        return (int) $pcs->id;
    }

    private function category(string $name, ?string $description = null, ?InventoryCategory $parent = null): InventoryCategory
    {
        return InventoryCategory::firstOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'parent_id' => $parent?->id,
            ]
        );
    }

    private function sku(string $prefix, string $name): string
    {
        // Str::slug signature varies across helper stubs; pass full argument list for analyzers.
        $slug = Str::upper(Str::slug($name, '_', null, []));
        $slug = substr($slug, 0, 28);
        return $prefix . '_' . $slug;
    }

    private function item(array $data): void
    {
        InventoryItem::firstOrCreate(
            ['sku' => $data['sku']],
            $data
        );
    }

    public function run(): void
    {
        // ── Categories (main) ────────────────────────────────────────────────
        $guestAmenities = $this->category(
            'Guest Amenities',
            'Use-and-throw items replenished by housekeeping each cleaning.'
        );
        $minibar = $this->category(
            'Minibar & Snacks',
            'Chargeable items often linked to billing / POS consumption.'
        );
        $linens = $this->category(
            'Room Linens',
            'Reusable items that circulate between rooms, laundry, and stores.'
        );
        $fixedAssets = $this->category(
            'Fixed Assets',
            'Long-term in-room assets tracked for maintenance/replacement.'
        );

        // ── Categories (sub) ────────────────────────────────────────────────
        $toiletries = $this->category('Toiletries', null, $guestAmenities);
        $beverageStation = $this->category('Beverage Station', null, $guestAmenities);
        $stationery = $this->category('Stationery', null, $guestAmenities);

        $minibarFood = $this->category('Minibar Food', null, $minibar);
        $minibarDrinks = $this->category('Minibar Drinks', null, $minibar);

        $bedding = $this->category('Bedding', null, $linens);
        $bath = $this->category('Bath', null, $linens);
        $linensOther = $this->category('Other Linens', null, $linens);

        $electronics = $this->category('Electronics', null, $fixedAssets);
        $hardGoods = $this->category('Furniture & Hard Goods', null, $fixedAssets);

        // ── UOMs ────────────────────────────────────────────────────────────
        $PCS = $this->uomId('PCS');
        $BTL = $this->uomId('BTL', 'PCS');
        $SACH = $this->uomId('SACH', 'PCS');
        $PACK = $this->uomId('PACK', 'PCS');
        $SET = $this->uomId('SET', 'PCS');
        $UNIT = $this->uomId('UNIT', 'PCS');

        // Helper defaults
        $defaults = static function (int $categoryId, int $purchaseUomId, int $issueUomId) {
            return [
                'category_id' => $categoryId,
                'purchase_uom_id' => $purchaseUomId,
                'issue_uom_id' => $issueUomId,
                'conversion_factor' => 1.0,
                'cost_price' => 0,
                'reorder_level' => 0,
                'current_stock' => 0,
                'is_direct_sale' => false,
                'is_prepared_item' => false,
                'description' => null,
                'vendor_id' => null,
                'tax_id' => null,
            ];
        };

        // ── Items: Guest Amenities (Consumables) ────────────────────────────
        foreach (
            [
                ['Bar soap', $toiletries, $PCS],
                ['Liquid soap', $toiletries, $PCS],
                ['Shampoo', $toiletries, $PCS],
                ['Conditioner', $toiletries, $PCS],
                ['Dental kit (toothbrush + toothpaste)', $toiletries, $SET],
                ['Shaving kit', $toiletries, $SET],
            ] as [$name, $cat, $uom]
        ) {
            $base = $defaults($cat->id, $uom, $uom);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('GA', $name),
                'reorder_level' => 25,
            ]));
        }

        foreach (
            [
                ['Coffee sachet', $beverageStation, $SACH],
                ['Tea bag', $beverageStation, $SACH],
                ['Sugar packet', $beverageStation, $SACH],
                ['Sweetener packet', $beverageStation, $SACH],
                ['Creamer sachet', $beverageStation, $SACH],
                ['Bottled water', $beverageStation, $BTL],
            ] as [$name, $cat, $uom]
        ) {
            $base = $defaults($cat->id, $uom, $uom);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('GA', $name),
                'reorder_level' => $name === 'Bottled water' ? 50 : 100,
            ]));
        }

        foreach (
            [
                ['Notepad', $stationery, $PCS],
                ['Pen', $stationery, $PCS],
                ['Do Not Disturb door hanger', $stationery, $PCS],
            ] as [$name, $cat, $uom]
        ) {
            $base = $defaults($cat->id, $uom, $uom);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('GA', $name),
                'reorder_level' => 25,
            ]));
        }

        // ── Items: Minibar & Snacks (Revenue Items) ─────────────────────────
        foreach (
            [
                ['Bread', $minibarFood, $PACK],
                ['Crackers', $minibarFood, $PACK],
                ['Chocolate', $minibarFood, $PCS],
                ['Nuts', $minibarFood, $PACK],
                ['Cup noodles', $minibarFood, $PCS],
            ] as [$name, $cat, $uom]
        ) {
            $base = $defaults($cat->id, $uom, $uom);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('MB', $name),
                'is_direct_sale' => true,
                'reorder_level' => 10,
            ]));
        }

        foreach (
            [
                ['Soda', $minibarDrinks, $BTL],
                ['Juice', $minibarDrinks, $BTL],
                ['Alcohol miniature', $minibarDrinks, $BTL],
                ['Sparkling water', $minibarDrinks, $BTL],
            ] as [$name, $cat, $uom]
        ) {
            $base = $defaults($cat->id, $uom, $uom);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('MB', $name),
                'is_direct_sale' => true,
                'reorder_level' => 10,
            ]));
        }

        // ── Items: Room Linens (Circulating Inventory) ──────────────────────
        foreach (
            [
                ['Bed sheet', $bedding],
                ['Pillowcase', $bedding],
                ['Duvet', $bedding],
                ['Mattress protector', $bedding],
            ] as [$name, $cat]
        ) {
            $base = $defaults($cat->id, $PCS, $PCS);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('LN', $name),
                'reorder_level' => 5,
            ]));
        }

        foreach (
            [
                ['Bath towel', $bath],
                ['Hand towel', $bath],
                ['Face towel', $bath],
                ['Bath mat', $bath],
            ] as [$name, $cat]
        ) {
            $base = $defaults($cat->id, $PCS, $PCS);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('LN', $name),
                'reorder_level' => 5,
            ]));
        }

        foreach (
            [
                ['Bathrobe', $linensOther],
                ['Reusable laundry bag', $linensOther],
            ] as [$name, $cat]
        ) {
            $base = $defaults($cat->id, $PCS, $PCS);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('LN', $name),
                'reorder_level' => 2,
            ]));
        }

        // ── Items: Fixed Assets (Non-Consumables) ───────────────────────────
        foreach (
            [
                ['Coffee maker', $electronics],
                ['Electric kettle', $electronics],
                ['Hair dryer', $electronics],
                ['Television', $electronics],
                ['Mini-fridge', $electronics],
            ] as [$name, $cat]
        ) {
            $base = $defaults($cat->id, $UNIT, $UNIT);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('FA', $name),
                'reorder_level' => 0,
            ]));
        }

        foreach (
            [
                ['Clothes hanger', $hardGoods],
                ['Safe-deposit box', $hardGoods],
                ['Iron', $hardGoods],
                ['Ironing board', $hardGoods],
                ['Luggage rack', $hardGoods],
            ] as [$name, $cat]
        ) {
            $base = $defaults($cat->id, $PCS, $PCS);
            $this->item(array_merge($base, [
                'name' => $name,
                'sku' => $this->sku('FA', $name),
                'reorder_level' => 0,
            ]));
        }
    }
}
