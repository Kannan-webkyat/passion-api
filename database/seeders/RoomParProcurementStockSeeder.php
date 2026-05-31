<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\PurchaseOrder;
use App\Models\Room;
use App\Models\RoomParTemplateLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseOrderLineAmounts;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Seeder;

/**
 * Seeds Main Store stock for room-PAR items via a real purchase order (create → send → receive).
 * Computes order qty from assigned room templates + buffer; sets WAC/cost via PO receipt.
 *
 * Requires: LocationSeeder, HotelInventoryCatalogSeeder, RoomParTestTemplatesSeeder (recommended).
 * Idempotent: skips if a received PO with marker notes already exists.
 */
class RoomParProcurementStockSeeder extends Seeder
{
    public const SEED_MARKER = 'Room PAR procurement seed';

    /**
     * @return array{0: float, 1: float, 2: string} unit_price (exclusive), tax_rate, tax_price_basis
     */
    private function pricingForItem(InventoryItem $item, string $kind): array
    {
        $name = strtolower((string) $item->name);
        $sku = (string) $item->sku;

        $table = [
            'shampoo' => 18.00,
            'conditioner' => 18.00,
            'bar soap' => 8.00,
            'liquid soap' => 12.00,
            'dental kit' => 22.00,
            'shaving kit' => 28.00,
            'coffee sachet' => 4.50,
            'tea bag' => 3.00,
            'bottled water' => 12.00,
            'notepad' => 15.00,
            'pen' => 8.00,
            'soda' => 28.00,
            'juice' => 35.00,
            'chocolate' => 45.00,
            'sparkling water' => 22.00,
            'alcohol miniature' => 180.00,
            'nuts' => 55.00,
            'crackers' => 40.00,
            'cup noodles' => 38.00,
            'electric kettle' => 850.00,
            'hair dryer' => 720.00,
            'iron' => 650.00,
            'ironing board' => 480.00,
            'clothes hanger' => 35.00,
            'coffee maker' => 1200.00,
            'mini-fridge' => 4500.00,
            'television' => 8500.00,
            'safe-deposit box' => 2200.00,
            'luggage rack' => 380.00,
        ];

        $unitPrice = null;
        foreach ($table as $needle => $price) {
            if (str_contains($name, $needle)) {
                $unitPrice = $price;
                break;
            }
        }

        if ($unitPrice === null) {
            $unitPrice = match (true) {
                str_starts_with($sku, 'GA_') => 12.00,
                str_starts_with($sku, 'MB_') => 35.00,
                str_starts_with($sku, 'FA_') => 750.00,
                default => 25.00,
            };
        }

        $taxRate = match ($kind) {
            'asset' => 18.0,
            default => 12.0,
        };

        return [$unitPrice, $taxRate, PurchaseOrderLineAmounts::BASIS_EXCLUSIVE];
    }

