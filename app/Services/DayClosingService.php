<?php

namespace App\Services;

use App\Models\PosDayClosing;
use App\Models\PosOrder;
use App\Models\RestaurantMaster;
use App\Models\RestaurantTable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Outlet day-close policy: sequential dates, checklist gates, last-closed lookup.
 */
class DayClosingService
{
    public const PERMISSION_OVERRIDE_SEQUENTIAL = 'pos-day-closing-override';

    public function lastClosedDate(int $restaurantId): ?string
    {
        $date = PosDayClosing::query()
            ->where('restaurant_id', $restaurantId)
            ->max('closed_date');

        if (! $date) {
            return null;
        }

        return $date instanceof \Carbon\CarbonInterface
            ? $date->format('Y-m-d')
            : (string) $date;
    }

    /**
     * Next business date that must be closed before trading on or closing a later date.
     *
     * @return array{ok: bool, last_closed: ?string, required_next: ?string, message: ?string}
     */
    public function sequentialCloseStatus(int $restaurantId, string $targetBusinessDate): array
    {
        $target = Carbon::parse($targetBusinessDate)->startOfDay();
        $lastClosed = $this->lastClosedDate($restaurantId);

        if ($lastClosed === null) {
            return [
                'ok' => true,
                'last_closed' => null,
                'required_next' => null,
                'message' => null,
            ];
        }

        $requiredNext = Carbon::parse($lastClosed)->addDay()->startOfDay();

        if ($target->lte($requiredNext)) {
            return [
                'ok' => true,
                'last_closed' => $lastClosed,
                'required_next' => $requiredNext->toDateString(),
                'message' => null,
            ];
        }

        return [
            'ok' => false,
            'last_closed' => $lastClosed,
            'required_next' => $requiredNext->toDateString(),
            'message' => "Business date {$requiredNext->toDateString()} must be closed before "
                ."working on or closing {$target->toDateString()}.",
        ];
    }

    public function userMayOverrideSequential(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->hasRole('Admin')
            || $user->hasRole('Super Admin')
            || $user->can(self::PERMISSION_OVERRIDE_SEQUENTIAL);
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function assertSequentialOrAbort(int $restaurantId, string $targetBusinessDate): void
    {
        $status = $this->sequentialCloseStatus($restaurantId, $targetBusinessDate);
        if ($status['ok'] || $this->userMayOverrideSequential()) {
            return;
        }

        abort(422, $status['message'] ?? 'Prior business date must be closed first.');
    }

    /**
     * @return array{
     *     items: list<array{key: string, label: string, status: string, count: int, blocking: bool, detail?: string}>,
     *     can_close: bool
     * }
     */
    public function buildChecklist(
        int $restaurantId,
        string $closedDate,
        array $summary,
        array $inventoryPrecheck,
        bool $alreadyClosedRecord,
    ): array {
        $sequential = $this->sequentialCloseStatus($restaurantId, $closedDate);
        $sequentialOk = $sequential['ok'] || $this->userMayOverrideSequential();

        $openBilled = (int) ($summary['open_billed_count'] ?? 0);
        $kotPending = $this->countKotPendingOnOpenOrders($restaurantId, $closedDate);
        $openTables = $this->countOccupiedTables($restaurantId);
        $negStock = (int) ($inventoryPrecheck['negative_stock']['count'] ?? 0);
        $pendingReq = (int) ($inventoryPrecheck['pending_requisitions']['count'] ?? 0);
        $invOk = (bool) ($inventoryPrecheck['can_close'] ?? true);

        $items = [
            [
                'key' => 'sequential',
                'label' => 'Prior business dates closed',
                'status' => $sequentialOk ? 'pass' : 'fail',
                'count' => $sequentialOk ? 0 : 1,
                'blocking' => true,
                'detail' => $sequentialOk
                    ? ($sequential['last_closed']
                        ? "Last closed: {$sequential['last_closed']}. Next expected: {$sequential['required_next']}."
                        : 'No prior close on record.')
                    : ($sequential['message'] ?? 'Close earlier business dates first.'),
            ],
            [
                'key' => 'open_billed',
                'label' => 'Open / billed unpaid orders',
                'status' => $openBilled === 0 ? 'pass' : 'fail',
                'count' => $openBilled,
                'blocking' => true,
                'detail' => $openBilled === 0
                    ? 'All orders settled or voided.'
                    : 'Settle, void, or re-open and fix unpaid bills.',
            ],
            [
                'key' => 'kot_pending',
                'label' => 'KOT pending (open orders)',
                'status' => $kotPending === 0 ? 'pass' : 'warn',
                'count' => $kotPending,
                'blocking' => false,
                'detail' => $kotPending === 0
                    ? 'No unsent or in-kitchen KOT lines on open orders.'
                    : 'Kitchen tickets still active on open orders (blocked by open orders gate).',
            ],
            [
                'key' => 'open_tables',
                'label' => 'Occupied tables',
                'status' => $openTables === 0 ? 'pass' : ($openBilled > 0 ? 'fail' : 'warn'),
                'count' => $openTables,
                'blocking' => false,
                'detail' => $openTables === 0
                    ? 'No tables marked occupied.'
                    : 'Tables still occupied — verify orders are settled.',
            ],
            [
                'key' => 'negative_stock',
                'label' => 'Negative inventory',
                'status' => $negStock === 0 ? 'pass' : 'fail',
                'count' => $negStock,
                'blocking' => true,
                'detail' => $negStock === 0
                    ? 'No negative stock at kitchen/bar stores.'
                    : 'Fix stock before close.',
            ],
            [
                'key' => 'pending_requisitions',
                'label' => 'Pending store requests',
                'status' => $pendingReq === 0 ? 'pass' : 'fail',
                'count' => $pendingReq,
                'blocking' => true,
                'detail' => $pendingReq === 0
                    ? 'No pending requisitions or transfers.'
                    : 'Complete or cancel pending store requests.',
            ],
        ];

        $blockingFail = collect($items)->contains(
            fn ($i) => $i['blocking'] && $i['status'] === 'fail'
        );

        return [
            'items' => $items,
            'can_close' => ! $blockingFail && $invOk,
            'sequential' => $sequential,
            'override_allowed' => $this->userMayOverrideSequential(),
        ];
    }

    private function countKotPendingOnOpenOrders(int $restaurantId, string $businessDate): int
    {
        return (int) DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->join('menu_items', 'pos_order_items.menu_item_id', '=', 'menu_items.id')
            ->where('pos_orders.restaurant_id', $restaurantId)
            ->whereIn('pos_orders.status', ['open', 'billed'])
            ->where('pos_order_items.status', 'active')
            ->where(function ($q) use ($businessDate) {
                $q->whereDate('pos_orders.business_date', $businessDate)
                    ->orWhere(function ($legacy) use ($businessDate) {
                        $legacy->whereNull('pos_orders.business_date')
                            ->whereDate('pos_orders.opened_at', $businessDate);
                    });
            })
            ->where(function ($q) {
                $q->where('menu_items.requires_production', true)
                    ->where(function ($kot) {
                        $kot->where('pos_order_items.kot_sent', false)
                            ->orWhereNull('pos_order_items.kitchen_ready_at');
                    });
            })
            ->count();
    }

    private function countOccupiedTables(int $restaurantId): int
    {
        return (int) RestaurantTable::query()
            ->where('restaurant_master_id', $restaurantId)
            ->where('status', 'occupied')
            ->count();
    }
}
