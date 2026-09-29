<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Kerala KGST Section 5(2) — bar foreign liquor turnover tax (TOT).
 * 4-star and above: regular method @ 10% on turnover (not compounded Section 7).
 * TOT is a periodic dealer liability — never itemized on customer bills.
 */
final class KgstBarTotPolicy
{
    public const SETTING_CUTOVER = 'kgst_bar_tot_cutover_date';

    public const TOT_RATE_PERCENT = 10.0;

    public const BUCKET_LABEL = 'Bar Turnover Tax (KGST) @ 10%';

    public static function cutoverDate(): ?string
    {
        $v = trim((string) Setting::get(self::SETTING_CUTOVER, ''));

        return $v !== '' ? $v : null;
    }

    public static function usesBarTurnoverModel(?string $businessDate): bool
    {
        $cutover = self::cutoverDate();
        if ($cutover === null || $businessDate === null || $businessDate === '') {
            return false;
        }

        return $businessDate >= $cutover;
    }

    public static function totLiabilityFromTurnover(float $turnover): float
    {
        if ($turnover <= 0) {
            return 0.0;
        }

        return round($turnover * (self::TOT_RATE_PERCENT / 100), 2);
    }

    /**
     * Per-line amounts for liquor under KGST vs legacy retail-VAT split.
     *
     * @return array{0: float, 1: float} [transaction_tax, turnover_or_net_taxable]
     */
    public static function liquorLineTaxAndTurnover(
        float $effGross,
        bool $taxExempt,
        bool $useKgstModel,
        bool $priceTaxInclusive,
        float $taxRatePercent,
    ): array {
        if ($taxExempt) {
            return [0.0, $effGross];
        }

        if ($useKgstModel) {
            return [0.0, round($effGross, 2)];
        }

        if ($priceTaxInclusive) {
            $lineTax = $taxRatePercent > 0 ? $effGross * ($taxRatePercent / (100 + $taxRatePercent)) : 0.0;

            return [round($lineTax, 2), round($effGross - $lineTax, 2)];
        }

        $lineTax = $effGross * ($taxRatePercent / 100);

        return [round($lineTax, 2), round($effGross, 2)];
    }
}
