<?php

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Room;
use App\Models\RoomParTemplate;
use Illuminate\Support\Facades\DB;

class RoomParInventoryContext
{
    public static function forRoomId(?int $roomId): ?array
    {
        if (! $roomId) {
            return null;
        }

        $room = Room::with(['roomType', 'parTemplate.lines.inventoryItem'])->find(
            $roomId,
            ['id', 'room_number', 'room_type_id', 'par_template_id']
        );
        if (! $room) {
            return null;
        }

        $roomLoc = InventoryLocation::where('room_id', '=', $room->id, 'and')->first();

        $template = null;
        if ($room->par_template_id) {
            $template = $room->parTemplate;
            if ($template && (int) $template->room_type_id !== (int) $room->room_type_id) {
                $template = RoomParTemplate::where('id', '=', $room->par_template_id, 'and')
                    ->with('lines.inventoryItem')
                    ->first();
            }
        }

        $onHand = [];
        if ($roomLoc) {
            $rows = DB::table('inventory_item_locations')
                ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                ->pluck('quantity', 'inventory_item_id');
            foreach ($rows as $itemId => $qty) {
                $onHand[(int) $itemId] = (float) $qty;
            }
        }

        $parItemIds = [];
        $parLines = [];
        if ($template) {
            if (! $template->relationLoaded('lines')) {
                $template->load('lines.inventoryItem');
            }
            foreach ($template->lines as $ln) {
                $itemId = (int) $ln->inventory_item_id;
                $parItemIds[$itemId] = true;
                $onHandQty = (float) ($onHand[$itemId] ?? 0);
                $requiredQty = (float) ($ln->par_qty ?? 0);
                $toStockQty = max(0, $requiredQty - $onHandQty);
                $parLines[] = [
                    'kind' => $ln->kind,
                    'inventory_item_id' => $itemId,
                    'item_name' => (string) ($ln->inventoryItem?->name ?? ''),
                    'sku' => (string) ($ln->inventoryItem?->sku ?? ''),
                    'is_direct_sale' => (bool) ($ln->inventoryItem?->is_direct_sale ?? false),
                    'required_qty' => $requiredQty,
                    'par_qty' => $requiredQty,
                    'on_hand_qty' => $onHandQty,
                    'to_stock_qty' => $toStockQty,
                    'shortfall_qty' => $toStockQty,
                ];
            }
        }

        $onHandItems = [];
        $extraOnHandItems = [];
        $positiveIds = [];
        foreach ($onHand as $iid => $q) {
            if ((float) $q > 0) {
                $positiveIds[] = (int) $iid;
            }
        }
        if (! empty($positiveIds)) {
            $items = InventoryItem::query()
                ->whereIn('id', $positiveIds, 'and', false)
                ->get(['id', 'name', 'sku', 'is_direct_sale']);
            foreach ($items as $it) {
                $row = [
                    'inventory_item_id' => (int) $it->id,
                    'name' => (string) $it->name,
                    'sku' => (string) $it->sku,
                    'is_direct_sale' => (bool) $it->is_direct_sale,
                    'qty' => (float) ($onHand[(int) $it->id] ?? 0),
                ];
                $onHandItems[] = $row;
                if (! isset($parItemIds[(int) $it->id])) {
                    $extraOnHandItems[] = $row;
                }
            }
        }

        $toStockTotal = array_sum(array_column($parLines, 'to_stock_qty'));

        return [
            'room_id' => (int) $room->id,
            'room_number' => (string) $room->room_number,
            'room_type_id' => (int) $room->room_type_id,
            'room_type_name' => (string) ($room->roomType?->name ?? ''),
            'room_location_id' => $roomLoc ? (int) $roomLoc->id : null,
            'template_assigned' => (bool) $room->par_template_id,
            'par_template_id' => $template ? (int) $template->id : null,
            'par_template_name' => $template ? (string) $template->name : null,
            'par_lines' => $parLines,
            'to_stock_total' => (float) $toStockTotal,
            'on_hand_by_item_id' => $onHand,
            'on_hand_items' => $onHandItems,
            'extra_on_hand_items' => $extraOnHandItems,
        ];
    }

    public static function resolveTemplateForRoom(Room $room): ?RoomParTemplate
    {
        if (! $room->par_template_id) {
            return null;
        }

        $template = $room->relationLoaded('parTemplate')
            ? $room->parTemplate
            : RoomParTemplate::with('lines')->find($room->par_template_id);

        if (! $template) {
            return null;
        }

        if ((int) $template->room_type_id !== (int) $room->room_type_id) {
            return null;
        }

        return $template;
    }

    public static function ensureRoomLocation(Room $room): InventoryLocation
    {
        $existing = InventoryLocation::where('room_id', '=', $room->id, 'and')->first();
        if ($existing) {
            return $existing;
        }

        $baseName = 'Room ' . trim((string) $room->room_number);
        $finalName = $baseName;
        if (InventoryLocation::where('name', '=', $finalName, 'and')->exists()) {
            $finalName = $baseName . ' (' . $room->id . ')';
        }

        return InventoryLocation::create([
            'name' => $finalName,
            'type' => 'satellite',
            'kind' => 'room',
            'room_id' => $room->id,
            'is_active' => true,
        ]);
    }
}
