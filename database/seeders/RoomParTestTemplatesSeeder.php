<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Room;
use App\Models\RoomParTemplate;
use App\Models\RoomParTemplateLine;
use App\Models\RoomType;
use App\Support\RoomParInventoryContext;
use Illuminate\Database\Seeder;

/**
 * One "Default" room-par template per room type, built from catalog SKUs.
 * Room Par is not limited to a housekeeping category; these lines are the standard
 * room set, and any other catalog item can be added on the Room Par screen.
 * Requires RoomTypeRoomSeeder and HousekeepingInventorySeeder.
 *
 * Re-running syncs each "Default" template to the definitions below (adds, updates and removes
 * lines). Rooms are only linked when they have no template yet.
 */
class RoomParTestTemplatesSeeder extends Seeder
{
    private const TEMPLATE_NAME = 'Default';

    /**
     * Line: [kind, sku, par qty]. Later entries with the same kind + sku override earlier ones.
     *
     * @return array<string, list<array{0: string, 1: string, 2: float|int}>>
     */
    private function templateLinesByRoomType(): array
    {
        $deluxe = [
            // Guest amenities (consumed per stay, replenished during cleaning)
            ['amenity', 'HK-AMN-001', 2], // Soap
            ['amenity', 'HK-AMN-009', 2], // Shampoo
            ['amenity', 'HK-AMN-012', 2], // Conditioner
            ['amenity', 'HK-AMN-004', 2], // Dental Kit
            ['amenity', 'HK-AMN-002', 2], // Comb
            ['amenity', 'HK-AMN-007', 2], // Shower Cap
            ['amenity', 'HK-AMN-003', 1], // Sanitary Bag
            ['amenity', 'HK-AMN-008', 1], // WC Band
            ['amenity', 'HK-AMN-019', 2], // Toilet Roll
            ['amenity', 'HK-AMN-020', 2], // Glass Cover
            ['amenity', 'HK-AMN-021', 2], // Paper Coaster
            ['amenity', 'HK-AMN-015', 1], // Laundry Bag
            ['amenity', 'HK-AMN-016', 2], // Room Slippers
            ['amenity', 'HK-GST-020', 1], // Writing Pad
            ['amenity', 'HK-GST-021', 1], // Pen
            // Minibar (charged on consumption)
            ['minibar', 'HK-MIN-006', 2], // Aquafina 500 ml
            ['minibar', 'HK-MIN-010', 1], // Pepsi
            ['minibar', 'HK-MIN-009', 1], // 7UP
            ['minibar', 'HK-MIN-001', 1], // Lay's
            ['minibar', 'HK-MIN-003', 1], // Dairy Milk
            // Linen
            ['asset', 'HK-LIN-002', 1], // Double Bedsheet
            ['asset', 'HK-LIN-006', 1], // Double Mattress Protector
            ['asset', 'HK-LIN-015', 1], // Double Duvet
            ['asset', 'HK-LIN-004', 1], // Double Duvet Cover
            ['asset', 'HK-LIN-007', 4], // Pillow
            ['asset', 'HK-LIN-008', 4], // Pillow Cover
            ['asset', 'HK-LIN-009', 4], // Pillow Protector
            ['asset', 'HK-LIN-018', 2], // Bath Towel
            ['asset', 'HK-LIN-016', 2], // Hand Towel
            ['asset', 'HK-LIN-017', 2], // Face Towel
            ['asset', 'HK-LIN-019', 1], // Bath Mat
            // Room equipment
            ['asset', 'HK-EQP-001', 1], // Hair Dryer
            ['asset', 'HK-EQP-013', 1], // Electric Kettle with Tray
            ['asset', 'HK-EQP-014', 2], // Coffee Cup
            ['asset', 'HK-EQP-015', 2], // Spoon
            ['asset', 'HK-EQP-008', 1], // Sugar Pot
            ['asset', 'HK-EQP-009', 1], // Amenities Tray
            ['asset', 'HK-EQP-010', 1], // Water Tray
            ['asset', 'HK-AMN-024', 2], // Water Glass
            ['asset', 'HK-AMN-023', 1], // Gargling Glass
            ['asset', 'HK-AMN-025', 6], // Wooden Hanger
            ['asset', 'HK-EQP-011', 1], // Open Dustbin
            ['asset', 'HK-EQP-012', 1], // Pedal Dustbin
            ['asset', 'HK-EQP-016', 1], // Bathroom Bucket and Mug
            ['asset', 'HK-EQP-018', 1], // Room Telephone
            ['asset', 'HK-GST-017', 1], // Room File
        ];

        $premium = $this->mergeLines($deluxe, [
            ['amenity', 'HK-AMN-010', 2], // Shower Gel
            ['amenity', 'HK-AMN-011', 2], // Moisturizer
            ['amenity', 'HK-AMN-005', 1], // Shaving Kit
            ['amenity', 'HK-AMN-006', 1], // Loofah
            ['amenity', 'HK-AMN-022', 1], // Tissue Box
            ['minibar', 'HK-MIN-013', 1], // Coca-Cola
            ['minibar', 'HK-MIN-012', 1], // Red Bull
            ['minibar', 'HK-MIN-002', 1], // KitKat
            ['minibar', 'HK-MIN-008', 1], // Cashew Nuts
            ['asset', 'HK-LIN-012', 2], // Cushion Pillow
            ['asset', 'HK-LIN-013', 2], // Cushion Pillow Cover
            ['asset', 'HK-LIN-011', 1], // Large Bed Runner
            ['asset', 'HK-EQP-004', 1], // Iron
            ['asset', 'HK-EQP-005', 1], // Ironing Board
            ['asset', 'HK-EQP-003', 1], // Safe Locker
            ['asset', 'HK-EQP-006', 1], // Minibar
            ['asset', 'HK-EQP-007', 1], // Minibar Basket
            ['asset', 'HK-EQP-002', 1], // Shaving Mirror
            ['asset', 'HK-EQP-019', 1], // Bathroom Telephone
            ['asset', 'HK-AMN-025', 8], // Wooden Hanger
        ]);

        $family = $this->mergeLines($deluxe, [
            ['amenity', 'HK-AMN-001', 4], // Soap
            ['amenity', 'HK-AMN-009', 4], // Shampoo
            ['amenity', 'HK-AMN-012', 4], // Conditioner
            ['amenity', 'HK-AMN-004', 4], // Dental Kit
            ['amenity', 'HK-AMN-002', 4], // Comb
            ['amenity', 'HK-AMN-007', 4], // Shower Cap
            ['amenity', 'HK-AMN-019', 3], // Toilet Roll
            ['amenity', 'HK-AMN-020', 4], // Glass Cover
            ['amenity', 'HK-AMN-021', 4], // Paper Coaster
            ['amenity', 'HK-AMN-016', 4], // Room Slippers
            ['amenity', 'HK-AMN-022', 1], // Tissue Box
            ['amenity', 'HK-GST-021', 2], // Pen
            ['minibar', 'HK-MIN-006', 4], // Aquafina 500 ml
            ['minibar', 'HK-MIN-010', 2], // Pepsi
            ['minibar', 'HK-MIN-011', 2], // Mirinda
            ['minibar', 'HK-MIN-001', 2], // Lay's
            ['minibar', 'HK-MIN-007', 2], // Biscuits
            ['minibar', 'HK-MIN-003', 2], // Dairy Milk
            ['asset', 'HK-LIN-001', 1], // Single Bedsheet
            ['asset', 'HK-LIN-005', 1], // Single Mattress Protector
            ['asset', 'HK-LIN-014', 1], // Single Duvet
            ['asset', 'HK-LIN-003', 1], // Single Duvet Cover
            ['asset', 'HK-LIN-007', 6], // Pillow
            ['asset', 'HK-LIN-008', 6], // Pillow Cover
            ['asset', 'HK-LIN-009', 6], // Pillow Protector
            ['asset', 'HK-LIN-018', 4], // Bath Towel
            ['asset', 'HK-LIN-016', 4], // Hand Towel
            ['asset', 'HK-LIN-017', 4], // Face Towel
            ['asset', 'HK-LIN-019', 2], // Bath Mat
            ['asset', 'HK-EQP-014', 4], // Coffee Cup
            ['asset', 'HK-EQP-015', 4], // Spoon
            ['asset', 'HK-AMN-024', 4], // Water Glass
            ['asset', 'HK-AMN-025', 10], // Wooden Hanger
            ['asset', 'HK-EQP-004', 1], // Iron
            ['asset', 'HK-EQP-005', 1], // Ironing Board
            ['asset', 'HK-EQP-003', 1], // Safe Locker
        ]);

        $juniorSuite = $this->mergeLines($premium, [
            ['amenity', 'HK-AMN-004', 2], // Dental Kit
            ['amenity', 'HK-AMN-005', 2], // Shaving Kit
            ['amenity', 'HK-AMN-013', 1], // A.P. Kit
            ['amenity', 'HK-AMN-014', 1], // Sanitizer
            ['amenity', 'HK-AMN-017', 1], // Shoe Shine
            ['amenity', 'HK-AMN-022', 2], // Tissue Box
            ['amenity', 'HK-GST-021', 2], // Pen
            ['minibar', 'HK-MIN-006', 4], // Aquafina 500 ml
            ['minibar', 'HK-MIN-004', 1], // Aquafina 1 Litre
            ['minibar', 'HK-MIN-005', 1], // Soda 750 ml
            ['minibar', 'HK-MIN-013', 2], // Coca-Cola
            ['minibar', 'HK-MIN-012', 2], // Red Bull
            ['minibar', 'HK-MIN-003', 2], // Dairy Milk
            ['minibar', 'HK-MIN-008', 2], // Cashew Nuts
            ['asset', 'HK-LIN-018', 4], // Bath Towel
            ['asset', 'HK-LIN-016', 4], // Hand Towel
            ['asset', 'HK-LIN-017', 4], // Face Towel
            ['asset', 'HK-LIN-019', 2], // Bath Mat
            ['asset', 'HK-LIN-012', 4], // Cushion Pillow
            ['asset', 'HK-LIN-013', 4], // Cushion Pillow Cover
            ['asset', 'HK-EQP-014', 4], // Coffee Cup
            ['asset', 'HK-EQP-015', 4], // Spoon
            ['asset', 'HK-AMN-024', 4], // Water Glass
            ['asset', 'HK-AMN-018', 1], // Shoe Polisher
            ['asset', 'HK-AMN-025', 10], // Wooden Hanger
        ]);

        return [
            'Deluxe Room' => $deluxe,
            'Premium Deluxe' => $premium,
            'Family' => $family,
            'Junior Suite' => $juniorSuite,
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float|int}>  $base
     * @param  list<array{0: string, 1: string, 2: float|int}>  $overrides
     * @return list<array{0: string, 1: string, 2: float|int}>
     */
    private function mergeLines(array $base, array $overrides): array
    {
        $byKey = [];
        foreach ([...$base, ...$overrides] as $line) {
            $byKey[$line[0] . '|' . $line[1]] = $line;
        }

        return array_values($byKey);
    }

    public function run(): void
    {
        $definitions = $this->templateLinesByRoomType();

        $skus = [];
        foreach ($definitions as $lines) {
            foreach ($lines as $line) {
                $skus[$line[1]] = true;
            }
        }
        $itemsBySku = InventoryItem::query()
            ->whereIn('sku', array_keys($skus))
            ->get(['id', 'sku', 'is_minibar', 'is_alcohol'])
            ->keyBy('sku');

        $missingSkus = [];
        $invalidMinibar = [];
        $linkedRooms = 0;

        foreach (RoomType::query()->orderBy('name')->get() as $roomType) {
            $lines = $definitions[$roomType->name] ?? null;
            if ($lines === null) {
                $this->command?->warn("No PAR lines defined for room type \"{$roomType->name}\" — skipped.");

                continue;
            }

            $template = RoomParTemplate::firstOrCreate([
                'room_type_id' => $roomType->id,
                'name' => self::TEMPLATE_NAME,
            ]);

            $keptLineIds = [];
            foreach ($lines as [$kind, $sku, $qty]) {
                $item = $itemsBySku->get($sku);
                if ($item === null) {
                    $missingSkus[$sku] = true;

                    continue;
                }
                if ($kind === 'minibar' && (! $item->is_minibar || $item->is_alcohol)) {
                    $invalidMinibar[$sku] = true;

                    continue;
                }

                $line = RoomParTemplateLine::updateOrCreate(
                    [
                        'template_id' => $template->id,
                        'inventory_item_id' => $item->id,
                        'kind' => $kind,
                    ],
                    ['par_qty' => $qty]
                );
                $keptLineIds[] = $line->id;
            }

            RoomParTemplateLine::query()
                ->where('template_id', $template->id)
                ->whereNotIn('id', $keptLineIds)
                ->delete();

            $rooms = Room::query()
                ->where('room_type_id', $roomType->id)
                ->whereNull('par_template_id')
                ->get(['id', 'room_number', 'room_type_id', 'par_template_id']);

            foreach ($rooms as $room) {
                $room->par_template_id = (int) $template->id;
                $room->save();
                RoomParInventoryContext::ensureRoomLocation($room);
                $linkedRooms++;
            }

            $this->command?->line("  {$roomType->name}: " . count($keptLineIds) . ' lines');
        }

        if ($missingSkus !== []) {
            $this->command?->warn(
                'Missing inventory SKUs (run HousekeepingInventorySeeder first): '
                    . implode(', ', array_keys($missingSkus))
            );
        }
        if ($invalidMinibar !== []) {
            $this->command?->warn(
                'Skipped minibar lines for items not flagged Minibar (or alcohol): '
                    . implode(', ', array_keys($invalidMinibar))
            );
        }

        $count = RoomParTemplate::where('name', self::TEMPLATE_NAME)->count();
        $this->command?->info("Room PAR templates ready ({$count} templates, {$linkedRooms} rooms newly linked).");
    }
}
