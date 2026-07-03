<?php

namespace App\Services;

use App\Models\RestaurantMaster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch menu-item availability from production logs minus committed POS sales.
 */
final class BatchProductionPoolService
{
    /**
     * @param  array<int>|null  $menuItemIds
     * @return Collection<int|string, float> menu_item_id => produced qty
     */
    public function producedByMenuItem(?string $businessDate = null, ?array $menuItemIds = null): Collection
    {
        $query = DB::table('recipes')
            ->where('recipes.is_active', true)
            ->where('recipes.requires_production', true);

        if ($menuItemIds !== null && $menuItemIds !== []) {
            $query->whereIn('recipes.menu_item_id', $menuItemIds);
        }

        if ($businessDate) {
            $query->leftJoin('production_logs', function ($join) use ($businessDate) {
                $join->on('recipes.id', '=', 'production_logs.recipe_id')
                    ->where(function ($q) use ($businessDate) {
                        $q->whereDate('production_logs.business_date', $businessDate)
                            ->orWhere(function ($legacy) use ($businessDate) {
                                $legacy->whereNull('production_logs.business_date')
                                    ->whereDate('production_logs.production_date', $businessDate);
                            });
                    });
            });
        } else {
            $query->leftJoin('production_logs', 'recipes.id', '=', 'production_logs.recipe_id');
        }

        $result = $query
            ->groupBy('recipes.menu_item_id')
            ->selectRaw('recipes.menu_item_id, COALESCE(SUM(production_logs.quantity_produced), 0) as total')
            ->pluck('total', 'menu_item_id')
            ->map(fn ($v) => (float) $v);

        if ($menuItemIds !== null) {
            $merged = collect();
            foreach ($menuItemIds as $id) {
                $merged->put($id, (float) $result->get($id, 0));
            }

            return $merged;
        }

        return $result;
    }

    /**
     * @param  array<int>  $menuItemIds
     * @return Collection<int|string, float>
     */
    public function committedSalesByMenuItem(
        array $menuItemIds,
        ?string $businessDate = null,
        ?int $excludeOrderId = null,
        bool $onlyUndeducted = false,
        ?int $restaurantId = null,
    ): Collection {
        if ($menuItemIds === []) {
            return collect();
        }

        $applyBusinessDate = function ($q) use ($businessDate, $restaurantId) {
            if (! $businessDate) {
                return;
            }
            $q->where(function ($w) use ($businessDate, $restaurantId) {
                $w->whereDate('pos_orders.business_date', $businessDate)
                    ->orWhere(function ($open) use ($restaurantId) {
                        $open->whereNull('pos_orders.business_date')
                            ->whereIn('pos_orders.status', ['open', 'billed']);
                        if ($restaurantId) {
                            $open->where('pos_orders.restaurant_id', $restaurantId);
                        }
                    });
            });
        };

        $direct = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->whereNotIn('pos_orders.status', ['void', 'refunded'])
            ->where('pos_order_items.status', 'active')
            ->whereIn('pos_order_items.menu_item_id', $menuItemIds)
            ->when($excludeOrderId, fn ($q) => $q->where('pos_orders.id', '!=', $excludeOrderId))
            ->when($onlyUndeducted, fn ($q) => $q->where('pos_order_items.inventory_deducted', false))
            ->when($businessDate, $applyBusinessDate)
            ->groupBy('pos_order_items.menu_item_id')
            ->selectRaw('pos_order_items.menu_item_id, SUM(pos_order_items.quantity) as total')
            ->pluck('total', 'menu_item_id')
            ->map(fn ($v) => (float) $v);

        $combo = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->join('combo_items', 'combo_items.combo_id', '=', 'pos_order_items.combo_id')
            ->whereNotIn('pos_orders.status', ['void', 'refunded'])
            ->where('pos_order_items.status', 'active')
            ->whereIn('combo_items.menu_item_id', $menuItemIds)
            ->when($excludeOrderId, fn ($q) => $q->where('pos_orders.id', '!=', $excludeOrderId))
            ->when($onlyUndeducted, fn ($q) => $q->where('pos_order_items.inventory_deducted', false))
            ->when($businessDate, $applyBusinessDate)
            ->groupBy('combo_items.menu_item_id')
            ->selectRaw('combo_items.menu_item_id, SUM(pos_order_items.quantity) as total')
            ->pluck('total', 'menu_item_id')
            ->map(fn ($v) => (float) $v);

        $merged = collect();
        foreach ($menuItemIds as $id) {
            $merged->put($id, ($direct->get($id, 0) + $combo->get($id, 0)));
        }

        return $merged;
    }

    public function availablePortions(
        int $menuItemId,
        ?string $businessDate,
        ?int $excludeOrderId = null,
        ?int $restaurantId = null,
    ): float {
        $produced = (float) $this->producedByMenuItem($businessDate, [$menuItemId])->get($menuItemId, 0);
        $committed = (float) $this->committedSalesByMenuItem(
            [$menuItemId],
            $businessDate,
            $excludeOrderId,
            false,
            $restaurantId,
        )->get($menuItemId, 0);

        return max(0.0, round($produced - $committed, 3));
    }

    public function resolveBusinessDateForLocation(int $locationId): ?string
    {
        $restaurant = RestaurantMaster::query()
            ->where(function ($q) use ($locationId) {
                $q->where('kitchen_location_id', $locationId)
                    ->orWhere('bar_location_id', $locationId);
            })
            ->where('is_active', true)
            ->first();

        return $restaurant
            ? BusinessDateService::resolve($restaurant)
            : null;
    }

    /** @return array<int> */
    public function outletIdsForLocation(?int $locationId): array
    {
        if (! $locationId) {
            return RestaurantMaster::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return RestaurantMaster::query()
            ->where(function ($q) use ($locationId) {
                $q->where('kitchen_location_id', $locationId)
                    ->orWhere('bar_location_id', $locationId);
            })
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
