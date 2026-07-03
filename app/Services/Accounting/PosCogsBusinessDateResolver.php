<?php

namespace App\Services\Accounting;

use App\Models\PosOrder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves POS order business_date from inventory transaction reference fields.
 */
final class PosCogsBusinessDateResolver
{
    public function fromReference(?string $refType, ?string $refId): ?string
    {
        if (! $refType || ! $refId) {
            return null;
        }

        return match ($refType) {
            'pos_order' => PosOrder::query()->where('id', (int) $refId)->value('business_date'),
            'pos_order_batch' => $this->fromBatchReference($refId),
            'pos_order_line_ready' => $this->fromOrderItemReference((int) $refId),
            'pos_order_void', 'pos_order_line_void', 'pos_order_item_void', 'pos_order_sync_cancel', 'pos_order_sync_reduce' => $this->fromOrderItemReferenceByOrder((int) $refId),
            default => null,
        };
    }

    /**
     * Scope inventory transactions to outlet business_date range (POS-linked only).
     *
     * @param  array<int>  $outletIds
     */
    public function applyBusinessDateScope($query, array $outletIds, string $from, string $to)
    {
        return $query->where(function ($outer) use ($outletIds, $from, $to) {
            $outer->where(function ($q) use ($outletIds, $from, $to) {
                $q->where('reference_type', 'pos_order')
                    ->whereIn('reference_id', function ($sub) use ($outletIds, $from, $to) {
                        $sub->select('id')
                            ->from('pos_orders')
                            ->whereIn('restaurant_id', $outletIds)
                            ->whereDate('business_date', '>=', $from)
                            ->whereDate('business_date', '<=', $to);
                    });
            })->orWhere(function ($q) use ($outletIds, $from, $to) {
                $q->where('reference_type', 'pos_order_batch')
                    ->whereExists(function ($sub) use ($outletIds, $from, $to) {
                        $sub->select(DB::raw(1))
                            ->from('pos_orders')
                            ->whereIn('restaurant_id', $outletIds)
                            ->whereDate('business_date', '>=', $from)
                            ->whereDate('business_date', '<=', $to)
                            ->whereRaw(
                                'pos_orders.id = CAST(SUBSTRING_INDEX(inventory_transactions.reference_id, \'-\', 1) AS UNSIGNED)'
                            );
                    });
            })->orWhere(function ($q) use ($outletIds, $from, $to) {
                $q->where('reference_type', 'pos_order_line_ready')
                    ->whereIn('reference_id', function ($sub) use ($outletIds, $from, $to) {
                        $sub->select('pos_order_items.id')
                            ->from('pos_order_items')
                            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
                            ->whereIn('pos_orders.restaurant_id', $outletIds)
                            ->whereDate('pos_orders.business_date', '>=', $from)
                            ->whereDate('pos_orders.business_date', '<=', $to);
                    });
            })->orWhere(function ($q) use ($outletIds, $from, $to) {
                $q->whereIn('reference_type', [
                    'pos_order_void',
                    'pos_order_line_void',
                    'pos_order_item_void',
                    'pos_order_sync_cancel',
                    'pos_order_sync_reduce',
                ])
                    ->whereIn('reference_id', function ($sub) use ($outletIds, $from, $to) {
                        $sub->select('id')
                            ->from('pos_orders')
                            ->whereIn('restaurant_id', $outletIds)
                            ->whereDate('business_date', '>=', $from)
                            ->whereDate('business_date', '<=', $to);
                    });
            });
        });
    }

    private function fromBatchReference(string $refId): ?string
    {
        $orderId = (int) explode('-', $refId, 2)[0];
        if ($orderId <= 0) {
            return null;
        }

        return PosOrder::query()->where('id', $orderId)->value('business_date');
    }

    private function fromOrderItemReference(int $orderItemId): ?string
    {
        $date = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_order_items.id', $orderItemId)
            ->value('pos_orders.business_date');

        return $date ? (string) $date : null;
    }

    private function fromOrderItemReferenceByOrder(int $orderId): ?string
    {
        return PosOrder::query()->where('id', $orderId)->value('business_date');
    }
}
