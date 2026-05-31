<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Room;
use App\Models\RoomParTemplate;
use App\Models\StoreRequest;
use App\Models\StoreRequestItem;
use App\Support\RoomParInventoryContext;

class RoomParStoreRequestService
{
    /**
     * @return array<int, array{inventory_item_id: int, quantity: float}>
     */
    public function buildLineQtys(Room $room, RoomParTemplate $template, bool $shortfallOnly): array
    {
        $onHand = [];
        if ($shortfallOnly) {
            $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
            $onHand = $ctx['on_hand_by_item_id'] ?? [];
        }

        $lineQtys = [];
        foreach ($template->lines as $line) {
            $parQty = (float) ($line->par_qty ?? 0);
            if ($parQty <= 0) {
                continue;
            }

            $itemId = (int) $line->inventory_item_id;
            $qty = $shortfallOnly
                ? max(0, $parQty - (float) ($onHand[$itemId] ?? 0))
                : $parQty;

            if ($qty <= 0) {
                continue;
            }

            $lineQtys[] = [
                'inventory_item_id' => $itemId,
                'quantity' => $qty,
            ];
        }

        return $lineQtys;
    }

    /**
     * @param  array<int, array{inventory_item_id: int, quantity: float}>  $lineQtys
     */
    public function createStoreRequest(
        Room $room,
        int $roomLocationId,
        InventoryLocation $source,
        array $lineQtys,
        ?string $notes,
        ?int $requestedBy,
        ?int $departmentId
    ): StoreRequest {
        $sr = StoreRequest::create([
            'request_number' => 'REQ-' . date('Ymd') . '-' . strtoupper(uniqid()),
            'from_location_id' => $roomLocationId,
            'to_location_id' => (int) $source->id,
            'department_id' => $departmentId,
            'requested_by' => $requestedBy,
            'status' => 'pending',
            'notes' => $notes,
            'requested_at' => now(),
        ]);

        foreach ($lineQtys as $row) {
            StoreRequestItem::create([
                'store_request_id' => (int) $sr->id,
                'inventory_item_id' => (int) $row['inventory_item_id'],
                'quantity_requested' => (float) $row['quantity'],
                'quantity_issued' => 0,
                'quantity_pending_acceptance' => 0,
            ]);
        }

        return $sr->load(['fromLocation', 'toLocation', 'items.item']);
    }
}
