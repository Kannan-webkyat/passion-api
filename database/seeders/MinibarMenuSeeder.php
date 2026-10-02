<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\InventoryTax;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Minibar menu items: one menu item per minibar inventory item (HousekeepingInventorySeeder),
 * linked by inventory_item_id, with item_code = the inventory SKU.
 *
 * No outlet availability is seeded: minibar items are not sold on the POS. Checkout inspection
 * charges consumption at the menu item's base price (GST-inclusive) and posts it to the room.
 *
 * Safe to re-run: existing minibar menu items keep their name, price, tax, availability and any
 * outlet links; only the inventory link, category and direct-sale / no-KOT flags are re-synced.
 *
 * Requires: InventoryTaxSeeder, HousekeepingInventorySeeder.
 */
class MinibarMenuSeeder extends Seeder
{
    private const CATEGORY = 'Minibar';

    private const TAX_NAME = 'GST 18% (Local)';

    /** SKU => guest selling price per issue unit in ₹, GST-inclusive. */
    private const PRICES = [
        'HK-MIN-001' => 35,  // Lay's
        'HK-MIN-002' => 50,  // KitKat
        'HK-MIN-003' => 80,  // Dairy Milk
        'HK-MIN-004' => 50,  // Aquafina 1 Litre
        'HK-MIN-005' => 40,  // Soda 750 ml
        'HK-MIN-006' => 25,  // Aquafina 500 ml
        'HK-MIN-007' => 40,  // Biscuits
        'HK-MIN-008' => 250, // Cashew Nuts
        'HK-MIN-009' => 60,  // 7UP
        'HK-MIN-010' => 40,  // Pepsi
        'HK-MIN-011' => 40,  // Mirinda
        'HK-MIN-012' => 175, // Red Bull
        'HK-MIN-013' => 40,  // Coca-Cola
    ];

    /** @var array<string, int> */
    private array $counts = [
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'skipped' => 0,
    ];

    public function run(): void
    {
        $items = InventoryItem::query()
            ->whereIn('sku', array_keys(self::PRICES))
            ->get(['id', 'sku', 'name', 'is_minibar', 'is_alcohol'])
            ->keyBy('sku');

        $missing = array_diff(array_keys(self::PRICES), $items->keys()->all());
        if ($missing !== []) {
            $this->command?->warn('  Missing minibar SKUs (run HousekeepingInventorySeeder first): '.implode(', ', $missing));
        }
        if ($items->isEmpty()) {
            return;
        }

        $taxId = $this->taxId();

        DB::transaction(function () use ($items, $taxId) {
            $categoryId = (int) MenuCategory::firstOrCreate(
                ['name' => self::CATEGORY],
                ['is_active' => true]
            )->id;

            foreach (self::PRICES as $sku => $price) {
                $inv = $items->get($sku);
                if (! $inv) {
                    continue;
                }
                if (! $inv->is_minibar || $inv->is_alcohol) {
                    $this->command?->warn("  SKU {$sku} (\"{$inv->name}\") is not flagged Minibar (or is alcohol); skipped.");
                    $this->counts['skipped']++;

                    continue;
                }

                $this->menuItem($inv, $categoryId, (float) $price, $taxId);
            }
        });

        $c = $this->counts;
        $this->command?->info('Minibar menu seeded.');
        $this->command?->info("  Menu items: {$c['created']} created, {$c['updated']} updated, {$c['unchanged']} unchanged, {$c['skipped']} skipped");
    }

    private function menuItem(InventoryItem $inv, int $categoryId, float $price, ?int $taxId): void
    {
        $linkFields = [
            'menu_category_id' => $categoryId,
            'inventory_item_id' => (int) $inv->id,
            'is_direct_sale' => true,
            'requires_production' => false,
        ];

        $menu = MenuItem::where('inventory_item_id', $inv->id)->orderBy('id')->first()
            ?? MenuItem::where('item_code', $inv->sku)->first();

        if (! $menu) {
            MenuItem::create(array_merge([
                'item_code' => $inv->sku,
                'name' => $inv->name,
                'menu_sub_category_id' => null,
                'price' => $price,
                'tax_id' => $taxId,
                'fixed_ept' => 0,
                'type' => 'Veg',
                'is_active' => true,
            ], $linkFields));
            $this->counts['created']++;

            return;
        }

        $menu->fill($linkFields);
        if ((float) $menu->price <= 0) {
            $menu->price = $price;
        }
        if (! $menu->tax_id && $taxId) {
            $menu->tax_id = $taxId;
        }

        if (! $menu->isDirty()) {
            $this->counts['unchanged']++;

            return;
        }

        $menu->save();
        $this->counts['updated']++;
    }

    private function taxId(): ?int
    {
        $id = InventoryTax::where('name', self::TAX_NAME)->value('id')
            ?? InventoryTax::where('type', 'local')->where('rate', 18)->value('id');

        if (! $id) {
            $this->command?->warn('  '.self::TAX_NAME.' tax not found; minibar menu items seeded without tax.');
        }

        return $id ? (int) $id : null;
    }
}
