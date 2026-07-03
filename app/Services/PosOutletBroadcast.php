<?php

namespace App\Services;

use App\Events\PosRestaurantUpdated;

/** Notify POS / kitchen UIs that outlet menu or orders may have changed. */
final class PosOutletBroadcast
{
    public static function forLocation(int $locationId, ?int $orderId = null): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $outletIds = app(BatchProductionPoolService::class)->outletIdsForLocation($locationId);
        foreach ($outletIds as $restaurantId) {
            event(new PosRestaurantUpdated((int) $restaurantId, $orderId));
        }
    }

    public static function forRestaurant(int $restaurantId, ?int $orderId = null): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        event(new PosRestaurantUpdated($restaurantId, $orderId));
    }
}
