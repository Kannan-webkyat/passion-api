<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryTax;
use App\Models\InventoryUom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping inventory catalog from data/housekeeping_inventory_catalog.php.
 *
 * Hierarchy matches inventory UI: Housekeeping (root) → Sub-Category → Item (keyed by SKU).
 * These are ordinary inventory categories. The housekeeping-category flag is cleared on the
 * categories this seeder owns, because Room Par lists every category and item.
 * Safe to re-run: categories and UOMs are reused by name; existing items only get their
 * catalog fields (name, category, UOMs, conversion) re-synced — stock, cost, reorder level,
 * tax and vendor edits are preserved.
 *
 * Minibar items are flagged is_minibar + is_direct_sale; MinibarMenuSeeder writes the guest
 * price on menu_items.price (no outlet link).
 *
 * Requires: InventoryTaxSeeder, InventoryUomSeeder.
 */
class HousekeepingInventorySeeder extends Seeder
{
    private const MAIN_CATEGORY = 'Housekeeping';

    private const MAIN_DESCRIPTION = 'Housekeeping linen, amenities, cleaning supplies, guest room items and housekeeping equipment.';

    private const MINIBAR_SUB_CATEGORY = 'Minibar';

    /** Unit name => short_name used only when the unit does not exist yet. */
    private const UOMS = [
        'Piece' => 'PCS',
        'Box' => 'BOX',
        'Pack' => 'PACK',
        'Bottle' => 'BTL',
        'Roll' => 'ROLL',
        'Pair' => 'PAIR',
        'Set' => 'SET',
        'Carton' => 'CTN',
        'Case' => 'CASE',
        'Sheet' => 'SHEET',
        'Book' => 'BOOK',
        'Pad' => 'PAD',
        'Can' => 'CAN',
    ];

    /** @var array<string, int> */
    private array $counts = [
        'main_created' => 0,
        'main_reused' => 0,
        'sub_created' => 0,
        'sub_reused' => 0,
        'uom_created' => 0,
        'uom_reused' => 0,
        'item_created' => 0,
        'item_updated' => 0,
        'item_unchanged' => 0,
        'item_skipped' => 0,
    ];

    public function run(): void
    {
        $catalog = require __DIR__.'/data/housekeeping_inventory_catalog.php';

        DB::transaction(function () use ($catalog) {
            $uomIds = [];
            foreach (self::UOMS as $name => $short) {
                $uomIds[$name] = $this->uomId($name, $short);
            }

            $taxId = $this->defaultTaxId();

            $main = $this->category(self::MAIN_CATEGORY, self::MAIN_DESCRIPTION, null, 'main');

            $subIds = [];
            foreach (array_keys($catalog) as $subName) {
                $subIds[$subName] = $this->category($subName, "{$subName} — ".self::MAIN_CATEGORY, $main->id, 'sub')->id;
            }
            $housekeepingCategoryIds = array_map('intval', array_merge([$main->id], array_values($subIds)));
            InventoryCategory::query()
                ->whereIn('id', $housekeepingCategoryIds)
                ->where('is_housekeeping', true)
                ->update(['is_housekeeping' => false]);

            foreach ($catalog as $subName => $rows) {
                foreach ($rows as [$sku, $name, $purchaseUom, $issueUom, $factor]) {
                    $catalogFields = [
                        'name' => $name,
                        'category_id' => $subIds[$subName],
                        'purchase_uom_id' => $uomIds[$purchaseUom],
                        'issue_uom_id' => $uomIds[$issueUom],
                        'conversion_factor' => (float) $factor,
                        'is_direct_sale' => false,
                        'is_alcohol' => false,
                    ];
                    if ($subName === self::MINIBAR_SUB_CATEGORY) {
                        $catalogFields['is_minibar'] = true;
                        $catalogFields['is_direct_sale'] = true;
                    }
                    $this->item($sku, $catalogFields, $taxId, $housekeepingCategoryIds);
                }
            }
        });

        $c = $this->counts;
        $this->command?->info('Housekeeping inventory seeded.');
        $this->command?->info("  Main categories: {$c['main_created']} created, {$c['main_reused']} reused");
        $this->command?->info("  Sub-categories: {$c['sub_created']} created, {$c['sub_reused']} reused");
        $this->command?->info("  UOMs: {$c['uom_created']} created, {$c['uom_reused']} reused");
        $this->command?->info("  Items: {$c['item_created']} created, {$c['item_updated']} updated, {$c['item_unchanged']} unchanged, {$c['item_skipped']} skipped");
    }

    private function category(string $name, string $description, ?int $parentId, string $kind): InventoryCategory
    {
        // inventory_categories.name is globally UNIQUE, so a name can only be reused, never duplicated.
        $category = InventoryCategory::where('name', $name)->first();

        if (! $category) {
            $this->counts["{$kind}_created"]++;

            return InventoryCategory::create([
                'name' => $name,
                'description' => $description,
                'parent_id' => $parentId,
                'is_housekeeping' => false,
            ]);
        }

        $this->counts["{$kind}_reused"]++;
        if ((int) $category->parent_id !== (int) $parentId) {
            $this->command?->warn("  Category \"{$name}\" already exists under another parent (id {$category->parent_id}); reused without moving it.");
        }

        return $category;
    }

    private function uomId(string $name, string $short): int
    {
        // inventory_uoms has UNIQUE(name) and UNIQUE(short_name); match either, case-insensitively.
        $uom = InventoryUom::whereRaw('LOWER(name) = ?', [strtolower($name)])->first()
            ?? InventoryUom::whereRaw('LOWER(short_name) IN (?, ?)', [strtolower($short), strtolower($name)])->first();

        if ($uom) {
            $this->counts['uom_reused']++;

            return (int) $uom->id;
        }

        $this->counts['uom_created']++;

        return (int) InventoryUom::create(['name' => $name, 'short_name' => $short])->id;
    }

    /** Same default the rest of the non-alcohol catalog uses; never creates a tax. */
    private function defaultTaxId(): ?int
    {
        $id = InventoryTax::where('name', 'GST 5% (Local)')->value('id')
            ?? InventoryTax::where('type', 'local')->where('rate', 5)->value('id');

        if (! $id) {
            $this->command?->warn('  Default GST 5% (Local) tax not found; items seeded without tax.');
        }

        return $id ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $catalogFields
     * @param  list<int>  $housekeepingCategoryIds
     */
    private function item(string $sku, array $catalogFields, ?int $taxId, array $housekeepingCategoryIds): void
    {
        $item = InventoryItem::where('sku', $sku)->first();

        if (! $item) {
            InventoryItem::create(array_merge([
                'sku' => $sku,
                'description' => null,
                'vendor_id' => null,
                'tax_id' => $taxId,
                'cost_price' => 0,
                'inspection_penalty_charge' => 0,
                'reorder_level' => 0,
                'current_stock' => 0,
                'stock_expected' => 0,
                'is_prepared_item' => false,
                'is_cess_applicable' => false,
                'cess_amount' => null,
                'liquor_category' => null,
            ], $catalogFields));
            $this->counts['item_created']++;

            return;
        }

        if ($item->is_alcohol || ($item->category_id && ! in_array((int) $item->category_id, $housekeepingCategoryIds, true))) {
            $this->command?->warn("  SKU {$sku} already belongs to a non-housekeeping item (\"{$item->name}\"); skipped.");
            $this->counts['item_skipped']++;

            return;
        }

        $item->fill($catalogFields);
        if (! $item->isDirty()) {
            $this->counts['item_unchanged']++;

            return;
        }

        $item->save();
        $this->counts['item_updated']++;
    }
}
