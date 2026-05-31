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
 * One "Default" room-par template per room type for local/testing.
 * Requires RoomTypeRoomSeeder and HotelInventoryCatalogSeeder.
 */
class RoomParTestTemplatesSeeder extends Seeder
{
    /**
     * @return array<string, list<array{kind: string, name: string, qty: float}>>
     */
    private function templateLinesByRoomType(): array
    {
        return [
            'Standard' => [
                ['kind' => 'amenity', 'name' => 'Shampoo', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Conditioner', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Bar soap', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Dental kit (toothbrush + toothpaste)', 'qty' => 1],
                ['kind' => 'amenity', 'name' => 'Coffee sachet', 'qty' => 4],
                ['kind' => 'amenity', 'name' => 'Tea bag', 'qty' => 4],
                ['kind' => 'amenity', 'name' => 'Bottled water', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Notepad', 'qty' => 1],
                ['kind' => 'amenity', 'name' => 'Pen', 'qty' => 1],
                ['kind' => 'minibar', 'name' => 'Soda', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Juice', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Chocolate', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Electric kettle', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Hair dryer', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Iron', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Ironing board', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Clothes hanger', 'qty' => 6],
            ],
            'Deluxe' => [
                ['kind' => 'amenity', 'name' => 'Shampoo', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Conditioner', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Liquid soap', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Dental kit (toothbrush + toothpaste)', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Shaving kit', 'qty' => 1],
                ['kind' => 'amenity', 'name' => 'Coffee sachet', 'qty' => 6],
                ['kind' => 'amenity', 'name' => 'Tea bag', 'qty' => 6],
                ['kind' => 'amenity', 'name' => 'Bottled water', 'qty' => 4],
                ['kind' => 'amenity', 'name' => 'Notepad', 'qty' => 1],
                ['kind' => 'amenity', 'name' => 'Pen', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Soda', 'qty' => 3],
                ['kind' => 'minibar', 'name' => 'Juice', 'qty' => 3],
                ['kind' => 'minibar', 'name' => 'Sparkling water', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Alcohol miniature', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Nuts', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Electric kettle', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Coffee maker', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Mini-fridge', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Hair dryer', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Iron', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Ironing board', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Clothes hanger', 'qty' => 8],
            ],
            'Family' => [
                ['kind' => 'amenity', 'name' => 'Shampoo', 'qty' => 4],
                ['kind' => 'amenity', 'name' => 'Conditioner', 'qty' => 4],
                ['kind' => 'amenity', 'name' => 'Bar soap', 'qty' => 4],
                ['kind' => 'amenity', 'name' => 'Dental kit (toothbrush + toothpaste)', 'qty' => 4],
                ['kind' => 'amenity', 'name' => 'Coffee sachet', 'qty' => 8],
                ['kind' => 'amenity', 'name' => 'Tea bag', 'qty' => 8],
                ['kind' => 'amenity', 'name' => 'Bottled water', 'qty' => 6],
                ['kind' => 'amenity', 'name' => 'Notepad', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Pen', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Soda', 'qty' => 4],
                ['kind' => 'minibar', 'name' => 'Juice', 'qty' => 4],
                ['kind' => 'minibar', 'name' => 'Crackers', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Cup noodles', 'qty' => 2],
                ['kind' => 'asset', 'name' => 'Electric kettle', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Coffee maker', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Mini-fridge', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Hair dryer', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Iron', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Ironing board', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Clothes hanger', 'qty' => 12],
                ['kind' => 'asset', 'name' => 'Luggage rack', 'qty' => 2],
            ],
            'Suite' => [
                ['kind' => 'amenity', 'name' => 'Shampoo', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Conditioner', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Liquid soap', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Dental kit (toothbrush + toothpaste)', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Shaving kit', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Coffee sachet', 'qty' => 8],
                ['kind' => 'amenity', 'name' => 'Tea bag', 'qty' => 8],
                ['kind' => 'amenity', 'name' => 'Bottled water', 'qty' => 6],
                ['kind' => 'amenity', 'name' => 'Notepad', 'qty' => 2],
                ['kind' => 'amenity', 'name' => 'Pen', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Soda', 'qty' => 4],
                ['kind' => 'minibar', 'name' => 'Juice', 'qty' => 4],
                ['kind' => 'minibar', 'name' => 'Sparkling water', 'qty' => 4],
                ['kind' => 'minibar', 'name' => 'Alcohol miniature', 'qty' => 4],
                ['kind' => 'minibar', 'name' => 'Chocolate', 'qty' => 2],
                ['kind' => 'minibar', 'name' => 'Nuts', 'qty' => 2],
                ['kind' => 'asset', 'name' => 'Coffee maker', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Electric kettle', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Mini-fridge', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Television', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Hair dryer', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Safe-deposit box', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Iron', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Ironing board', 'qty' => 1],
                ['kind' => 'asset', 'name' => 'Clothes hanger', 'qty' => 10],
                ['kind' => 'asset', 'name' => 'Luggage rack', 'qty' => 2],
            ],
        ];
    }

    private function itemId(string $name): ?int
    {
        $id = InventoryItem::where('name', $name)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function run(): void
    {
        $definitions = $this->templateLinesByRoomType();
        $missingItems = [];

        foreach (RoomType::query()->orderBy('name')->get() as $roomType) {
            $lines = $definitions[$roomType->name] ?? null;
            if ($lines === null) {
                $this->command?->warn("No PAR test lines defined for room type \"{$roomType->name}\" — skipped.");

                continue;
            }

            $template = RoomParTemplate::updateOrCreate(
                [
                    'room_type_id' => $roomType->id,
                    'name' => 'Default',
                ],
                []
            );

            foreach ($lines as $line) {
                $itemId = $this->itemId($line['name']);
                if ($itemId === null) {
                    $missingItems[] = $line['name'];

                    continue;
                }

                RoomParTemplateLine::updateOrCreate(
                    [
                        'template_id' => $template->id,
                        'inventory_item_id' => $itemId,
                        'kind' => $line['kind'],
                    ],
                    [
                        'par_qty' => $line['qty'],
                    ]
                );
            }

            $rooms = Room::query()
                ->where('room_type_id', $roomType->id)
                ->get(['id', 'room_number', 'room_type_id', 'par_template_id']);

            foreach ($rooms as $room) {
                $room->par_template_id = (int) $template->id;
                $room->save();
                RoomParInventoryContext::ensureRoomLocation($room);
            }
        }

        if ($missingItems !== []) {
            $this->command?->warn(
                'Missing inventory items (run HotelInventoryCatalogSeeder first): '
                    . implode(', ', array_unique($missingItems))
            );
        }

        $count = RoomParTemplate::count();
        $this->command?->info("Room PAR test templates ready ({$count} templates).");
    }
}
