<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Property cancellation policy + settlement math for pre-arrival voids.
 *
 * Settings (via Setting key/value):
 * - cancellation_free_hours_before (int, default 24)
 * - cancellation_fee_type: none | percent | first_night | fixed (default first_night)
 * - cancellation_fee_value: percent (0–100) or fixed ₹ (default 100)
 */
final class BookingCancellationPolicy
{
    public const FEE_NONE = 'none';

    public const FEE_PERCENT = 'percent';

    public const FEE_FIRST_NIGHT = 'first_night';

    public const FEE_FIXED = 'fixed';

    public const REASONS = [
        'guest_request' => 'Guest request',
        'plans_changed' => 'Plans changed',
        'found_alternative' => 'Found alternative lodging',
        'illness_emergency' => 'Illness / emergency',
        'travel_disruption' => 'Travel disruption',
        'duplicate_booking' => 'Duplicate booking',
        'hotel_initiated' => 'Hotel initiated',
        'other' => 'Other',
    ];

    /**
     * @return array{
     *   free_hours_before: int,
     *   fee_type: string,
     *   fee_value: float,
     *   fee_type_label: string
     * }
     */
    public static function settings(): array
    {
        $type = strtolower(trim((string) Setting::get('cancellation_fee_type', self::FEE_FIRST_NIGHT)));
        if (! in_array($type, [self::FEE_NONE, self::FEE_PERCENT, self::FEE_FIRST_NIGHT, self::FEE_FIXED], true)) {
            $type = self::FEE_FIRST_NIGHT;
        }

        $hours = (int) Setting::get('cancellation_free_hours_before', 24);
        if ($hours < 0) {
            $hours = 0;
        }
        if ($hours > 8760) {
            $hours = 8760;
        }

        $value = (float) Setting::get('cancellation_fee_value', 100);
        if ($value < 0) {
            $value = 0;
        }
        if ($type === self::FEE_PERCENT && $value > 100) {
            $value = 100;
        }

        return [
            'free_hours_before' => $hours,
            'fee_type' => $type,
            'fee_value' => round($value, 2),
            'fee_type_label' => match ($type) {
                self::FEE_NONE => 'No fee',
                self::FEE_PERCENT => 'Percent of stay',
                self::FEE_FIRST_NIGHT => 'First night',
                self::FEE_FIXED => 'Fixed amount',
                default => $type,
            },
        ];
    }

    /**
     * Arrival datetime used for free-cancel window (check-in day + standard check-in time for day stays).
     */
    public static function arrivalAt(Booking $booking): Carbon
    {
        $unit = (string) ($booking->booking_unit ?? 'day');
        if ($unit === 'hour_package' && $booking->check_in_at) {
            return Carbon::parse($booking->check_in_at);
        }

        if ($booking->check_in_at) {
            return Carbon::parse($booking->check_in_at);
        }

        $day = Carbon::parse((string) $booking->check_in)->startOfDay();
        $standard = trim((string) Setting::get('standard_check_in_time', '14:00'));
        if (preg_match('/^\d{1,2}:\d{2}/', $standard)) {
            [$h, $m] = array_pad(explode(':', $standard, 3), 2, '0');
            $day->setTime((int) $h, (int) $m, 0);
        } else {
            $day->setTime(14, 0, 0);
        }

        return $day;
    }

    public static function stayTotal(Booking $booking): float
    {
        return max(0.0, round((float) ($booking->total_price ?? 0), 2));
    }

