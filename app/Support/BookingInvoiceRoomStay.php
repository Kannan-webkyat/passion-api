<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\PosOrder;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Recomputes room-stay inclusive totals for invoices so PDF totals match reception / room-chart math
 * (seasonal nights + bundled meals + policy early/late fees + GST), without double-counting POS folio.
 */
final class BookingInvoiceRoomStay
{
    public static function summarizeForInvoice(Booking $booking): array
    {
        $booking->loadMissing(['room.roomType.tax', 'room.roomType.ratePlans', 'room.roomType.seasons']);

        $storedRoomInclusive = (float) ($booking->total_price ?? 0);
        $storedExtraCharges = (float) ($booking->extra_charges ?? 0);
        $posPostedTotal = self::sumPosRoomChargePayments($booking);

        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return [
                'room_inclusive_grand' => $storedRoomInclusive,
                'additive_extra_charges' => $storedExtraCharges,
                'gross_before_checkout_discount' => round($storedRoomInclusive + $storedExtraCharges, 2),
            ];
        }

        $roomType = $booking->room?->roomType;
        $plan = $roomType?->ratePlans?->firstWhere('id', $booking->rate_plan_id);

        if (! $roomType || ! $plan) {
            return [
                'room_inclusive_grand' => $storedRoomInclusive,
                'additive_extra_charges' => $storedExtraCharges,
                'gross_before_checkout_discount' => round($storedRoomInclusive + $storedExtraCharges, 2),
            ];
        }

        $recomputedInclusive = self::recomputedDayStayInclusiveGrand($booking, $roomType, $plan);
        $roomInclusiveGrand = abs($recomputedInclusive - $storedRoomInclusive) >= 0.5
            ? $recomputedInclusive
            : $storedRoomInclusive;

        $taxRate = (float) ($roomType->tax?->rate ?? 0);
        $earlyLatePreTax = self::earlyCheckInFeePreTax($booking, $roomType) + self::lateCheckoutFeePreTax($booking, $roomType);
        $earlyLateInclusiveGuess = self::roomRatesIncludeGst()
            ? round($earlyLatePreTax, 2)
            : ($taxRate > 0.004
                ? round($earlyLatePreTax * (1 + $taxRate / 100), 2)
                : round($earlyLatePreTax, 2));

        $orphanExtra = max(0.0, round($storedExtraCharges - $posPostedTotal, 2));

        if ($orphanExtra <= 0.01) {
            $additiveExtra = $posPostedTotal;
        } elseif ($earlyLatePreTax > 0.004 && abs($orphanExtra - $earlyLatePreTax) < 1.0) {
            // Early/late stored only on extra_charges — already included in recomputed room GST bucket.
            $additiveExtra = $posPostedTotal;
        } elseif ($earlyLatePreTax > 0.004 && abs($orphanExtra - $earlyLateInclusiveGuess) < 1.0) {
            $additiveExtra = $posPostedTotal;
        } else {
            $additiveExtra = $storedExtraCharges;
        }

