<?php

namespace App\Services;

use App\Models\RestaurantMaster;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class BusinessDateService
{
    /**
     * Resolve business date by outlet cut-off time.
     *
     * Example:
     * - cut-off 04:00, timestamp 2026-03-28 02:30 => business date 2026-03-27
     * - cut-off 04:00, timestamp 2026-03-28 09:30 => business date 2026-03-28
     */
    public static function resolve(?RestaurantMaster $restaurant, ?CarbonInterface $at = null): string
    {
        $at = $at ? Carbon::parse($at) : now();
        $cutoff = $restaurant?->business_day_cutoff_time ?: '04:00:00';
        [$h, $m, $s] = array_map('intval', explode(':', $cutoff));

        $cutoffAt = (clone $at)->setTime($h, $m, $s);
        if ($at->lt($cutoffAt)) {
            return $at->copy()->subDay()->toDateString();
        }

        return $at->toDateString();
    }
}
