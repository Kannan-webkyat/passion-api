<?php

namespace App\Support;

use App\Models\RoomType;

/**
 * Splits extra-bed charges between adult and child rates using the same guest-mix rules as capacity validation.
 */
final class ExtraBedPricing
{
    public static function perNightCharge(RoomType $roomType, int $adults, int $children, int $extraBeds): float
    {
        if ($extraBeds <= 0) {
            return 0.0;
        }

        $adultRate = (float) ($roomType->extra_bed_cost ?? 0);
        $childRate = (float) ($roomType->child_extra_bed_cost ?? 0);
        if ($childRate <= 0) {
            $childRate = $adultRate;
        }

        $baseOcc = (int) ($roomType->base_occupancy ?? 2);
        $childLimit = (int) ($roomType->child_sharing_limit ?? 1);

        $extraAdults = max(0, $adults - $baseOcc);
        $remBase = max(0, $baseOcc - $adults);
        $extraChildrenMin = max(0, $children - $remBase - $childLimit);

        $adultBeds = min($extraBeds, $extraAdults);
        $remaining = $extraBeds - $adultBeds;
        $childBeds = min($remaining, $extraChildrenMin);
        $otherBeds = $remaining - $childBeds;

        return $adultBeds * $adultRate + $childBeds * $childRate + $otherBeds * $adultRate;
    }
}
