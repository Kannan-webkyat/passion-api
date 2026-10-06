<?php

namespace App\Support;

use App\Models\RoomType;
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

    /**
     * Minimum extra adult and extra child beds when every child age is known.
     * Null when ages are missing, so callers keep the age-blind bed count.
     *
     * @param  array<int, mixed>|null  $childAges
     * @return array{adult: int, child: int}|null
     */
    public static function requiredExtraBeds(RoomType $roomType, int $adults, int $children, ?array $childAges): ?array
    {
        $ages = self::knownChildAges($children, $childAges);
        if ($ages === null) {
            return null;
        }

        $from = (int) ($roomType->child_age_from ?? 0);
        $to = (int) ($roomType->child_age_limit ?? 12);
        $base = (int) ($roomType->base_occupancy ?? 2);
        $sharing = max(0, (int) ($roomType->child_sharing_limit ?? 1));
        $extraAdults = max(0, $adults - $base);
        $baseLeft = max(0, $base - $adults);
        $sharingLeft = $sharing;
        $over = 0;
        $band = 0;
        foreach ($ages as $age) {
            if ($age >= $to) {
                $over++;
            } elseif ($age >= $from) {
                $band++;
            }
        }

        $overInBase = min($over, $baseLeft);
        $baseLeft -= $overInBase;
        $overInShare = min($over - $overInBase, $sharingLeft);
        $sharingLeft -= $overInShare;
        $overExtra = $over - $overInBase - $overInShare;
        $bandInBase = min($band, $baseLeft);
        $baseLeft -= $bandInBase;
        $bandInShare = min($band - $bandInBase, $sharingLeft);
        $bandExtra = $band - $bandInBase - $bandInShare;

        return ['adult' => $extraAdults + $overExtra, 'child' => $bandExtra];
    }

    /**
     * Pre-tax extra bed amount for one night, or once for an hourly package.
     * Missing ages bill every selected bed at the extra adult price.
     *
     * @param  array<int, mixed>|null  $childAges
     */
    public static function extraBedPreTax(RoomType $roomType, int $adults, int $children, ?array $childAges, int $extraBeds): float
    {
        $selected = max(0, $extraBeds);
        $required = self::requiredExtraBeds($roomType, $adults, $children, $childAges);
        if ($required === null) {
            return $selected * (float) ($roomType->extra_bed_cost ?? 0);
        }

        $adultCharged = min($required['adult'], $selected);
        $childCharged = min($required['child'], $selected - $adultCharged);
        $leftover = $selected - $adultCharged - $childCharged;
        $adultBeds = $adultCharged + $leftover;
        $adultPrice = (float) ($roomType->extra_bed_cost ?? 0);
        $childPrice = (float) ($roomType->child_extra_bed_cost ?? 0);

        return ($adultBeds * $adultPrice) + ($childCharged * $childPrice);
    }

    /**
     * @param  array<int, mixed>|null  $childAges
     * @return list<int>|null
     */
    private static function knownChildAges(int $children, ?array $childAges): ?array
    {
        if ($children <= 0) {
            return [];
        }
        if (! is_array($childAges) || count($childAges) < $children) {
            return null;
        }

        $ages = [];
        for ($i = 0; $i < $children; $i++) {
            if (! is_numeric($childAges[$i])) {
                return null;
            }
            $ages[] = (int) $childAges[$i];
        }

        return $ages;
    }
}
