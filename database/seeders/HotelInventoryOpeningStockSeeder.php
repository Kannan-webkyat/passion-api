<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HotelInventoryOpeningStockSeeder extends Seeder
{
    public function run(): void
    {
        /** @var InventoryLocation|null $mainStore */
        $mainStore = InventoryLocation::where('type', '=', 'main_store', 'and')->first();
        if (! $mainStore) {
            return;
        }

        // Seed only hotel-default catalog SKUs created by HotelInventoryCatalogSeeder.
        // (Avoids touching existing kitchen/bar items and any client-created SKUs.)
        $items = InventoryItem::where('sku', 'like', 'GA\_%', 'and')
            ->orWhere('sku', 'like', 'MB\_%')
            ->orWhere('sku', 'like', 'LN\_%')
            ->orWhere('sku', 'like', 'FA\_%')
            ->get();

        foreach ($items as $item) {
            $sku = (string) $item->sku;

            // Do not double-seed: if main store already has a row for this item, skip.
            $existing = DB::table('inventory_item_locations')
                ->where('inventory_item_id', '=', $item->id, 'and')
                ->where('inventory_location_id', '=', $mainStore->id, 'and')
                ->first();
            if ($existing) {
                continue;
            }

            $qty = 0;
            if (str_starts_with($sku, 'GA_')) {
                $qty = 200; // consumables: replenish frequently
            } elseif (str_starts_with($sku, 'MB_')) {
                $qty = 50; // minibar/snacks: moderate
            } elseif (str_starts_with($sku, 'LN_')) {
                $qty = 20; // linens: circulating stock
            } elseif (str_starts_with($sku, 'FA_')) {
                $qty = 2; // fixed assets: minimal spare units
            }

            DB::table('inventory_item_locations')->updateOrInsert(
                ['inventory_item_id' => $item->id, 'inventory_location_id' => $mainStore->id],
                [
                    'quantity' => $qty,
                    'reorder_level' => $item->reorder_level ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            if ($qty > 0) {
                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_location_id' => $mainStore->id,
                    'type' => 'in',
                    'quantity' => (int) $qty,
                    'unit_cost' => 0,
                    'total_cost' => 0,
                    'reason' => 'Opening Stock (seed)',
                    'notes' => 'Auto-seeded opening quantity into Main Store.',
                    'user_id' => null,
                ]);
            }

            InventoryItem::syncStoredCurrentStockFromLocations($item->id);
        }
    }
}