    /**
     * @return array<int, array{qty: float, kind: string}>
     */
    private function computeOrderQuantities(): array
    {
        $rooms = Room::query()
            ->whereNotNull('par_template_id')
            ->with(['parTemplate.lines'])
            ->get(['id', 'par_template_id']);

        $roomParTotalByItem = [];
        $kindByItem = [];

        if ($rooms->isNotEmpty()) {
            foreach ($rooms as $room) {
                $template = $room->parTemplate;
                if (! $template) {
                    continue;
                }
                foreach ($template->lines as $line) {
                    $itemId = (int) $line->inventory_item_id;
                    $roomParTotalByItem[$itemId] = ($roomParTotalByItem[$itemId] ?? 0) + (float) ($line->par_qty ?? 0);
                    $kindByItem[$itemId] = (string) ($line->kind ?? 'amenity');
                }
            }
        } else {
            $lines = RoomParTemplateLine::query()->get(['inventory_item_id', 'par_qty', 'kind']);
            foreach ($lines as $line) {
                $itemId = (int) $line->inventory_item_id;
                $roomParTotalByItem[$itemId] = ($roomParTotalByItem[$itemId] ?? 0) + (float) ($line->par_qty ?? 0);
                $kindByItem[$itemId] = (string) ($line->kind ?? 'amenity');
            }
        }

        if ($roomParTotalByItem === []) {
            $items = InventoryItem::query()
                ->where(function ($q) {
                    $q->where('sku', 'like', 'GA\_%', 'and')
                        ->orWhere('sku', 'like', 'MB\_%')
                        ->orWhere('sku', 'like', 'FA\_%');
                })
                ->get(['id', 'sku']);

            foreach ($items as $item) {
                $sku = (string) $item->sku;
                $kind = str_starts_with($sku, 'FA_') ? 'asset' : (str_starts_with($sku, 'MB_') ? 'minibar' : 'amenity');
                $roomParTotalByItem[(int) $item->id] = match ($kind) {
                    'asset' => 2.0,
                    'minibar' => 5.0,
                    default => 10.0,
                };
                $kindByItem[(int) $item->id] = $kind;
            }
        }

        $roomCount = max(1, $rooms->count() ?: Room::whereNotNull('par_template_id')->count() ?: 15);
        $orderByItem = [];

        foreach ($roomParTotalByItem as $itemId => $parTotal) {
            $kind = $kindByItem[$itemId] ?? 'amenity';
            $orderQty = match ($kind) {
                'asset' => max(
                    (int) ceil($parTotal + 5),
                    (int) ceil($roomCount / 2) + 3
                ),
                'minibar' => (int) ceil(max($parTotal, 1) * 3),
                default => (int) ceil(max($parTotal, 1) * 4),
            };
            $orderByItem[(int) $itemId] = [
                'qty' => (float) max(1, $orderQty),
                'kind' => $kind,
            ];
        }

        return $orderByItem;
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
            ->where('status', '=', 'received', 'and')
            ->exists()
        ) {
            $this->command?->info('Room PAR procurement PO already seeded — skipped.');

            return;
        }

        $orderByItem = $this->computeOrderQuantities();
        if ($orderByItem === []) {
            $this->command?->warn('No room PAR items found to order.');

            return;
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

        $items = InventoryItem::query()
            ->whereIn('id', array_keys($orderByItem), 'and', false)
            ->get(['id', 'name', 'sku']);

        $poLines = [];
        foreach ($items as $item) {
            $meta = $orderByItem[(int) $item->id] ?? null;
            if (! $meta) {
                continue;
            }
            [$unitPrice, $taxRate, $basis] = $this->pricingForItem($item, $meta['kind']);
            $poLines[] = [
                'inventory_item_id' => (int) $item->id,
                'quantity' => $meta['qty'],
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'tax_price_basis' => $basis,
            ];
        }

        if ($poLines === []) {
            $this->command?->warn('No PO lines built for room PAR items.');

            return;
        }

        $adminId = User::where('email', 'admin@hotel.com')->value('id');

        $validated = [
            'vendor_id' => (int) $vendor->id,
            'location_id' => (int) $mainStore->id,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => self::SEED_MARKER . ' — auto-generated for room PAR restock testing.',
            'items' => $poLines,
        ];

        try {
            $po = app(PurchaseOrderService::class)->createFromValidatedData(
                $validated,
                null,
                'sent'
            );

            app(PurchaseOrderService::class)->receivePurchaseOrder(
                $po,
                (int) $mainStore->id,
                $adminId ? (int) $adminId : null
            );
        } catch (\Throwable $e) {
            $this->command?->error('Room PAR procurement seed failed: ' . $e->getMessage());
            throw $e;
        }

        $po->refresh();
        $lineCount = count($poLines);
        $this->command?->info(sprintf(
            'Room PAR stock seeded via %s (%d lines, ₹%s received into %s).',
            $po->po_number,
            $lineCount,
            number_format((float) $po->total_amount, 2),
            $mainStore->name
        ));
    }
}