        return [
            'room_inclusive_grand' => round($roomInclusiveGrand, 2),
            'additive_extra_charges' => round($additiveExtra, 2),
            'gross_before_checkout_discount' => round($roomInclusiveGrand + $additiveExtra, 2),
        ];
    }

    public static function sumPosRoomChargePayments(Booking $booking): float
    {
        $orders = PosOrder::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'paid')
            ->whereHas('payments', fn ($q) => $q->where('method', 'room_charge'))
            ->with(['payments'])
            ->get();

        $sum = 0.0;
        foreach ($orders as $order) {
            $sum += (float) $order->payments->where('method', 'room_charge')->sum('amount');
        }

        return round($sum, 2);
    }

    private static function recomputedDayStayInclusiveGrand(Booking $booking, RoomType $roomType, RatePlan $plan): float
    {
        $checkInAt = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $checkOutAt = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();

        $basePerNight = (float) ($plan->base_price ?? 0);
        $extraBeds = (int) ($booking->extra_beds_count ?? 0);
        $extraBedCost = (float) ($roomType->extra_bed_cost ?? 0);

        $beforeTax = SeasonalRoomPricing::sumDayRoomRentWithSeasons(
            $basePerNight,
            $extraBedCost,
            $extraBeds,
            $checkInAt->copy()->startOfDay(),
            $checkOutAt->copy()->startOfDay(),
            $roomType->seasons ?? []
        );

        $beforeTax += self::mealPreTaxAddOns($booking, $roomType, $plan, $checkInAt, $checkOutAt);

        $beforeTax += self::earlyCheckInFeePreTax($booking, $roomType);
        $beforeTax += self::lateCheckoutFeePreTax($booking, $roomType);

        $taxRate = (float) ($roomType->tax?->rate ?? 0);

        if (self::roomRatesIncludeGst()) {
            return round($beforeTax, 2);
        }

        return round($beforeTax * (1 + ($taxRate / 100)), 2);
    }

    private static function roomRatesIncludeGst(): bool
    {
        return filter_var(Setting::get('room_rates_include_gst', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private static function mealPreTaxAddOns(Booking $booking, RoomType $roomType, RatePlan $plan, Carbon $checkInAt, Carbon $checkOutAt): float
    {
        $nights = max(1, (int) $checkInAt->copy()->startOfDay()->diffInDays($checkOutAt->copy()->startOfDay()));
        $meal = self::mealPlanKind($plan);
        $hasBkf = in_array($meal, ['breakfast', 'half_board', 'full_board'], true);
        $hasLnc = $meal === 'full_board';
        $hasDnr = in_array($meal, ['half_board', 'full_board'], true);

        $add = 0.0;
        $adults = (int) ($booking->adults_count ?? 1);
        $children = (int) ($booking->children_count ?? 0);

        if ($hasBkf) {
            $bp = (float) ($roomType->breakfast_price ?? 0);
            $cbp = (float) ($roomType->child_breakfast_price ?? 0);
            $add += ($adults * $bp + $children * $cbp) * $nights;
        }
        if ($hasLnc) {
            $add +=
                ((float) ($roomType->adult_lunch_price ?? 0) * $adults + (float) ($roomType->child_lunch_price ?? 0) * $children) * $nights;
        }
        if ($hasDnr) {
            $add +=
                ((float) ($roomType->adult_dinner_price ?? 0) * $adults + (float) ($roomType->child_dinner_price ?? 0) * $children) * $nights;
        }

        if (! $hasBkf) {
            $adB = (int) ($booking->adult_breakfast_count ?? 0);
            $chB = (int) ($booking->child_breakfast_count ?? 0);
            $bp = (float) ($roomType->breakfast_price ?? 0);
            $cbp = (float) ($roomType->child_breakfast_price ?? 0);
            $add += ($adB * $bp + $chB * $cbp) * $nights;
        }

        return $add;
    }

    private static function mealPlanKind(?RatePlan $plan): string
    {
        if (! $plan) {
            return 'room_only';
        }
        $t = trim((string) ($plan->meal_plan_type ?? ''));
        if ($t !== '') {
            return $t;
        }
        $name = (string) ($plan->name ?? '');
        if (preg_match('/\bMAP\b/i', $name)) {
            return 'half_board';
        }
        if (preg_match('/\bAP\b/i', $name)) {
            return 'full_board';
        }
        if (preg_match('/\bCP\b/i', $name)) {
            return 'breakfast';
        }

        return 'room_only';
    }

    /**
     * Minutes from midnight for a booking time column (matches reception room-chart `timeToMinutes`).
     */
    private static function clockMinutesFromBookingField(?string $raw): ?int
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        try {
            $t = Carbon::parse(trim((string) $raw));

            return ($t->hour * 60) + $t->minute;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function policyClockMinutes(string $settingKey, string $defaultTime): int
    {
        $s = trim((string) Setting::get($settingKey, $defaultTime));
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            return ((int) $m[1] * 60) + (int) $m[2];
        }
        try {
            $t = Carbon::parse($s);

            return ($t->hour * 60) + $t->minute;
        } catch (\Throwable) {
            if (preg_match('/^(\d{1,2}):(\d{2})/', $defaultTime, $m)) {
                return ((int) $m[1] * 60) + (int) $m[2];
            }

            return 11 * 60;
        }
    }

    /**
     * Mirrors {@see RoomChartDrawer} early block + {@see roomChart/page.tsx} `computeEarlyLateFee`
     * (eligibility uses **standard check-out** policy — same quirk as the UI).
     */
    private static function earlyCheckInFeePreTax(Booking $booking, RoomType $roomType): float
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return 0.0;
        }

        $etaRaw = $booking->early_checkin_time ?: $booking->estimated_arrival_time;
        $etaMin = self::clockMinutesFromBookingField($etaRaw !== null ? (string) $etaRaw : null);
        if ($etaMin === null) {
            return 0.0;
        }

        $policyCheckOutMin = self::policyClockMinutes('standard_check_out_time', '11:00');
        if ($etaMin >= $policyCheckOutMin) {
            return 0.0;
        }

        $fee = (float) ($roomType->early_check_in_fee ?? 0);
        if ($fee <= 0.004) {
            return 0.0;
        }

        $typeRaw = strtolower((string) ($roomType->early_check_in_type ?? 'flat_fee'));
        $type = match (true) {
            in_array($typeRaw, ['hour', 'per_hour'], true) => 'per_hour',
            in_array($typeRaw, ['minute', 'per_minute'], true) => 'per_minute',
            in_array($typeRaw, ['flat', 'flat_fee'], true) => 'flat_fee',
            default => $typeRaw,
        };

        // Flat fee: room chart returns the full fee whenever the outer guard passes — no buffer / check-in delta.
        if ($type === 'flat_fee') {
            return round($fee, 2);
        }

        $policyCheckInMin = self::policyClockMinutes('standard_check_in_time', '14:00');
        $deltaMinutes = $policyCheckInMin - $etaMin;
        $bufferMins = (int) ($roomType->early_check_in_buffer_minutes ?? 0);
        $billableMins = max(0, $deltaMinutes - $bufferMins);
        if ($billableMins <= 0) {
            return 0.0;
        }

        if ($type === 'per_hour') {
            $billableHours = (int) ceil($billableMins / 60);

            return round($billableHours * $fee, 2);
        }
        if ($type === 'per_minute') {
            return round($billableMins * $fee, 2);
        }

        return round($fee, 2);
    }

    /**
     * Mirrors {@see RoomChartDrawer} late block + `computeEarlyLateFee`.
     */
    private static function lateCheckoutFeePreTax(Booking $booking, RoomType $roomType): float
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return 0.0;
        }

        $lcoRaw = $booking->late_checkout_time;
        $lcoMin = self::clockMinutesFromBookingField($lcoRaw !== null ? (string) $lcoRaw : null);
        if ($lcoMin === null) {
            return 0.0;
        }

        $policyCheckOutMin = self::policyClockMinutes('standard_check_out_time', '11:00');
        if ($lcoMin <= $policyCheckOutMin) {
            return 0.0;
        }

        $fee = (float) ($roomType->late_check_out_fee ?? 0);
        if ($fee <= 0.004) {
            return 0.0;
        }

        $typeRaw = strtolower((string) ($roomType->late_check_out_type ?? 'flat_fee'));
        $type = match (true) {
            in_array($typeRaw, ['hour', 'per_hour'], true) => 'per_hour',
            in_array($typeRaw, ['minute', 'per_minute'], true) => 'per_minute',
            in_array($typeRaw, ['flat', 'flat_fee'], true) => 'flat_fee',
            default => $typeRaw,
        };

        if ($type === 'flat_fee') {
            return round($fee, 2);
        }

        $deltaMinutes = $lcoMin - $policyCheckOutMin;
        $bufferMins = (int) ($roomType->late_check_out_buffer_minutes ?? 0);
        $billableMins = max(0, $deltaMinutes - $bufferMins);
        if ($billableMins <= 0) {
            return 0.0;
        }

        if ($type === 'per_hour') {
            $billableHours = (int) ceil($billableMins / 60);

            return round($billableHours * $fee, 2);
        }
        if ($type === 'per_minute') {
            return round($billableMins * $fee, 2);
        }

        return round($fee, 2);
    }
}
