<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Recipe;
use App\Models\RestaurantMaster;
use App\Services\Accounting\PosCogsBusinessDateResolver;
use Illuminate\Support\Facades\DB;

/**
 * Food cost for POS reporting — prefers actual inventory transactions, falls back to recipe estimates.
 */
final class PosFoodCostService
{
    public function __construct(
        private readonly RecipeCostCalculator $recipeCostCalculator,
        private readonly PosCogsBusinessDateResolver $businessDateResolver,
    ) {}

    public function forOutlets(array $outletIds, string $from, string $to): float
    {
        return (float) ($this->forOutletsWithMeta($outletIds, $from, $to)['amount'] ?? 0.0);
    }

    /**
     * @return array{amount: float, source: 'actual_transactions'|'recipe_estimate'}
     */
    public function forOutletsWithMeta(array $outletIds, string $from, string $to): array
    {
        if ($outletIds === []) {
            return ['amount' => 0.0, 'source' => 'recipe_estimate'];
        }

        $actual = $this->actualFromTransactions($outletIds, $from, $to);
        if ($actual > 0) {
            return ['amount' => round($actual, 2), 'source' => 'actual_transactions'];
        }

        return [
            'amount' => $this->estimatedFromRecipes($outletIds, $from, $to),
            'source' => 'recipe_estimate',
        ];
    }

    public function actualFromTransactions(array $outletIds, string $from, string $to): float
    {
        $locationIds = $this->resolveOutletLocationIds($outletIds);
        if ($locationIds === []) {
            return 0.0;
        }

        $outs = (float) $this->businessDateResolver->applyBusinessDateScope(
            InventoryTransaction::query()
                ->where('type', 'out')
                ->where('reason', 'POS Order')
                ->whereIn('inventory_location_id', $locationIds),
            $outletIds,
            $from,
            $to
        )->sum('total_cost');

        $reversals = (float) $this->businessDateResolver->applyBusinessDateScope(
            InventoryTransaction::query()
                ->where('type', 'in')
                ->where('reason', 'Inventory Reversal')
                ->whereIn('inventory_location_id', $locationIds),
            $outletIds,
            $from,
            $to
        )->sum('total_cost');

        return max(0, $outs - $reversals);
    }

    public function estimatedFromRecipes(array $outletIds, string $from, string $to): float
    {
        if ($outletIds === []) {
            return 0.0;
        }

        $costByMenuItemId = Recipe::query()
            ->where('is_active', true)
            ->whereNotNull('menu_item_id')
            ->get()
            ->mapWithKeys(fn (Recipe $recipe) => [
                (int) $recipe->menu_item_id => $this->recipeCostCalculator->costPerPortion($recipe),
            ]);

        if ($costByMenuItemId->isEmpty()) {
            return 0.0;
        }

        $soldRows = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'poi.order_id', '=', 'po.id')
            ->whereIn('po.status', ['paid', 'refunded'])
            ->whereIn('po.restaurant_id', $outletIds)
            ->whereDate('po.business_date', '>=', $from)
            ->whereDate('po.business_date', '<=', $to)
            ->where('poi.status', 'active')
            ->whereNotNull('poi.menu_item_id')
            ->groupBy('poi.menu_item_id')
            ->select('poi.menu_item_id', DB::raw('SUM(poi.quantity) as qty'))
            ->get();

        $total = 0.0;
        foreach ($soldRows as $row) {
            $menuItemId = (int) $row->menu_item_id;
            $qty = (float) $row->qty;
            $total += $qty * (float) ($costByMenuItemId[$menuItemId] ?? 0.0);
        }

        return round($total, 2);
    }

    /** @return array<int> */
    private function resolveOutletLocationIds(array $outletIds): array
    {
        return RestaurantMaster::query()
            ->whereIn('id', $outletIds)
            ->get(['kitchen_location_id', 'bar_location_id'])
            ->flatMap(fn (RestaurantMaster $r) => [
                $r->kitchen_location_id ? (int) $r->kitchen_location_id : null,
                $r->bar_location_id ? (int) $r->bar_location_id : null,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
