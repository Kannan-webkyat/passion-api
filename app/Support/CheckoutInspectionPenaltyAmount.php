<?php

namespace App\Support;

/**
 * Resolves checkout inspection asset penalty amounts from `checkout_inspection_penalties`
 * (JSON map) or from a numeric penalty_key shorthand (staff enters "500" meaning ₹500 per unit).
 */
final class CheckoutInspectionPenaltyAmount
{
    /**
     * @param  array<string, mixed>  $penalties  Decoded checkout_inspection_penalties JSON
     * @return array{0: float, 1: ?string} [unit_amount, map_label or null]
     */
    public static function resolve(array $penalties, string $penKey): array
    {
        $penKey = trim($penKey);
        if ($penKey === '') {
            return [0.0, null];
        }

        $mapped = $penalties[$penKey] ?? null;
        if (is_array($mapped)) {
            $unit = round(max(0.0, (float) ($mapped['amount'] ?? 0)), 2);
            $lbl = isset($mapped['label']) ? trim((string) $mapped['label']) : '';

            return [$unit, $lbl !== '' ? $lbl : null];
        }

        if (preg_match('/^\d+(\.\d{1,4})?$/', $penKey)) {
            return [round(max(0.0, (float) $penKey), 2), null];
        }

        return [0.0, null];
    }
}
