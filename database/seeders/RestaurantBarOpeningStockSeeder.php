<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\RestaurantMaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock for bar (BAR-*) and restaurant kitchen (RST-*) catalog items.
 *
 * Requires: LocationSeeder, BarInventoryOrganizedSeeder, RestaurantInventoryCatalogSeeder.
 * Skips location rows that already have quantity > 0 (safe to re-run).
 */
class RestaurantBarOpeningStockSeeder extends Seeder
{
    private const SEED_NOTE = 'Opening Stock (seed)';

    public function run(): void
    {
        $mainStore = InventoryLocation::where('name', 'Main Store')->first();
        $barStore = InventoryLocation::where('name', 'Bar Store')->first();
        $kitchen = InventoryLocation::where('name', 'Kitchen')->first();

        if (! $mainStore) {
            $this->command?->error('Main Store not found. Run LocationSeeder first.');

            return;
        }

        $this->wireBarOutletsToBarStore($barStore);

        $barSeeded = $this->seedBarStock($mainStore, $barStore);
        $kitchenSeeded = $this->seedRestaurantStock($mainStore, $kitchen);

        $this->command?->info('Restaurant & bar opening stock seeded.');
        $this->command?->info("  Bar SKUs (BAR-*): {$barSeeded['items']} items · {$barSeeded['rows']} location rows");
        $this->command?->info("  Restaurant SKUs (RST-*): {$kitchenSeeded['items']} items · {$kitchenSeeded['rows']} location rows");
        if ($barSeeded['skipped'] + $kitchenSeeded['skipped'] > 0) {
            $this->command?->warn('  Skipped (already had stock): '.($barSeeded['skipped'] + $kitchenSeeded['skipped']).' location rows');
        }
    }

    private function wireBarOutletsToBarStore(?InventoryLocation $barStore): void
    {
        if (! $barStore) {
            return;
        }

        RestaurantMaster::query()
            ->where('is_active', true)
            ->where(function ($q) use ($barStore) {
                $q->where('kitchen_location_id', $barStore->id)
                    ->orWhere('department_id', $barStore->department_id);
            })
            ->each(function (RestaurantMaster $outlet) use ($barStore) {
                $updates = [];
                if ((int) ($outlet->kitchen_location_id ?? 0) !== (int) $barStore->id) {
                    $updates['kitchen_location_id'] = $barStore->id;
                }
                if (! $outlet->bar_location_id) {
                    $updates['bar_location_id'] = $barStore->id;
                }
                if ($updates !== []) {
                    $outlet->update($updates);
                }
            });
    }

    /** @return array{items: int, rows: int, skipped: int} */
    private function seedBarStock(?InventoryLocation $mainStore, ?InventoryLocation $barStore): array
    {
        $items = InventoryItem::query()
            ->where('sku', 'like', 'BAR-%')
            ->with('issueUom')
            ->get();

        $stats = ['items' => 0, 'rows' => 0, 'skipped' => 0];

        foreach ($items as $item) {
            $qty = $this->barOpeningQuantity($item);
            if ($qty <= 0) {
                continue;
            }

            $seededAny = false;
            foreach (array_filter([$mainStore, $barStore]) as $location) {
                if ($this->seedLocationRow($item, $location, $qty)) {
                    $stats['rows']++;
                    $seededAny = true;
                } else {
                    $stats['skipped']++;
                }
            }

            if ($seededAny) {
                $stats['items']++;
                InventoryItem::syncStoredCurrentStockFromLocations($item->id);
            }
        }

        return $stats;
    }

    /** @return array{items: int, rows: int, skipped: int} */
    private function seedRestaurantStock(?InventoryLocation $mainStore, ?InventoryLocation $kitchen): array
    {
        $items = InventoryItem::query()
            ->where('sku', 'like', 'RST-%')
            ->with('issueUom')
            ->get();

        $stats = ['items' => 0, 'rows' => 0, 'skipped' => 0];

        foreach ($items as $item) {
            $mainQty = $this->restaurantMainStoreQuantity($item);
            $kitchenQty = $this->restaurantKitchenQuantity($item);

            $seededAny = false;

            if ($mainStore && $mainQty > 0) {
                if ($this->seedLocationRow($item, $mainStore, $mainQty)) {
                    $stats['rows']++;
                    $seededAny = true;
                } else {
                    $stats['skipped']++;
                }
            }

            if ($kitchen && $kitchenQty > 0) {
                if ($this->seedLocationRow($item, $kitchen, $kitchenQty)) {
                    $stats['rows']++;
                    $seededAny = true;
                } else {
                    $stats['skipped']++;
                }
            }

            if ($seededAny) {
                $stats['items']++;
                InventoryItem::syncStoredCurrentStockFromLocations($item->id);
            }
        }

        return $stats;
    }

    private function barOpeningQuantity(InventoryItem $item): float
    {
        if ((float) ($item->cost_price ?? 0) <= 0) {
            return 0;
        }

        $issue = strtoupper($item->issueUom?->short_name ?? '');

        if (in_array($issue, ['BTL', 'PCS'], true)) {
            return 24;
        }

        $bottleMl = max(1, (float) ($item->conversion_factor ?? 750));

        return 12 * $bottleMl;
    }

    private function restaurantMainStoreQuantity(InventoryItem $item): float
    {
        $reorder = max(1, (float) ($item->reorder_level ?? 1));
        $issue = strtoupper($item->issueUom?->short_name ?? '');

        return match (true) {
            in_array($issue, ['GM', 'G', 'GRAM', 'GRAMS'], true) => max($reorder * 20, 5000),
            in_array($issue, ['KG', 'KILOGRAM'], true) => max($reorder * 5, 10),
            in_array($issue, ['ML', 'MILLILITRE'], true) => max($reorder * 20, 10000),
            in_array($issue, ['LTR', 'L', 'LITRE', 'LITER'], true) => max($reorder * 5, 20),
            in_array($issue, ['PCS', 'BTL'], true) => max($reorder * 10, 48),
            default => max($reorder * 10, 100),
        };
    }

    private function restaurantKitchenQuantity(InventoryItem $item): float
    {
        return round($this->restaurantMainStoreQuantity($item) * 0.4, 3);
    }

    private function seedLocationRow(
        InventoryItem $item,
        InventoryLocation $location,
        float $quantity
    ): bool {
        $existing = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $location->id)
            ->first();

        if ($existing && (float) $existing->quantity > 0) {
            return false;
        }

        DB::table('inventory_item_locations')->updateOrInsert(
            [
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $location->id,
            ],
            [
                'quantity' => $quantity,
                'reorder_level' => $item->reorder_level ?? 0,
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ]
        );

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'type' => 'in',
            'quantity' => $quantity,
            'unit_cost' => (float) ($item->cost_price ?? 0),
            'total_cost' => round($quantity * (float) ($item->cost_price ?? 0), 2),
            'reason' => self::SEED_NOTE,
            'notes' => "Auto-seeded into {$location->name}.",
            'user_id' => null,
        ]);

        return true;
    }
}
