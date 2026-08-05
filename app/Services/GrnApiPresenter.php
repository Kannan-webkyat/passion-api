<?php

namespace App\Services;

use App\Models\GRN;
use Illuminate\Support\Collection;

/**
 * Attaches read-only {@see GrnItemCostSnapshot} to GRN API payloads.
 */
final class GrnApiPresenter
{
    public static function withCostSnapshots(GRN $grn): GRN
    {
        $grn->loadMissing('items');
        self::attachSnapshotsToItems($grn->items, $grn->inventory_costing_mode);

        return $grn;
    }

    /**
     * @param  Collection<int, GRN>|array<int, GRN>  $grns
     */
    public static function withCostSnapshotsOnMany(Collection|array $grns): Collection|array
    {
        foreach ($grns as $grn) {
            self::withCostSnapshots($grn);
        }

        return $grns;
    }

    /**
     * @param  iterable<int, \App\Models\GrnItem>  $items
     */
    public static function attachSnapshotsToItems(iterable $items, ?string $grnCostingMode): void
    {
        foreach ($items as $item) {
            $item->setAttribute(
                'cost_snapshot',
                GrnItemCostSnapshot::fromGrnItem($item, $grnCostingMode)
            );
        }
    }
}
