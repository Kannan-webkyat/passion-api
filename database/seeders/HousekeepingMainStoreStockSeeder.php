<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\PurchaseOrder;
use App\Models\Room;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GrnService;
use App\Services\PurchaseOrderLineAmounts;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Opening Main Store stock for the housekeeping catalog, received through a real
 * purchase order → GRN (submit, inspect, approve), so stock, WAC cost, cost layers
 * and the GRN journal are all posted the normal way.
 *
 * Quantities and prices: data/housekeeping_main_store_stock.php. Items used on room PAR
 * templates are topped up so the store covers the rooms' PAR with a buffer.
 *
 * Requires: LocationSeeder, HousekeepingInventorySeeder, RoomParTestTemplatesSeeder (optional).
 * Idempotent: skipped once a received PO with the marker note exists.
 */
class HousekeepingMainStoreStockSeeder extends Seeder
{
    public const SEED_MARKER = 'Housekeeping main store opening stock seed';

    /**
     * Linen that rotates through the laundry (in room, in wash, on the shelf).
     * Pillows, duvets, cushions and runners are not in the wash cycle.
     */
    private const LAUNDERED_LINEN_SKUS = [
        'HK-LIN-001', 'HK-LIN-002', 'HK-LIN-003', 'HK-LIN-004', 'HK-LIN-005', 'HK-LIN-006',
        'HK-LIN-008', 'HK-LIN-009', 'HK-LIN-013', 'HK-LIN-016', 'HK-LIN-017', 'HK-LIN-018',
        'HK-LIN-019',
    ];

    /**
     * Store cover per kind, as a multiple of the total room PAR (in issue units).
     * Laundered linen needs three cycles; other assets only need a few spares.
     */
    private function parFloorInIssueUnits(string $sku, string $kind, float $roomParTotal): float
    {
        if (in_array($sku, self::LAUNDERED_LINEN_SKUS, true)) {
            return $roomParTotal * 3;
        }

        return match ($kind) {
            'amenity' => $roomParTotal * 4,
            'minibar' => $roomParTotal * 3,
            default => $roomParTotal + 2,
        };
    }

    /**
     * @return array<int, array{qty: float, kind: string}>
     */
    private function roomParTotalsByItem(): array
    {
        $totals = [];
        $rooms = Room::query()
            ->whereNotNull('par_template_id')
            ->with('parTemplate.lines')
            ->get(['id', 'par_template_id']);

        foreach ($rooms as $room) {
            foreach ($room->parTemplate?->lines ?? [] as $line) {
                $itemId = (int) $line->inventory_item_id;
                $totals[$itemId] = [
                    'qty' => ($totals[$itemId]['qty'] ?? 0) + (float) $line->par_qty,
                    'kind' => (string) $line->kind,
                ];
            }
        }

        return $totals;
    }

    public function run(): void
    {
        $mainStore = InventoryLocation::where('type', '=', 'main_store', 'and')->first();
        if (! $mainStore) {
            $this->command?->warn('Main Store location missing — run LocationSeeder first.');

            return;
        }

        if (
            PurchaseOrder::query()
                ->where('notes', 'like', '%' . self::SEED_MARKER . '%')
                ->whereIn('status', ['received', 'partial'])
                ->exists()
        ) {
            $this->command?->info('Housekeeping main store stock already seeded — skipped.');

            return;
        }

        $stock = require __DIR__ . '/data/housekeeping_main_store_stock.php';
        $items = InventoryItem::query()
            ->whereIn('sku', array_keys($stock))
            ->get(['id', 'sku', 'name', 'conversion_factor'])
            ->keyBy('sku');

        $missing = array_diff(array_keys($stock), $items->keys()->all());
        if ($missing !== []) {
            $this->command?->warn(
                'Missing housekeeping SKUs (run HousekeepingInventorySeeder first): '
                    . implode(', ', $missing)
            );
        }
        if ($items->isEmpty()) {
            return;
        }

        $parTotals = $this->roomParTotalsByItem();
        $toppedUp = 0;
        $poLines = [];

        foreach ($stock as $sku => [$qty, $unitPrice]) {
            $item = $items->get($sku);
            if (! $item) {
                continue;
            }

            $orderQty = (float) $qty;
            $par = $parTotals[(int) $item->id] ?? null;
            if ($par !== null) {
                $factor = max(1.0, (float) ($item->conversion_factor ?: 1));
                $floorQty = ceil($this->parFloorInIssueUnits($sku, $par['kind'], $par['qty']) / $factor);
                if ($floorQty > $orderQty) {
                    $orderQty = $floorQty;
                    $toppedUp++;
                }
            }

            $poLines[] = [
                'inventory_item_id' => (int) $item->id,
                'quantity' => $orderQty,
                'unit_price' => (float) $unitPrice,
                'tax_price_basis' => PurchaseOrderLineAmounts::BASIS_EXCLUSIVE,
            ];
        }

        $vendor = Vendor::firstOrCreate(
            ['name' => 'Housekeeping & Room Supplies Co'],
            [
                'contact_person' => 'Procurement Desk',
                'phone' => '9876512345',
                'email' => 'procurement@roomsupplies.test',
                'address' => 'Industrial Estate, Chennai, Tamil Nadu',
                'gstin' => '33AABCU9603R1ZM',
                'state' => 'Tamil Nadu',
                'is_registered_dealer' => true,
                'default_tax_price_basis' => PurchaseOrderLineAmounts::BASIS_EXCLUSIVE,
            ]
        );

        $adminId = User::where('email', 'admin@hotel.com')->value('id');
        $adminId = $adminId ? (int) $adminId : null;

        $po = DB::transaction(function () use ($vendor, $mainStore, $poLines, $adminId) {
            $po = app(PurchaseOrderService::class)->createFromValidatedData([
                'vendor_id' => (int) $vendor->id,
                'location_id' => (int) $mainStore->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->toDateString(),
                'notes' => self::SEED_MARKER . ' — opening stock for housekeeping items.',
                'items' => $poLines,
            ], null, 'sent');

            app(GrnService::class)->receivePurchaseOrderLegacy($po, (int) $mainStore->id, null, $adminId);

            return $po->refresh();
        });

        $this->command?->info(sprintf(
            'Housekeeping stock received into %s via %s (%d lines, %d topped up to cover room PAR, ₹%s).',
            $mainStore->name,
            $po->po_number,
            count($poLines),
            $toppedUp,
            number_format((float) $po->total_amount, 2)
        ));
    }
}
