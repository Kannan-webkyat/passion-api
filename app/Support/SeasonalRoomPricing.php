<?php

namespace App\Support;

use App\Models\RoomTypeSeason;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Room-type seasonal periods (admin) apply per calendar night of a stay.
 * Adjustment types match {@see RoomTypeController} validation.
 */
final class SeasonalRoomPricing
{
    public static function applyToBase(float $base, ?RoomTypeSeason $season): float
    {
        if ($season === null) {
            return $base;
        }

        $adj = (float) $season->price_adjustment;

        return match ($season->adjustment_type) {
            'override' => $adj,
            'add_fixed' => $base + $adj,
            'add_percent' => $base * (1 + $adj / 100),
            'discount' => max(0.0, $base - $adj),
            default => $base,
        };
    }

    /**
     * @param  iterable<RoomTypeSeason>|Collection<int, RoomTypeSeason>|null  $seasons
     */
    public static function seasonForDate($seasons, Carbon $date): ?RoomTypeSeason
    {
        if ($seasons === null) {
            return null;
        }
        if ($seasons instanceof Collection) {
            $seasons = $seasons->all();
        }
        $d = $date->toDateString();
        foreach ($seasons as $s) {
            $start = $s->start_date instanceof \Carbon\CarbonInterface
                ? $s->start_date->format('Y-m-d')
                : substr((string) $s->start_date, 0, 10);
            $end = $s->end_date instanceof \Carbon\CarbonInterface
                ? $s->end_date->format('Y-m-d')
                : substr((string) $s->end_date, 0, 10);
            if ($d >= $start && $d <= $end) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Sum room rent + extra beds for each night, applying the matching season (if any) to base rent only.
     *
     * @param  iterable<RoomTypeSeason>|Collection<int, RoomTypeSeason>|null  $seasons
     */
    public static function sumDayRoomRentWithSeasons(
        float $basePerNight,
        float $extraBedCost,
        int $extraBeds,
        Carbon $checkInStart,
        Carbon $checkOutStart,
        $seasons
    ): float {
        $start = $checkInStart->copy()->startOfDay();
        $end = $checkOutStart->copy()->startOfDay();
        $nights = max(1, $start->diffInDays($end));
        $sum = 0.0;
        for ($i = 0; $i < $nights; $i++) {
            $night = $start->copy()->addDays($i);
            $season = self::seasonForDate($seasons, $night);
            $nightly = self::applyToBase($basePerNight, $season);
            $sum += $nightly + ($extraBeds * $extraBedCost);
        }

        return $sum;
    }
}
