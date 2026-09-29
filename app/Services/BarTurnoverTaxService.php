<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Monthly KGST bar foreign-liquor turnover and TOT liability (Section 5(2)).
 */
final class BarTurnoverTaxService
{
    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     bar_turnover: float,
     *     tot_rate_percent: float,
     *     tot_liability: float,
     *     line_count: int,
     *     bills_count: int,
     *     method: string,
     *     notes: list<string>,
     * }
     */
    public function summary(int $restaurantId, string $from, string $to): array
    {
        $lines = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'poi.order_id', '=', 'po.id')
            ->whereIn('po.status', ['paid', 'refunded'])
            ->where('po.restaurant_id', $restaurantId)
            ->where('poi.status', 'active')
            ->where('poi.tax_regime', 'vat_liquor')
            ->where('po.is_complimentary', false)
            ->whereDate('po.business_date', '>=', $from)
            ->whereDate('po.business_date', '<=', $to);

        $lineCount = (clone $lines)->count();
        $billsCount = (clone $lines)->distinct('po.id')->count('po.id');

        $turnover = $this->turnoverFromOrders($restaurantId, $from, $to);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'bar_turnover' => $turnover,
            'tot_rate_percent' => KgstBarTotPolicy::TOT_RATE_PERCENT,
            'tot_liability' => KgstBarTotPolicy::totLiabilityFromTurnover($turnover),
            'line_count' => $lineCount,
            'bills_count' => $billsCount,
            'method' => 'KGST Section 5(2) — 10% on bar foreign liquor turnover',
            'notes' => [
                'Turnover tax is not collected from customers on bills.',
                '4-star hotels use the regular turnover method (Section 7 compounded rate not applicable).',
                'Validate with your CA before filing.',
            ],
        ];
    }

    /**
     * Bar turnover from order aggregates (vat_net_taxable = gross turnover under KGST model).
     */
    public function turnoverFromOrders(int $restaurantId, string $from, string $to): float
    {
        return round((float) DB::table('pos_orders')
            ->whereIn('status', ['paid', 'refunded'])
            ->where('restaurant_id', $restaurantId)
            ->where('is_complimentary', false)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->sum('vat_net_taxable'), 2);
    }
}
