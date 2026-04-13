<?php

namespace App\Services;

/**
 * Centralized PO line tax math. Inclusive lines: tax = gross − rounded net (matches vendor quote).
 * Uses bcmath for money multiplication/division where it matters.
 */
final class PurchaseOrderLineAmounts
{
    public const BASIS_EXCLUSIVE = 'tax_exclusive';

    public const BASIS_INCLUSIVE = 'tax_inclusive';

    public const BASIS_NON_TAXABLE = 'non_taxable';

    public static function normalizeBasis(?string $basis): string
    {
        $b = $basis ?? self::BASIS_EXCLUSIVE;

        return in_array($b, [self::BASIS_EXCLUSIVE, self::BASIS_INCLUSIVE, self::BASIS_NON_TAXABLE], true)
            ? $b
            : self::BASIS_EXCLUSIVE;
    }

    /**
     * @return array{subtotal: float, tax_amount: float, total_amount: float, tax_rate: float}
     *                                                                                         subtotal = exclusive line net (inventory / ITC base); total_amount = gross payable line
     */
    public static function compute(float $quantity, float $unitPrice, float $taxRate, string $taxPriceBasis): array
    {
        $basis = self::normalizeBasis($taxPriceBasis);
        $q = max(0, $quantity);
        $up = max(0, $unitPrice);

        if ($basis === self::BASIS_NON_TAXABLE) {
            $sub = self::roundMoney2(bcmul(self::strNum($q), self::strNum($up), 8));

            return [
                'subtotal' => $sub,
                'tax_amount' => 0.0,
                'total_amount' => $sub,
                'tax_rate' => 0.0,
            ];
        }

        $rate = max(0, $taxRate);

        if ($basis === self::BASIS_EXCLUSIVE) {
            $sub = self::roundMoney2(bcmul(self::strNum($q), self::strNum($up), 8));
            $tax = self::roundMoney2(bcdiv(bcmul(self::strNum($sub), self::strNum($rate), 8), '100', 8));

            return [
                'subtotal' => $sub,
                'tax_amount' => $tax,
                'total_amount' => self::roundMoney2(bcadd(self::strNum($sub), self::strNum($tax), 8)),
                'tax_rate' => $rate,
            ];
        }

        // tax_inclusive: gross = qty × unit (inclusive); net = round(gross / (1+rate/100), 2); tax = gross − net
        $gross = self::roundMoney2(bcmul(self::strNum($q), self::strNum($up), 8));

        if ($rate <= 0.0) {
            return [
                'subtotal' => $gross,
                'tax_amount' => 0.0,
                'total_amount' => $gross,
                'tax_rate' => $rate,
            ];
        }

        $grossStr = self::strNum($gross);
        $divisor = bcadd('1', bcdiv(self::strNum($rate), '100', 12), 12);
        $netUnrounded = bcdiv($grossStr, $divisor, 12);
        $net = self::roundMoney2($netUnrounded);
        $tax = self::roundMoney2(bcsub($grossStr, self::strNum($net), 8));

        return [
            'subtotal' => $net,
            'tax_amount' => $tax,
            'total_amount' => $gross,
            'tax_rate' => $rate,
        ];
    }

    private static function strNum(float $n): string
    {
        return number_format($n, 8, '.', '');
    }

    private static function roundMoney2(string $value): float
    {
        if ($value === '' || $value === null) {
            return 0.0;
        }

        return round((float) $value, 2);
    }
}
