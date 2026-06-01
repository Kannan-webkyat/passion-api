<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Recipe;
use App\Models\RestaurantMaster;
use App\Models\Room;
use App\Models\User;
use App\Support\BookingInvoiceRoomStay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PropertyFinancialSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(string $from, string $to, User $user): array
    {
        $result = $this->summarizeCore($from, $to, $user);
        $previous = $this->previousPeriodRange($from, $to);
        $previousCore = $this->summarizeCore($previous['from'], $previous['to'], $user);

        $result['trend'] = $this->buildTrend($from, $to, $user);
        $result['comparison_period'] = $previous['label'];
        $result['sparkline'] = $this->buildSparkline($to, $user);
        $result['revenue_breakdown'] = $this->revenueBreakdown($result);
        $result['financial_insights'] = $this->financialInsights($result);
        $result['booking_performance'] = $this->bookingPerformance($from, $to, $user);
        $result['room_performance'] = $this->roomSnapshot($to, $user);
        $result['hospitality'] = $this->hospitalityMetrics($from, $to, $result, $user);
        $result['kpis'] = $this->buildKpis($result, $previousCore, $previous['label'], $user);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeCore(string $from, string $to, User $user): array
    {
        $canRooms = $this->canReadRooms($user);
        $canPos = $this->canReadPos($user);

        $rooms = $canRooms
            ? $this->roomTotals($from, $to)
            : $this->emptyRoomsSlice();

        if ($canRooms) {
            $collections = $this->roomCollectionsInPeriod($from, $to);
            $rooms['collected'] = $collections['collected'];
            $rooms['payments_count'] = $collections['payments_count'];
            $rooms['checkout_revenue'] = $rooms['revenue'];
            $rooms['revenue'] = $collections['collected'];
        } else {
            $rooms['collected'] = 0.0;
            $rooms['payments_count'] = 0;
            $rooms['checkout_revenue'] = 0.0;
        }

        $pos = $canPos
            ? $this->posTotals($from, $to, $user)
            : $this->emptyPosSlice();

        $totalSales = round($rooms['revenue'] + $pos['direct']['total_sales'], 2);
        $revenue = round($rooms['revenue'] + $pos['direct']['revenue'], 2);
        $foodCost = round($pos['food_cost'], 2);
        $profit = round($revenue - $foodCost, 2);
        $marginBase = $revenue > 0 ? $revenue : 0.0;
        $profitMarginPct = $marginBase > 0 ? round(($profit / $marginBase) * 100, 1) : 0.0;

        return [
            'from' => $from,
            'to' => $to,
            'total_sales' => $totalSales,
            'revenue' => $revenue,
            'food_cost' => $foodCost,
            'profit' => $profit,
            'profit_margin_pct' => $profitMarginPct,
            'breakdown' => [
                'rooms' => $rooms,
                'fb_direct' => $pos['direct'],
                'fb_room_posted' => $pos['room_posted'],
                'fb_meta' => [
                    'tax_amount' => $pos['tax_amount'] ?? 0.0,
                    'discount_amount' => $pos['discount_amount'] ?? 0.0,
                    'service_charge_amount' => $pos['service_charge_amount'] ?? 0.0,
                    'tip_amount' => $pos['tip_amount'] ?? 0.0,
                ],
            ],
        ];
    }

    /**
     * Time-series buckets for charts (daily / weekly / monthly by range length).
     *
     * @return list<array{label: string, total_sales: float, revenue: float, profit: float}>
     */
    private function buildTrend(string $from, string $to, User $user): array
    {
        $start = \Carbon\Carbon::parse($from)->startOfDay();
        $end = \Carbon\Carbon::parse($to)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;

        if ($days <= 1) {
            return [];
        }

        $buckets = [];
        if ($days <= 31) {
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $ds = $d->toDateString();
                $buckets[] = [
                    'from' => $ds,
                    'to' => $ds,
                    'label' => $d->format('d M'),
                ];
            }
        } elseif ($days <= 186) {
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $bFrom = $cursor->toDateString();
                $bEnd = $cursor->copy()->addDays(6);
                if ($bEnd->gt($end)) {
                    $bEnd = $end->copy();
                }
                $bTo = $bEnd->toDateString();
                $buckets[] = [
                    'from' => $bFrom,
                    'to' => $bTo,
                    'label' => $cursor->format('d M'),
                ];
                $cursor = $bEnd->copy()->addDay();
            }
        } else {
            $cursor = $start->copy()->startOfMonth();
            while ($cursor->lte($end)) {
                $bFrom = $cursor->copy()->max($start)->toDateString();
                $monthEnd = $cursor->copy()->endOfMonth();
                $bTo = $monthEnd->lt($end) ? $monthEnd->toDateString() : $end->toDateString();
                $buckets[] = [
                    'from' => $bFrom,
                    'to' => $bTo,
                    'label' => $cursor->format('M y'),
                ];
                $cursor->addMonth()->startOfMonth();
            }
        }

        $points = [];
        foreach ($buckets as $bucket) {
            $slice = $this->summarizeCore($bucket['from'], $bucket['to'], $user);
            $points[] = [
                'label' => $bucket['label'],
                'total_sales' => $slice['total_sales'],
                'revenue' => $slice['revenue'],
                'profit' => $slice['profit'],
            ];
        }

        return $points;
    }

    private function canReadRooms(User $user): bool
    {
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return true;
        }

        return $user->can('reservation-view')
            || $user->can('reservation')
            || $user->can('view-rooms')
            || $user->can('manage-rooms')
            || $user->can('rooms-view');
    }

    private function canReadPos(User $user): bool
    {
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return true;
        }

        return $user->can('report-sales');
    }

    /**
     * @return array{
     *     bookings_count: int,
     *     check_ins: int,
     *     room_nights: int,
     *     total_sales: float,
     *     revenue: float,
     *     refunds: float,
     *     discounts: float,
     *     extra_charges: float,
     *     taxes_estimated: float
     * }
     */
    private function roomTotals(string $from, string $to): array
    {
        $bookings = Booking::query()
            ->where('status', '=', 'checked_out')
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereNotNull('check_out_at')
                        ->whereDate('check_out_at', '>=', $from)
                        ->whereDate('check_out_at', '<=', $to);
                })->orWhere(function ($q2) use ($from, $to) {
                    $q2->whereNull('check_out_at')
                        ->whereDate('check_out', '>=', $from)
                        ->whereDate('check_out', '<=', $to);
                });
            })
            ->with(['room.roomType.tax'])
            ->get();

        $gross = 0.0;
        $revenue = 0.0;
        $refunds = 0.0;
        $discounts = 0.0;
        $extraCharges = 0.0;
        $taxes = 0.0;
        $roomNights = 0;

        foreach ($bookings as $booking) {
            $summary = $this->bookingGrossDetails($booking);
            $bookingGross = $summary['gross'];
            $discount = max(0.0, (float) ($booking->checkout_discount_amount ?? 0));
            $refund = max(0.0, (float) ($booking->refund_amount ?? 0));
            $gross += $bookingGross;
            $revenue += max(0.0, round($bookingGross - min($discount, $bookingGross) - $refund, 2));
            $refunds += $refund;
            $discounts += $discount;
            $extraCharges += $summary['extra_charges'];
            $taxes += $summary['tax_estimated'];
            $roomNights += $this->bookingRoomNights($booking);
        }

        $checkIns = (int) Booking::query()
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->whereNotNull('check_in_at')
            ->whereDate('check_in_at', '>=', $from)
            ->whereDate('check_in_at', '<=', $to)
            ->count();

        return [
            'bookings_count' => $bookings->count(),
            'check_ins' => $checkIns,
            'room_nights' => $roomNights,
            'total_sales' => round($gross, 2),
            'revenue' => round($revenue, 2),
            'refunds' => round($refunds, 2),
            'discounts' => round($discounts, 2),
            'extra_charges' => round($extraCharges, 2),
            'taxes_estimated' => round($taxes, 2),
        ];
    }

    /**
     * Cash collected from room deposits/payments in the period (parsed from booking audit notes).
     *
     * @return array{collected: float, payments_count: int}
     */
    private function roomCollectionsInPeriod(string $from, string $to): array
    {
        $collected = 0.0;
        $paymentsCount = 0;
        $collectedByBooking = [];

        $bookings = Booking::query()
            ->whereNotNull('notes')
            ->where('notes', 'like', '%[Deposit:%')
            ->get(['id', 'notes']);

        foreach ($bookings as $booking) {
            foreach ($this->parseDepositAuditLines($booking->notes ?? '') as $line) {
                if ($line['date'] < $from || $line['date'] > $to || $line['amount'] <= 0) {
                    continue;
                }
                $collected += $line['amount'];
                $paymentsCount++;
                $collectedByBooking[$booking->id] = ($collectedByBooking[$booking->id] ?? 0) + $line['amount'];
            }
        }

        // Deposits taken at reservation creation (no separate Deposit audit line).
        $createdWithDeposit = Booking::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->where('deposit_amount', '>', 0)
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereNotNull('check_in_at')
                        ->whereDate('check_in_at', '>=', $from)
                        ->whereDate('check_in_at', '<=', $to);
                })->orWhere(function ($q2) use ($from, $to) {
                    $q2->whereNull('check_in_at')
                        ->whereDate('check_in', '>=', $from)
                        ->whereDate('check_in', '<=', $to);
                });
            })
            ->get(['id', 'deposit_amount']);

        foreach ($createdWithDeposit as $booking) {
            if (($collectedByBooking[$booking->id] ?? 0) > 0.004) {
                continue;
            }
            $collected += (float) $booking->deposit_amount;
            $paymentsCount++;
        }

        return [
            'collected' => round($collected, 2),
            'payments_count' => $paymentsCount,
        ];
    }

    /**
     * @return list<array{date: string, amount: float}>
     */
    private function parseDepositAuditLines(string $notes): array
    {
        $lines = [];
        if (! preg_match_all(
            '/\[Deposit:.*?\(([+\x{2212}\-])₹([\d,\.]+)\).*?on (\d{4}-\d{2}-\d{2})/u',
            $notes,
            $matches,
            PREG_SET_ORDER,
        )) {
            return $lines;
        }

        foreach ($matches as $match) {
            $sign = ($match[1] === '+' || $match[1] === '-') ? ($match[1] === '+' ? 1 : -1) : -1;
            $amount = (float) str_replace(',', '', $match[2]);
            $lines[] = [
                'date' => $match[3],
                'amount' => round($sign * $amount, 2),
            ];
        }

        return $lines;
    }

    /**
     * @return array{gross: float, extra_charges: float, tax_estimated: float}
     */
    private function bookingGrossDetails(Booking $booking): array
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            $gross = (float) ($booking->total_price ?? 0) + (float) ($booking->extra_charges ?? 0);

            return [
                'gross' => $gross,
                'extra_charges' => (float) ($booking->extra_charges ?? 0),
                'tax_estimated' => 0.0,
            ];
        }

        $booking->loadMissing(['room.roomType.tax', 'room.roomType.ratePlans']);
        $invoice = BookingInvoiceRoomStay::summarizeForInvoice($booking);
        $gross = (float) $invoice['gross_before_checkout_discount'];
        $extra = (float) ($invoice['additive_extra_charges'] ?? 0);
        $taxRate = (float) ($booking->room?->roomType?->tax?->rate ?? 0);
        $taxEstimated = $taxRate > 0.004
            ? round($gross - ($gross / (1 + ($taxRate / 100))), 2)
            : 0.0;

        return [
            'gross' => $gross,
            'extra_charges' => $extra,
            'tax_estimated' => $taxEstimated,
        ];
    }

    private function bookingRoomNights(Booking $booking): int
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return 1;
        }

        $checkIn = Carbon::parse($booking->check_in)->startOfDay();
        $checkOut = Carbon::parse($booking->check_out)->startOfDay();
        $nights = (int) max(1, $checkIn->diffInDays($checkOut));

        return $nights;
    }

    private function bookingGross(Booking $booking): float
    {
        return $this->bookingGrossDetails($booking)['gross'];
    }

    /**
     * @return array{
     *     direct: array{orders_count: int, total_sales: float, revenue: float, total_refunded: float},
     *     room_posted: array{total_sales: float, revenue: float},
     *     food_cost: float,
     *     outlet_count: int
     * }
     */
    private function posTotals(string $from, string $to, User $user): array
    {
        $outletIds = $this->resolveAccessibleOutletIds($user);

        if ($outletIds === []) {
            return [
                'direct' => [
                    'orders_count' => 0,
                    'total_sales' => 0.0,
                    'revenue' => 0.0,
                    'total_refunded' => 0.0,
                ],
                'room_posted' => [
                    'total_sales' => 0.0,
                    'revenue' => 0.0,
                ],
                'food_cost' => 0.0,
                'outlet_count' => 0,
                'tax_amount' => 0.0,
                'discount_amount' => 0.0,
                'service_charge_amount' => 0.0,
                'tip_amount' => 0.0,
            ];
        }

        $baseOrdersQ = DB::table('pos_orders')
            ->whereIn('status', ['paid', 'refunded'])
            ->whereIn('restaurant_id', $outletIds)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to);

        $agg = (clone $baseOrdersQ)->select(
            DB::raw('COUNT(*) as orders_count'),
            DB::raw('SUM(total_amount) as total_amount'),
            DB::raw('SUM(COALESCE(tax_amount, 0)) as tax_amount'),
            DB::raw('SUM(COALESCE(vat_tax_amount, 0)) as vat_tax_amount'),
            DB::raw('SUM(COALESCE(discount_amount, 0)) as discount_amount'),
            DB::raw('SUM(COALESCE(service_charge_amount, 0)) as service_charge_amount'),
            DB::raw('SUM(COALESCE(tip_amount, 0)) as tip_amount'),
        )->first();

        $refundsQ = DB::table('pos_order_refunds')
            ->join('pos_orders', 'pos_order_refunds.order_id', '=', 'pos_orders.id')
            ->whereIn('pos_orders.restaurant_id', $outletIds)
            ->whereDate('pos_order_refunds.business_date', '>=', $from)
            ->whereDate('pos_order_refunds.business_date', '<=', $to);

        $totalRefunded = (float) $refundsQ->sum('pos_order_refunds.amount');
        $totalSales = (float) ($agg->total_amount ?? 0);
        $netRealized = round($totalSales - $totalRefunded, 2);

        $orderIds = (clone $baseOrdersQ)->pluck('id')->all();
        $roomChargeGross = 0.0;
        $roomChargeNet = 0.0;

        if ($orderIds !== []) {
            $paymentRows = DB::table('pos_payments')
                ->whereIn('order_id', $orderIds)
                ->where('method', '=', 'room_charge')
                ->select(
                    DB::raw('SUM(amount) as gross_amount'),
                    DB::raw('COUNT(*) as count'),
                )
                ->first();

            $roomChargeGross = (float) ($paymentRows->gross_amount ?? 0);

            $roomChargeRefunds = (float) DB::table('pos_order_refunds')
                ->join('pos_orders', 'pos_order_refunds.order_id', '=', 'pos_orders.id')
                ->whereIn('pos_orders.id', $orderIds)
                ->where('pos_order_refunds.method', '=', 'room_charge')
                ->whereDate('pos_order_refunds.business_date', '>=', $from)
                ->whereDate('pos_order_refunds.business_date', '<=', $to)
                ->sum('pos_order_refunds.amount');

            $roomChargeNet = round(max(0.0, $roomChargeGross - $roomChargeRefunds), 2);
        }

        $directSales = round(max(0.0, $totalSales - $roomChargeGross), 2);
        $directRevenue = round(max(0.0, $netRealized - $roomChargeNet), 2);
        $directRefunds = round(max(0.0, $totalRefunded - ($roomChargeGross - $roomChargeNet)), 2);

        return [
            'direct' => [
                'orders_count' => (int) ($agg->orders_count ?? 0),
                'total_sales' => $directSales,
                'revenue' => $directRevenue,
                'total_refunded' => $directRefunds,
            ],
            'room_posted' => [
                'total_sales' => round($roomChargeGross, 2),
                'revenue' => $roomChargeNet,
            ],
            'food_cost' => $this->estimateFoodCostForOutlets($outletIds, $from, $to),
            'outlet_count' => count($outletIds),
            'tax_amount' => round((float) ($agg->tax_amount ?? 0) + (float) ($agg->vat_tax_amount ?? 0), 2),
            'discount_amount' => round((float) ($agg->discount_amount ?? 0), 2),
            'service_charge_amount' => round((float) ($agg->service_charge_amount ?? 0), 2),
            'tip_amount' => round((float) ($agg->tip_amount ?? 0), 2),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function resolveAccessibleOutletIds(User $user): array
    {
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return RestaurantMaster::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
        }

        $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn($id) => (int) $id)->all();
        if (count($assigned) > 0) {
            return $assigned;
        }

        $deptIds = $user->departments()->pluck('departments.id')->map(fn($id) => (int) $id)->all();
        if (count($deptIds) > 0) {
            return RestaurantMaster::query()
                ->where('is_active', true)
                ->where(function ($q) use ($deptIds) {
                    $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                })
                ->orderBy('id')
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
        }

        return [];
    }

    private function estimateFoodCostForOutlets(array $outletIds, string $from, string $to): float
    {
        if ($outletIds === []) {
            return 0.0;
        }

        $costByMenuItemId = Recipe::query()
            ->with(['ingredients.inventoryItem'])
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn(Recipe $recipe) => [(int) $recipe->menu_item_id => (float) $recipe->cost_per_portion]);

        if ($costByMenuItemId->isEmpty()) {
            return 0.0;
        }

        $soldRows = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'poi.order_id', '=', 'po.id')
            ->whereIn('po.status', ['paid', 'refunded'])
            ->whereIn('po.restaurant_id', $outletIds)
            ->whereDate('po.business_date', '>=', $from)
            ->whereDate('po.business_date', '<=', $to)
            ->where('poi.status', 'active')
            ->whereNotNull('poi.menu_item_id')
            ->groupBy('poi.menu_item_id')
            ->select('poi.menu_item_id', DB::raw('SUM(poi.quantity) as qty'))
            ->get();

        $total = 0.0;
        foreach ($soldRows as $row) {
            $menuItemId = (int) $row->menu_item_id;
            $qty = (float) $row->qty;
            $total += $qty * (float) ($costByMenuItemId[$menuItemId] ?? 0.0);
        }

        return round($total, 2);
    }

    /**
     * @return array{bookings_count: int, check_ins: int, room_nights: int, total_sales: float, revenue: float, refunds: float, discounts: float, extra_charges: float, taxes_estimated: float}
     */
    private function emptyRoomsSlice(): array
    {
        return [
            'bookings_count' => 0,
            'check_ins' => 0,
            'room_nights' => 0,
            'total_sales' => 0.0,
            'revenue' => 0.0,
            'checkout_revenue' => 0.0,
            'collected' => 0.0,
            'payments_count' => 0,
            'refunds' => 0.0,
            'discounts' => 0.0,
            'extra_charges' => 0.0,
            'taxes_estimated' => 0.0,
        ];
    }

    /**
     * @return array{
     *     direct: array{orders_count: int, total_sales: float, revenue: float, total_refunded: float},
     *     room_posted: array{total_sales: float, revenue: float},
     *     food_cost: float,
     *     outlet_count: int,
     *     tax_amount: float,
     *     discount_amount: float,
     *     service_charge_amount: float,
     *     tip_amount: float
     * }
     */
    private function emptyPosSlice(): array
    {
        return [
            'direct' => [
                'orders_count' => 0,
                'total_sales' => 0.0,
                'revenue' => 0.0,
                'total_refunded' => 0.0,
            ],
            'room_posted' => [
                'total_sales' => 0.0,
                'revenue' => 0.0,
            ],
            'food_cost' => 0.0,
            'outlet_count' => 0,
            'tax_amount' => 0.0,
            'discount_amount' => 0.0,
            'service_charge_amount' => 0.0,
            'tip_amount' => 0.0,
        ];
    }

    /**
     * @return array{from: string, to: string, label: string}
     */
    private function previousPeriodRange(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;

        if ($days === 1) {
            $prev = $start->copy()->subDay();

            return [
                'from' => $prev->toDateString(),
                'to' => $prev->toDateString(),
                'label' => 'yesterday',
            ];
        }

        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        if ($days === 7) {
            return [
                'from' => $prevStart->toDateString(),
                'to' => $prevEnd->toDateString(),
                'label' => 'last week',
            ];
        }

        if ($start->day === 1 && $end->isSameMonth($start) && $end->isToday()) {
            $prevStart = $start->copy()->subMonth()->startOfMonth();
            $prevEnd = $start->copy()->subDay();

            return [
                'from' => $prevStart->toDateString(),
                'to' => $prevEnd->toDateString(),
                'label' => 'last month',
            ];
        }

        if ($start->month === 1 && $start->day === 1 && $end->isSameYear($start) && $end->isToday()) {
            $prevStart = $start->copy()->subYear()->startOfYear();
            $prevEnd = $start->copy()->subDay();

            return [
                'from' => $prevStart->toDateString(),
                'to' => $prevEnd->toDateString(),
                'label' => 'last year',
            ];
        }

        return [
            'from' => $prevStart->toDateString(),
            'to' => $prevEnd->toDateString(),
            'label' => 'previous period',
        ];
    }

    /**
     * @return list<array{label: string, revenue: float}>
     */
    private function buildSparkline(string $endDate, User $user): array
    {
        $end = Carbon::parse($endDate)->startOfDay();
        $points = [];

        for ($i = 6; $i >= 0; $i--) {
            $d = $end->copy()->subDays($i);
            $ds = $d->toDateString();
            $slice = $this->summarizeCore($ds, $ds, $user);
            $points[] = [
                'label' => $d->format('d M'),
                'revenue' => $slice['revenue'],
            ];
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $core
     * @return list<array{key: string, label: string, value: float, pct: float}>
     */
    private function revenueBreakdown(array $core): array
    {
        $rooms = (float) ($core['breakdown']['rooms']['revenue'] ?? 0);
        $fb = (float) ($core['breakdown']['fb_direct']['revenue'] ?? 0);
        $net = (float) ($core['revenue'] ?? 0);
        $other = max(0.0, round($net - $rooms - $fb, 2));

        $slices = [
            ['key' => 'rooms', 'label' => 'Rooms', 'value' => $rooms],
            ['key' => 'fb', 'label' => 'F&B', 'value' => $fb],
            ['key' => 'other', 'label' => 'Other charges', 'value' => $other],
        ];

        $base = max($rooms + $fb + $other, 1.0);

        $result = array_map(function (array $slice) use ($base) {
            return [
                'key' => $slice['key'],
                'label' => $slice['label'],
                'value' => $slice['value'],
                'pct' => round(($slice['value'] / $base) * 100, 1),
            ];
        }, array_values(array_filter($slices, fn(array $s) => $s['value'] > 0 || $s['key'] === 'rooms' || $s['key'] === 'fb')));

        $taxes = round(
            (float) ($core['breakdown']['rooms']['taxes_estimated'] ?? 0)
                + (float) ($core['breakdown']['fb_meta']['tax_amount'] ?? 0),
            2,
        );

        if ($taxes > 0) {
            $result[] = [
                'key' => 'taxes',
                'label' => 'Taxes (included in gross)',
                'value' => $taxes,
                'pct' => 0,
                'informational' => true,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $core
     * @return array<string, float>
     */
    private function financialInsights(array $core): array
    {
        $rooms = $core['breakdown']['rooms'];
        $fb = $core['breakdown']['fb_direct'];
        $fbMeta = $core['breakdown']['fb_meta'] ?? [];

        $discounts = round((float) ($rooms['discounts'] ?? 0) + (float) ($fbMeta['discount_amount'] ?? 0), 2);
        $refunds = round((float) ($rooms['refunds'] ?? 0) + (float) ($fb['total_refunded'] ?? 0), 2);
        $taxes = round((float) ($rooms['taxes_estimated'] ?? 0) + (float) ($fbMeta['tax_amount'] ?? 0), 2);

        return [
            'gross_sales' => (float) $core['total_sales'],
            'discounts' => $discounts,
            'refunds' => $refunds,
            'taxes_collected' => $taxes,
            'net_revenue' => (float) $core['revenue'],
            'food_cost' => (float) $core['food_cost'],
            'profit' => (float) $core['profit'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function bookingPerformance(string $from, string $to, User $user): array
    {
        if (! $this->canReadRooms($user)) {
            return [
                'arrivals' => 0,
                'departures' => 0,
                'in_house' => 0,
                'walk_ins' => 0,
                'no_shows' => 0,
                'cancellations' => 0,
            ];
        }

        $today = Carbon::today()->toDateString();

        $arrivals = (int) Booking::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->where(function ($q) use ($from, $to) {
                $q->whereDate('check_in', '>=', $from)->whereDate('check_in', '<=', $to);
            })
            ->count();

        $departures = (int) Booking::query()
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->where(function ($q) use ($from, $to) {
                $q->whereDate('check_out', '>=', $from)->whereDate('check_out', '<=', $to);
            })
            ->count();

        $inHouse = (int) Booking::query()
            ->where('status', '=', 'checked_in')
            ->count();

        $walkIns = (int) Booking::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->where(function ($q) use ($from, $to) {
                $q->whereDate('check_in', '>=', $from)->whereDate('check_in', '<=', $to);
            })
            ->where(function ($q) {
                $q->where('booking_source', '=', 'walk-in')
                    ->orWhere('booking_source', '=', 'walk_in');
            })
            ->count();

        $noShows = (int) Booking::query()
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereDate('check_in', '>=', $from)
            ->whereDate('check_in', '<=', $to)
            ->whereDate('check_in', '<', $today)
            ->whereNull('check_in_at')
            ->count();

        $cancellations = (int) Booking::query()
            ->where('status', '=', 'cancelled')
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereDate('check_in', '>=', $from)->whereDate('check_in', '<=', $to);
                })->orWhere(function ($q2) use ($from, $to) {
                    $q2->whereDate('updated_at', '>=', $from)->whereDate('updated_at', '<=', $to);
                });
            })
            ->count();

        return [
            'arrivals' => $arrivals,
            'departures' => $departures,
            'in_house' => $inHouse,
            'walk_ins' => $walkIns,
            'no_shows' => $noShows,
            'cancellations' => $cancellations,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function roomSnapshot(string $date, User $user): array
    {
        if (! $this->canReadRooms($user)) {
            return [
                'total' => 0,
                'occupied' => 0,
                'reserved' => 0,
                'vacant' => 0,
                'out_of_order' => 0,
                'hk_pending' => 0,
                'inspected' => 0,
            ];
        }

        $day = Carbon::parse($date);
        $dayStartAt = $day->copy()->startOfDay();
        $dayEndAt = $day->copy()->addDay()->startOfDay();

        $rooms = Room::with(['statusBlocks' => function ($q) use ($date) {
            $d = Carbon::parse($date)->toDateString();
            $q->where('is_active', true)
                ->where('start_date', '<=', $d)
                ->where('end_date', '>', $d);
        }, 'segments' => function ($q) use ($dayStartAt, $dayEndAt) {
            $q->where('status', '!=', 'cancelled')
                ->where('check_in_at', '<', $dayEndAt)
                ->where('check_out_at', '>', $dayStartAt);
        }, 'segments.booking'])->get();

        $counts = [
            'total' => $rooms->count(),
            'occupied' => 0,
            'reserved' => 0,
            'vacant' => 0,
            'out_of_order' => 0,
            'hk_pending' => 0,
            'inspected' => 0,
        ];

        foreach ($rooms as $room) {
            if ($room->segments->isNotEmpty()) {
                $isCheckedIn = $room->segments->contains(function ($seg) {
                    $bStatus = $seg->booking?->status;

                    return $bStatus === 'checked_in' || $seg->status === 'checked_in';
                });

                if ($isCheckedIn) {
                    $counts['occupied']++;
                } else {
                    $counts['reserved']++;
                }
            } elseif ($room->statusBlocks->isNotEmpty()) {
                $st = $room->statusBlocks->first()->status;
                if ($st === 'maintenance' || $st === 'on_hold') {
                    $counts['out_of_order']++;
                } elseif ($st === 'inspected') {
                    $counts['inspected']++;
                } elseif (in_array($st, ['dirty', 'cleaning', 'pending_inspection'], true)) {
                    $counts['hk_pending']++;
                } else {
                    $counts['vacant']++;
                }
            } else {
                $counts['vacant']++;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $core
     * @return array<string, float|int>
     */
    private function hospitalityMetrics(string $from, string $to, array $core, User $user): array
    {
        $rooms = $core['breakdown']['rooms'];
        $roomPerf = $this->roomSnapshot($to, $user);
        $totalRooms = max(1, (int) ($roomPerf['total'] ?? 1));
        $occupied = (int) ($roomPerf['occupied'] ?? 0);
        $roomRevenue = (float) ($rooms['revenue'] ?? 0);
        $roomNights = (int) ($rooms['room_nights'] ?? 0);
        if ($roomNights <= 0 && $roomRevenue > 0) {
            $roomNights = max(1, (int) ($rooms['payments_count'] ?? $rooms['check_ins'] ?? 1));
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = max(1, (int) $start->diffInDays($end) + 1);

        $occupancyPct = round(($occupied / $totalRooms) * 100, 1);
        $adr = $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : 0.0;
        $revpar = round($roomRevenue / ($totalRooms * $days), 2);

        $avgLos = 0.0;
        $guestCount = 0;
        $repeatPct = 0.0;

        if ($this->canReadRooms($user)) {
            $checkoutBookings = Booking::query()
                ->where('status', '=', 'checked_out')
                ->where(function ($q) use ($from, $to) {
                    $q->where(function ($q2) use ($from, $to) {
                        $q2->whereNotNull('check_out_at')
                            ->whereDate('check_out_at', '>=', $from)
                            ->whereDate('check_out_at', '<=', $to);
                    })->orWhere(function ($q2) use ($from, $to) {
                        $q2->whereNull('check_out_at')
                            ->whereDate('check_out', '>=', $from)
                            ->whereDate('check_out', '<=', $to);
                    });
                })
                ->get(['check_in', 'check_out', 'adults_count', 'children_count', 'email']);

            if ($checkoutBookings->isNotEmpty()) {
                $losSum = 0;
                foreach ($checkoutBookings as $b) {
                    $losSum += $this->bookingRoomNights($b);
                    $guestCount += (int) ($b->adults_count ?? 0) + (int) ($b->children_count ?? 0);
                }
                $avgLos = round($losSum / $checkoutBookings->count(), 1);
            }

            $inHouseGuests = Booking::query()
                ->where('status', '=', 'checked_in')
                ->get(['adults_count', 'children_count', 'email']);
            if ($guestCount === 0) {
                foreach ($inHouseGuests as $b) {
                    $guestCount += (int) ($b->adults_count ?? 0) + (int) ($b->children_count ?? 0);
                }
            }

            $emails = $checkoutBookings->pluck('email')->filter()->map(fn($e) => strtolower(trim((string) $e)))->unique()->values();
            if ($emails->isNotEmpty()) {
                $repeat = 0;
                foreach ($emails as $email) {
                    $count = Booking::query()
                        ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                        ->whereIn('status', ['checked_in', 'checked_out'])
                        ->count();
                    if ($count > 1) {
                        $repeat++;
                    }
                }
                $repeatPct = round(($repeat / $emails->count()) * 100, 1);
            }
        }

        return [
            'occupancy_pct' => $occupancyPct,
            'occupied_rooms' => $occupied,
            'total_rooms' => $totalRooms,
            'adr' => $adr,
            'revpar' => $revpar,
            'avg_length_of_stay' => $avgLos,
            'guest_count' => $guestCount,
            'repeat_guest_pct' => $repeatPct,
        ];
    }

    private function pctChange(float $current, float $previous): ?float
    {
        if ($previous <= 0.004) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param array<string, mixed> $core
     * @param array<string, mixed> $previousCore
     * @return array<string, mixed>
     */
    private function buildKpis(array $core, array $previousCore, string $comparisonLabel, User $user): array
    {
        $rooms = $core['breakdown']['rooms'];
        $fb = $core['breakdown']['fb_direct'];
        $hospitality = $core['hospitality'] ?? [];
        $sparkline = array_map(fn(array $p) => (float) $p['revenue'], $core['sparkline'] ?? []);

        $prevRooms = $previousCore['breakdown']['rooms'];
        $prevFb = $previousCore['breakdown']['fb_direct'];
        $prevHospitality = $this->hospitalityMetrics(
            $previousCore['from'],
            $previousCore['to'],
            $previousCore,
            $user,
        );

        return [
            'total_revenue' => [
                'value' => (float) $core['revenue'],
                'change_pct' => $this->pctChange((float) $core['revenue'], (float) $previousCore['revenue']),
                'sparkline' => $sparkline,
            ],
            'room_revenue' => [
                'value' => (float) $rooms['revenue'],
                'check_ins' => (int) ($rooms['check_ins'] ?? 0),
                'change_pct' => $this->pctChange((float) $rooms['revenue'], (float) $prevRooms['revenue']),
            ],
            'fb_revenue' => [
                'value' => (float) $fb['revenue'],
                'orders_count' => (int) ($fb['orders_count'] ?? 0),
                'change_pct' => $this->pctChange((float) $fb['revenue'], (float) $prevFb['revenue']),
            ],
            'profit' => [
                'value' => (float) $core['profit'],
                'margin_pct' => (float) $core['profit_margin_pct'],
                'change_pct' => $this->pctChange((float) $core['profit'], (float) $previousCore['profit']),
            ],
            'occupancy' => [
                'occupied' => (int) ($hospitality['occupied_rooms'] ?? 0),
                'total' => (int) ($hospitality['total_rooms'] ?? 0),
                'pct' => (float) ($hospitality['occupancy_pct'] ?? 0),
                'change_pct' => $this->pctChange(
                    (float) ($hospitality['occupancy_pct'] ?? 0),
                    (float) ($prevHospitality['occupancy_pct'] ?? 0),
                ),
            ],
            'adr' => [
                'value' => (float) ($hospitality['adr'] ?? 0),
                'change_pct' => $this->pctChange(
                    (float) ($hospitality['adr'] ?? 0),
                    (float) ($prevHospitality['adr'] ?? 0),
                ),
            ],
            'comparison_label' => $comparisonLabel,
        ];
    }
}
