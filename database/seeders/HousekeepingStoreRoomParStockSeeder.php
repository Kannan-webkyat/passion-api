<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Optional test helper:
 * Moves a small PAR buffer from Main Store to Housekeeping Store for HK room stock requests.
 *
 * Usage:
 * php artisan db:seed --class=HousekeepingStoreRoomParStockSeeder
 */
class HousekeepingStoreRoomParStockSeeder extends Seeder
{
    public function run(): void
    {
        $mainStore = InventoryLocation::query()
            ->where('type', '=', 'main_store', 'and')
            ->first();
        $hkStore = InventoryLocation::query()
            ->where('type', '=', 'housekeeping_store', 'and')
            ->where('is_active', '=', true, 'and')
            ->first();
        if (! $hkStore) {
            $hkStore = InventoryLocation::query()
                ->where('name', '=', 'Housekeeping Store', 'and')
                ->where('is_active', '=', true, 'and')
                ->first();
        }

        if (! $mainStore || ! $hkStore) {
            $this->command?->warn('Main Store or Housekeeping Store missing.');

            return;
        }

        $items = InventoryItem::query()
            ->where(function ($q) {
                $q->where('sku', 'like', 'GA\_%', 'and')
                    ->orWhere('sku', 'like', 'MB\_%')
                    ->orWhere('sku', 'like', 'FA\_%');
            })
            ->get(['id', 'sku', 'name', 'cost_price', 'conversion_factor']);

        $movedLines = 0;
        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                $sku = (string) $item->sku;
                $targetQty = str_starts_with($sku, 'FA_')
                    ? 4.0
                    : (str_starts_with($sku, 'MB_') ? 10.0 : 30.0);

                $hkQty = (float) (DB::table('inventory_item_locations')
                    ->where('inventory_item_id', '=', $item->id, 'and')
                    ->where('inventory_location_id', '=', $hkStore->id, 'and')
                    ->value('quantity') ?? 0);
                if ($hkQty >= $targetQty) {
                    continue;
                }

                $mainQty = (float) (DB::table('inventory_item_locations')
                    ->where('inventory_item_id', '=', $item->id, 'and')
                    ->where('inventory_location_id', '=', $mainStore->id, 'and')
                    ->lockForUpdate()
                    ->value('quantity') ?? 0);
                if ($mainQty <= 0) {
                    continue;
                }

                $moveQty = min($targetQty - $hkQty, $mainQty);
                if ($moveQty <= 0) {
                    continue;
                }

                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $item->id, 'inventory_location_id' => $hkStore->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );

                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', '=', $item->id, 'and')
                    ->where('inventory_location_id', '=', $mainStore->id, 'and')
                    ->decrement('quantity', $moveQty);

                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', '=', $item->id, 'and')
                    ->where('inventory_location_id', '=', $hkStore->id, 'and')
                    ->increment('quantity', $moveQty);

                $unitCost = (float) ($item->cost_price ?? 0) / max(1, (float) ($item->conversion_factor ?: 1));
                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_location_id' => $mainStore->id,
                    'type' => 'out',
                    'quantity' => $moveQty,
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($unitCost * $moveQty, 2),
                    'reason' => 'Transfer',
                    'notes' => 'Seed transfer to Housekeeping Store',
                    'user_id' => null,
                    'reference_type' => 'seed',
                    'reference_id' => 'hk-room-stock',
                ]);

                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_location_id' => $hkStore->id,
                    'type' => 'in',
                    'quantity' => $moveQty,
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($unitCost * $moveQty, 2),
                    'reason' => 'Transfer',
                    'notes' => 'Seed transfer from Main Store',
                    'user_id' => null,
                    'reference_type' => 'seed',
                    'reference_id' => 'hk-room-stock',
                ]);

                InventoryItem::syncStoredCurrentStockFromLocations($item->id);
                $movedLines++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->command?->info("Housekeeping store PAR buffer ensured ({$movedLines} item lines moved).");
    }
}