    public static function nightCount(Booking $booking): int
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return 1;
        }

        try {
            $ci = Carbon::parse((string) ($booking->check_in ?? ''))->startOfDay();
            $co = Carbon::parse((string) ($booking->check_out ?? ''))->startOfDay();
            $nights = (int) $ci->diffInDays($co);

            return max(1, $nights);
        } catch (\Throwable) {
            return 1;
        }
    }

    /** Approximate first-night charge from stored stay total. */
    public static function firstNightAmount(Booking $booking): float
    {
        $total = self::stayTotal($booking);
        $nights = self::nightCount($booking);

        return round($total / max(1, $nights), 2);
    }

    /**
     * Policy fee before staff waive/override.
     *
     * @return array{
     *   within_free_window: bool,
     *   hours_until_arrival: float,
     *   free_hours_before: int,
     *   fee_type: string,
     *   fee_type_label: string,
     *   fee_value: float,
     *   policy_fee: float,
     *   stay_total: float,
     *   first_night_amount: float,
     *   nights: int,
     *   arrival_at: string,
     *   policy_summary: string
     * }
     */
    public static function evaluate(Booking $booking, ?Carbon $asOf = null): array
    {
        $settings = self::settings();
        $asOf = $asOf?->copy() ?? now();
        $arrival = self::arrivalAt($booking);
        // Signed hours until arrival (positive = arrival in the future).
        $hoursUntil = round($asOf->floatDiffInRealHours($arrival, false), 2);
        // free_hours_before = 0 means no complimentary window (fee always applies outside fee_type=none).
        $withinFree = $settings['free_hours_before'] > 0
            && $hoursUntil >= (float) $settings['free_hours_before'];

        $stayTotal = self::stayTotal($booking);
        $firstNight = self::firstNightAmount($booking);
        $nights = self::nightCount($booking);

        $policyFee = 0.0;
        if (! $withinFree) {
            $policyFee = match ($settings['fee_type']) {
                self::FEE_NONE => 0.0,
                self::FEE_PERCENT => round($stayTotal * ((float) $settings['fee_value'] / 100), 2),
                self::FEE_FIRST_NIGHT => $firstNight,
                self::FEE_FIXED => round((float) $settings['fee_value'], 2),
                default => $firstNight,
            };
        }

        $policyFee = max(0.0, min($policyFee, $stayTotal > 0 ? $stayTotal : $policyFee));

        $summary = $withinFree
            ? sprintf(
                'Within free cancellation window (%d hours before arrival). No policy fee.',
                $settings['free_hours_before']
            )
            : match ($settings['fee_type']) {
                self::FEE_NONE => 'Outside free window, but property fee type is “No fee”.',
                self::FEE_PERCENT => sprintf(
                    'Outside free window (%d h). Fee = %s%% of stay (₹%s).',
                    $settings['free_hours_before'],
                    rtrim(rtrim(number_format((float) $settings['fee_value'], 2, '.', ''), '0'), '.'),
                    number_format($policyFee, 2, '.', '')
                ),
                self::FEE_FIRST_NIGHT => sprintf(
                    'Outside free window (%d h). Fee = first night (₹%s).',
                    $settings['free_hours_before'],
                    number_format($policyFee, 2, '.', '')
                ),
                self::FEE_FIXED => sprintf(
                    'Outside free window (%d h). Fee = fixed ₹%s.',
                    $settings['free_hours_before'],
                    number_format($policyFee, 2, '.', '')
                ),
                default => 'Cancellation fee applies per property policy.',
            };

        return [
            'within_free_window' => $withinFree,
            'hours_until_arrival' => $hoursUntil,
            'free_hours_before' => $settings['free_hours_before'],
            'fee_type' => $settings['fee_type'],
            'fee_type_label' => $settings['fee_type_label'],
            'fee_value' => $settings['fee_value'],
            'policy_fee' => round($policyFee, 2),
            'stay_total' => $stayTotal,
            'first_night_amount' => $firstNight,
            'nights' => $nights,
            'arrival_at' => $arrival->toIso8601String(),
            'policy_summary' => $summary,
        ];
    }

    /**
     * Deposit vs fee settlement.
     *
     * @return array{
     *   deposit_amount: float,
     *   effective_fee: float,
     *   forfeited_from_deposit: float,
     *   refund_due: float,
     *   balance_due: float,
     *   payment_status_after: string
     * }
     */
    public static function settle(float $deposit, float $effectiveFee): array
    {
        $deposit = max(0.0, round($deposit, 2));
        $effectiveFee = max(0.0, round($effectiveFee, 2));

        $forfeited = min($deposit, $effectiveFee);
        $refundDue = max(0.0, round($deposit - $effectiveFee, 2));
        $balanceDue = max(0.0, round($effectiveFee - $deposit, 2));

        $paymentStatus = 'pending';
        if ($refundDue > 0.004) {
            $paymentStatus = 'refunded';
        } elseif ($forfeited > 0.004 || $effectiveFee > 0.004) {
            // Fee retained from deposit (or collected) — treat as settled.
            $paymentStatus = 'paid';
        } elseif ($deposit <= 0.004 && $effectiveFee <= 0.004) {
            $paymentStatus = 'pending';
        }

        return [
            'deposit_amount' => $deposit,
            'effective_fee' => $effectiveFee,
            'forfeited_from_deposit' => round($forfeited, 2),
            'refund_due' => $refundDue,
            'balance_due' => $balanceDue,
            'payment_status_after' => $paymentStatus,
        ];
    }

    /**
     * Full preview payload for the cancel dialog.
     *
     * @return array<string, mixed>
     */
    public static function preview(
        Booking $booking,
        ?float $feeOverride = null,
        bool $waiveFee = false,
        float $additionalCollected = 0.0,
    ): array {
        $eval = self::evaluate($booking);
        $policyFee = (float) $eval['policy_fee'];

        $effectiveFee = $waiveFee
            ? 0.0
            : ($feeOverride !== null ? max(0.0, round($feeOverride, 2)) : $policyFee);

        // Cap at stay total when stay total is known.
        $stayTotal = (float) $eval['stay_total'];
        if ($stayTotal > 0.004) {
            $effectiveFee = min($effectiveFee, $stayTotal);
        }

        $deposit = max(0.0, round((float) ($booking->deposit_amount ?? 0), 2));
        $additionalCollected = max(0.0, round($additionalCollected, 2));
        $depositForSettle = round($deposit + $additionalCollected, 2);

        $settlement = self::settle($depositForSettle, $effectiveFee);

        return array_merge($eval, [
            'booking_id' => (int) $booking->id,
            'guest_name' => (string) ($booking->guest_name ?? trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''))),
            'status' => (string) $booking->status,
            'payment_status' => (string) ($booking->payment_status ?? 'pending'),
            'payment_method' => $booking->payment_method,
            'waive_fee' => $waiveFee,
            'fee_override' => $feeOverride,
            'effective_fee' => $effectiveFee,
            'fee_waived' => $waiveFee || ($effectiveFee <= 0.004 && $policyFee > 0.004),
            'existing_deposit' => $deposit,
            'additional_collected' => $additionalCollected,
            'deposit_for_settlement' => $depositForSettle,
            'forfeited_from_deposit' => $settlement['forfeited_from_deposit'],
            'refund_due' => $settlement['refund_due'],
            'balance_due' => $settlement['balance_due'],
            'payment_status_after' => $settlement['payment_status_after'],
            'can_cancel' => in_array((string) $booking->status, ['pending', 'confirmed'], true),
        ]);
    }
}
