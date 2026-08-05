<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Services\Accounting\PosCogsBusinessDateResolver;
use Illuminate\Support\Facades\DB;

/**
 * Actual consumption quantities for reconciliation reports.
 */
final class ConsumptionActualsService
{
    private const OUT_REASONS_EXCLUDED = ['Transfer', 'Internal Issue', 'Production', 'Finished Goods'];

    public function __construct(
        private readonly PosCogsBusinessDateResolver $businessDateResolver,
        private readonly BatchProductionPoolService $outletResolver,
    ) {}

    /**
     * @return array<int, float> inventory_item_id => net quantity out
     */
    public function netUsageByItem(string $from, string $to, ?int $locationId = null): array
    {
        $outletIds = $this->outletResolver->outletIdsForLocation($locationId);
        $actuals = [];

        $posOutQuery = InventoryTransaction::query()
            ->where('type', 'out')
            ->where('reason', 'POS Order')
            ->whereNotIn('reason', self::OUT_REASONS_EXCLUDED);

        if ($locationId) {
            $posOutQuery->where('inventory_location_id', $locationId);
        }

        if ($outletIds !== []) {
            $this->businessDateResolver->applyBusinessDateScope($posOutQuery, $outletIds, $from, $to);
        } else {
            $posOutQuery->whereRaw('1 = 0');
        }

        foreach ($posOutQuery->select('inventory_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id')
            ->get() as $row) {
            $actuals[(int) $row->inventory_item_id] = (float) $row->total_qty;
        }

        $nonPosOutQuery = InventoryTransaction::query()
            ->where('type', 'out')
            ->where('reason', '!=', 'POS Order')
            ->whereNotIn('reason', self::OUT_REASONS_EXCLUDED)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        if ($locationId) {
            $nonPosOutQuery->where('inventory_location_id', $locationId);
        }

        foreach ($nonPosOutQuery->select('inventory_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id')
            ->get() as $row) {
            $id = (int) $row->inventory_item_id;
            $actuals[$id] = ($actuals[$id] ?? 0) + (float) $row->total_qty;
        }

        $posReversalQuery = InventoryTransaction::query()
            ->where('type', 'in')
            ->where('reason', 'Inventory Reversal');

        if ($locationId) {
            $posReversalQuery->where('inventory_location_id', $locationId);
        }

        if ($outletIds !== []) {
            $this->businessDateResolver->applyBusinessDateScope($posReversalQuery, $outletIds, $from, $to);
        } else {
            $posReversalQuery->whereRaw('1 = 0');
        }

        foreach ($posReversalQuery->select('inventory_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id')
            ->get() as $row) {
            $id = (int) $row->inventory_item_id;
            $actuals[$id] = ($actuals[$id] ?? 0) - (float) $row->total_qty;
        }

        $nonPosReversalQuery = InventoryTransaction::query()
            ->where('type', 'in')
            ->where('reason', 'Inventory Reversal')
            ->where(function ($q) {
                $q->whereNull('reference_type')
                    ->orWhereNotIn('reference_type', [
                        'pos_order',
                        'pos_order_batch',
                        'pos_order_line_ready',
                        'pos_order_void',
                        'pos_order_line_void',
                        'pos_order_item_void',
                        'pos_order_sync_cancel',
                        'pos_order_sync_reduce',
                    ]);
            })
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        if ($locationId) {
            $nonPosReversalQuery->where('inventory_location_id', $locationId);
        }

        foreach ($nonPosReversalQuery->select('inventory_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id')
            ->get() as $row) {
            $id = (int) $row->inventory_item_id;
            $actuals[$id] = ($actuals[$id] ?? 0) - (float) $row->total_qty;
        }

        return $actuals;
    }
}
