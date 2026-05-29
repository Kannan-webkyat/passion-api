<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesHousekeepingPermissions;
use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Models\Department;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\Room;
use App\Models\StoreRequest;
use App\Services\RoomParStoreRequestService;
use App\Support\RoomParInventoryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HousekeepingRoomStockController extends Controller
{
    use AuthorizesSpatiePermissions;
    use AuthorizesHousekeepingPermissions;

    private const REQUEST_NOTE_PREFIX = 'HK room stock request';
    private const DIRECT_REFILL_NOTE_PREFIX = 'HK direct room refill';

    private function allowRoomStockAccess(): void
    {
        $this->allowHousekeepingViewSection([self::HK_ROOM_STOCK]);
    }

    /**
     * @return array{id: int, code: string}
     */
    private function requireHkDepartmentForUser(): array
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $hkDept = Department::query()
            ->where('code', '=', 'HKP', 'and')
            ->first(['id', 'code']);

        if (! $hkDept) {
            abort(422, 'Housekeeping department (HKP) not found.');
        }

        if ($user->hasRole('Admin')) {
            return ['id' => (int) $hkDept->id, 'code' => (string) $hkDept->code];
        }

        $userDeptIds = $user->departments()->pluck('departments.id')->toArray();
        if (! in_array((int) $hkDept->id, $userDeptIds, true)) {
            abort(403, 'Only Housekeeping department users can manage room stock.');
        }

        return ['id' => (int) $hkDept->id, 'code' => (string) $hkDept->code];
    }

    private function resolveHousekeepingStore(int $hkDepartmentId): InventoryLocation
    {
        $location = InventoryLocation::query()
            ->where('department_id', '=', $hkDepartmentId, 'and')
            ->where('type', '=', 'housekeeping_store', 'and')
            ->where('is_active', '=', true, 'and')
            ->orderBy('id')
            ->first();

        if ($location) {
            return $location;
        }

        // Backward-compatible fallback for existing data that still uses sub_store.
        $location = InventoryLocation::query()
            ->where('department_id', '=', $hkDepartmentId, 'and')
            ->where('type', '=', 'sub_store', 'and')
            ->where('name', '=', 'Housekeeping Store', 'and')
            ->where('is_active', '=', true, 'and')
            ->first();

        if (! $location) {
            abort(422, 'Housekeeping Store not found. Configure a location with type "housekeeping_store" under Housekeeping department.');
        }

        return $location;
    }

    public function index(Request $request)
    {
        $this->allowRoomStockAccess();

        $validated = $request->validate([
            'room_type_id' => 'nullable|exists:room_types,id',
            'floor' => 'nullable|string|max:50',
            'q' => 'nullable|string|max:80',
            'stock_status' => 'nullable|in:all,needs_restock,fully_stocked',
        ]);

        $query = Room::query()
            ->with(['roomType:id,name', 'parTemplate:id,name'])
            ->where('is_active', '=', true, 'and')
            ->orderBy('room_number');

        if (! empty($validated['room_type_id'])) {
            $query->where('room_type_id', '=', (int) $validated['room_type_id'], 'and');
        }
        if (! empty($validated['floor'])) {
            $query->where('floor', '=', (string) $validated['floor'], 'and');
        }
        if (! empty($validated['q'])) {
            $needle = trim((string) $validated['q']);
            $query->where(function ($q) use ($needle) {
                $q->where('room_number', 'like', '%' . $needle . '%')
                    ->orWhere('floor', 'like', '%' . $needle . '%');
            });
        }

        $rows = [];
        foreach ($query->get(['id', 'room_number', 'room_type_id', 'floor', 'par_template_id']) as $room) {
            $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
            if (! $ctx) {
                continue;
            }

            $toStockTotal = (float) ($ctx['to_stock_total'] ?? 0);
            $templateAssigned = (bool) ($ctx['template_assigned'] ?? false);
            $needsRestock = $templateAssigned && $toStockTotal > 0.00001;
            $isFullyStocked = $templateAssigned && $toStockTotal <= 0.00001;

            $rows[] = [
                'room_id' => (int) $room->id,
                'room_number' => (string) $room->room_number,
                'floor' => $room->floor !== null ? (string) $room->floor : null,
                'room_type_id' => (int) $room->room_type_id,
                'room_type_name' => (string) ($room->roomType->name ?? ''),
                'template_assigned' => $templateAssigned,
                'par_template_id' => $ctx['par_template_id'] ?? null,
                'par_template_name' => $ctx['par_template_name'] ?? null,
                'room_location_id' => $ctx['room_location_id'] ?? null,
                'to_stock_total' => $toStockTotal,
                'shortfall_items_count' => count(array_filter(
                    (array) ($ctx['par_lines'] ?? []),
                    fn(array $line): bool => ((float) ($line['to_stock_qty'] ?? $line['shortfall_qty'] ?? 0)) > 0.00001
                )),
                'needs_restock' => $needsRestock,
                'fully_stocked' => $isFullyStocked,
            ];
        }

        $stockStatus = (string) ($validated['stock_status'] ?? 'all');
        if ($stockStatus !== 'all') {
            $rows = array_values(array_filter($rows, function (array $row) use ($stockStatus): bool {
                if ($stockStatus === 'needs_restock') {
                    return (bool) $row['needs_restock'];
                }

                return (bool) $row['fully_stocked'];
            }));
        }

        return response()->json([
            'rooms' => $rows,
            'summary' => [
                'total_rooms' => count($rows),
                'rooms_needing_restock' => count(array_filter($rows, fn(array $row): bool => (bool) $row['needs_restock'])),
                'rooms_fully_stocked' => count(array_filter($rows, fn(array $row): bool => (bool) $row['fully_stocked'])),
            ],
        ]);
    }

    public function roomContext(Room $room)
    {
        $this->allowRoomStockAccess();

        $payload = RoomParInventoryContext::forRoomId((int) $room->id);
        if (! $payload) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        return response()->json($payload);
    }

    public function refill(Request $request)
    {
        $this->allowRoomStockAccess();

        $validated = $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'shortfall_only' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $hkDepartment = $this->requireHkDepartmentForUser();
        $source = $this->resolveHousekeepingStore((int) $hkDepartment['id']);
        $shortfallOnly = (bool) ($validated['shortfall_only'] ?? true);

        $rooms = Room::query()
            ->whereIn('id', $validated['room_ids'], 'and', false)
            ->get(['id', 'room_number', 'room_type_id', 'par_template_id']);

        /** @var array<int, float> $requiredByItem */
        $requiredByItem = [];
        /** @var list<array{room: Room, template_name: string, transfers: list<array{item_id: int, quantity: float}>}> $roomTransfers */
        $roomTransfers = [];
        $skipped = [];

        foreach ($rooms as $room) {
            $template = RoomParInventoryContext::resolveTemplateForRoom($room);
            if ($template && ! $template->relationLoaded('lines')) {
                $template->load('lines');
            }
            if (! $template) {
                $skipped[] = [
                    'room_id' => (int) $room->id,
                    'room_number' => (string) $room->room_number,
                    'reason' => 'No stock template assigned to this room.',
                ];
                continue;
            }

            $transfers = $this->buildRoomTransfers($room, $template, $shortfallOnly);
            if (empty($transfers)) {
                $skipped[] = [
                    'room_id' => (int) $room->id,
                    'room_number' => (string) $room->room_number,
                    'reason' => $shortfallOnly ? 'Already at or above PAR.' : 'No PAR lines on template.',
                ];
                continue;
            }

            foreach ($transfers as $transfer) {
                $itemId = (int) $transfer['item_id'];
                $requiredByItem[$itemId] = ($requiredByItem[$itemId] ?? 0) + (float) $transfer['quantity'];
            }

            $roomTransfers[] = [
                'room' => $room,
                'template_name' => (string) $template->name,
                'transfers' => $transfers,
            ];
        }

        if (empty($roomTransfers)) {
            return response()->json([
                'message' => 'No refill transfers created.',
                'skipped' => $skipped,
            ], 422);
        }

        $insufficient = $this->validateSourceStock($source, $requiredByItem);
        if ($insufficient !== null) {
            return response()->json([
                'message' => $this->formatInsufficientStockMessage($source, $insufficient),
                'source_location_id' => (int) $source->id,
                'source_location_name' => (string) $source->name,
                'insufficient_items' => $insufficient,
                'skipped' => $skipped,
            ], 422);
        }

        $executed = [];
        $transferredLines = 0;
        $userNote = trim((string) ($validated['notes'] ?? ''));

        DB::beginTransaction();
        try {
            foreach ($roomTransfers as $row) {
                $room = $row['room'];
                $roomLoc = RoomParInventoryContext::ensureRoomLocation($room);

                $baseNote = self::DIRECT_REFILL_NOTE_PREFIX . ' — Room ' . $room->room_number . ' (Template: ' . $row['template_name'] . ')';
                $noteText = $userNote !== '' ? ($baseNote . ' | ' . $userNote) : $baseNote;

                foreach ($row['transfers'] as $transfer) {
                    $this->transferStock(
                        (int) $transfer['item_id'],
                        $source,
                        $roomLoc,
                        (float) $transfer['quantity'],
                        $noteText
                    );
                    $transferredLines++;
                }

                $executed[] = [
                    'room_id' => (int) $room->id,
                    'room_number' => (string) $room->room_number,
                    'transferred_lines' => count($row['transfers']),
                ];
            }

            DB::commit();
        } catch (\RuntimeException $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'count' => count($executed),
            'transferred_lines' => $transferredLines,
            'source_location_id' => (int) $source->id,
            'source_location_name' => (string) $source->name,
            'rooms' => $executed,
            'skipped' => $skipped,
        ]);
    }

    public function refillItem(Request $request, Room $room)
    {
        $this->allowRoomStockAccess();

        $validated = $request->validate([
            'inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'quantity' => 'nullable|numeric|min:0.0001',
            'notes' => 'nullable|string|max:2000',
        ]);

        $hkDepartment = $this->requireHkDepartmentForUser();
        $source = $this->resolveHousekeepingStore((int) $hkDepartment['id']);

        $template = RoomParInventoryContext::resolveTemplateForRoom($room);
        if ($template && ! $template->relationLoaded('lines')) {
            $template->load('lines');
        }
        if (! $template) {
            return response()->json(['message' => 'No stock template assigned to this room.'], 422);
        }

        $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
        $itemId = (int) $validated['inventory_item_id'];
        $line = null;
        foreach ((array) ($ctx['par_lines'] ?? []) as $row) {
            if ((int) ($row['inventory_item_id'] ?? 0) === $itemId) {
                $line = $row;
                break;
            }
        }

        if (! $line) {
            return response()->json(['message' => 'Selected item is not part of this room template.'], 422);
        }

        $shortfallQty = max(0, (float) ($line['to_stock_qty'] ?? $line['shortfall_qty'] ?? 0));
        if ($shortfallQty <= 0.00001) {
            return response()->json([
                'message' => 'This item is already at or above PAR.',
                'transferred_qty' => 0,
                'context' => RoomParInventoryContext::forRoomId((int) $room->id),
            ]);
        }

        $requestedQty = (float) ($validated['quantity'] ?? $shortfallQty);
        $qty = min($requestedQty, $shortfallQty);
        if ($qty <= 0.00001) {
            return response()->json(['message' => 'Quantity must be greater than zero.'], 422);
        }

        $item = InventoryItem::query()->find($itemId);
        $itemName = (string) ($item->name ?? ('Item #' . $itemId));

        DB::beginTransaction();
        try {
            $roomLoc = RoomParInventoryContext::ensureRoomLocation($room);
            $baseNote = self::DIRECT_REFILL_NOTE_PREFIX . ' — Room ' . $room->room_number . ' · ' . $itemName;
            $userNote = trim((string) ($validated['notes'] ?? ''));
            $noteText = $userNote !== '' ? ($baseNote . ' | ' . $userNote) : $baseNote;

            $this->transferStock($itemId, $source, $roomLoc, $qty, $noteText);

            DB::commit();
        } catch (\RuntimeException $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Refill completed.',
            'item_id' => $itemId,
            'item_name' => $itemName,
            'transferred_qty' => $qty,
            'source_location_id' => (int) $source->id,
            'source_location_name' => (string) $source->name,
            'context' => RoomParInventoryContext::forRoomId((int) $room->id),
        ]);
    }

    public function requestRestock(Request $request)
    {
        $this->allowRoomStockAccess();

        $validated = $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'shortfall_only' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $hkDepartment = $this->requireHkDepartmentForUser();
        $source = $this->resolveHousekeepingStore((int) $hkDepartment['id']);
        $shortfallOnly = (bool) ($validated['shortfall_only'] ?? true);
        $rooms = Room::query()
            ->whereIn('id', $validated['room_ids'], 'and', false)
            ->get(['id', 'room_number', 'room_type_id', 'par_template_id']);

        $service = app(RoomParStoreRequestService::class);
        $created = [];
        $skipped = [];

        DB::beginTransaction();
        try {
            foreach ($rooms as $room) {
                $template = RoomParInventoryContext::resolveTemplateForRoom($room);
                if ($template && ! $template->relationLoaded('lines')) {
                    $template->load('lines');
                }
                if (! $template) {
                    $skipped[] = [
                        'room_id' => (int) $room->id,
                        'room_number' => (string) $room->room_number,
                        'reason' => 'No stock template assigned to this room.',
                    ];
                    continue;
                }

                $lineQtys = $service->buildLineQtys($room, $template, $shortfallOnly);
                if (empty($lineQtys)) {
                    $skipped[] = [
                        'room_id' => (int) $room->id,
                        'room_number' => (string) $room->room_number,
                        'reason' => $shortfallOnly
                            ? 'Already at or above PAR.'
                            : 'No PAR lines on template.',
                    ];
                    continue;
                }

                $roomLoc = RoomParInventoryContext::ensureRoomLocation($room);
                $baseNote = self::REQUEST_NOTE_PREFIX . ' — Room ' . $room->room_number . ' (Template: ' . $template->name . ')';
                $userNote = trim((string) ($validated['notes'] ?? ''));
                $defaultNotes = $userNote !== '' ? ($baseNote . ' | ' . $userNote) : $baseNote;

                $created[] = $service->createStoreRequest(
                    $room,
                    (int) $roomLoc->id,
                    $source,
                    $lineQtys,
                    $defaultNotes,
                    Auth::id(),
                    (int) $hkDepartment['id']
                );
            }

            if (empty($created)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'No restock requests created.',
                    'skipped' => $skipped,
                ], 422);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'count' => count($created),
            'source_location_id' => (int) $source->id,
            'source_location_name' => (string) $source->name,
            'store_requests' => $created,
            'skipped' => $skipped,
        ]);
    }

    public function requestHistory(Request $request)
    {
        $this->allowRoomStockAccess();

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $hkDepartment = $this->requireHkDepartmentForUser();
        $source = $this->resolveHousekeepingStore((int) $hkDepartment['id']);
        $limit = (int) ($validated['limit'] ?? 50);

        $query = StoreRequest::query()
            ->with([
                'department:id,name,code',
                'fromLocation:id,name,room_id',
                'fromLocation.room:id,room_number',
                'toLocation:id,name',
                'items.item:id,name,sku',
            ])
            ->where('to_location_id', '=', (int) $source->id, 'and')
            ->where('notes', 'like', self::REQUEST_NOTE_PREFIX . '%')
            ->orderByDesc('id');

        $user = Auth::user();
        if (! $user || ! $user->hasRole('Admin')) {
            $query->where('requested_by', '=', Auth::id(), 'and');
        }

        return response()->json($query->limit($limit)->get());
    }

    public function refillHistory(Request $request)
    {
        $this->allowRoomStockAccess();

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'q' => 'nullable|string|max:80',
        ]);

        $limit = (int) ($validated['limit'] ?? 100);

        $query = InventoryTransaction::query()
            ->with([
                'item:id,name,sku',
                'location:id,name,room_id',
                'location.room:id,room_number',
                'user:id,name',
            ])
            ->where('reference_type', '=', 'housekeeping_room_stock', 'and')
            ->where('type', '=', 'in', 'and')
            ->whereHas('location', function ($q) {
                $q->whereNotNull('room_id');
            })
            ->orderByDesc('id');

        if (! empty($validated['room_id'])) {
            $roomId = (int) $validated['room_id'];
            $query->whereHas('location', function ($q) use ($roomId) {
                $q->where('room_id', '=', $roomId, 'and');
            });
        }

        if (! empty($validated['q'])) {
            $needle = trim((string) $validated['q']);
            $query->where(function ($q) use ($needle) {
                $q->whereHas('item', function ($itemQ) use ($needle) {
                    $itemQ->where('name', 'like', '%' . $needle . '%')
                        ->orWhere('sku', 'like', '%' . $needle . '%');
                })
                    ->orWhereHas('location.room', function ($roomQ) use ($needle) {
                        $roomQ->where('room_number', 'like', '%' . $needle . '%');
                    })
                    ->orWhereHas('user', function ($userQ) use ($needle) {
                        $userQ->where('name', 'like', '%' . $needle . '%');
                    });
            });
        }

        $rows = $query->limit($limit)->get();
        $refIds = $rows
            ->pluck('reference_id')
            ->filter(fn($v): bool => $v !== null && $v !== '')
            ->map(fn($v): string => (string) $v)
            ->unique()
            ->values()
            ->all();

        $sourceByRef = [];
        if (! empty($refIds)) {
            $outRows = InventoryTransaction::query()
                ->with(['location:id,name'])
                ->whereIn('reference_id', $refIds, 'and', false)
                ->where('reference_type', '=', 'housekeeping_room_stock', 'and')
                ->where('type', '=', 'out', 'and')
                ->orderByDesc('id')
                ->get(['id', 'reference_id', 'inventory_location_id']);

            foreach ($outRows as $out) {
                $refId = (string) ($out->reference_id ?? '');
                if ($refId === '' || isset($sourceByRef[$refId])) {
                    continue;
                }
                $sourceByRef[$refId] = [
                    'source_location_id' => (int) ($out->inventory_location_id ?? 0),
                    'source_location_name' => (string) ($out->location->name ?? ''),
                ];
            }
        }

        return response()->json($rows->map(function (InventoryTransaction $row) use ($sourceByRef): array {
            $refId = (string) ($row->reference_id ?? '');
            $src = $refId !== '' ? ($sourceByRef[$refId] ?? null) : null;

            return [
                'id' => (int) $row->id,
                'reference_id' => $refId !== '' ? $refId : null,
                'performed_at' => optional($row->created_at)?->toISOString(),
                'room_id' => (int) ($row->location->room_id ?? 0),
                'room_number' => (string) ($row->location->room->room_number ?? ''),
                'to_location_id' => (int) ($row->inventory_location_id ?? 0),
                'to_location_name' => (string) ($row->location->name ?? ''),
                'source_location_id' => (int) ($src['source_location_id'] ?? 0),
                'source_location_name' => (string) ($src['source_location_name'] ?? ''),
                'inventory_item_id' => (int) ($row->inventory_item_id ?? 0),
                'item_name' => (string) ($row->item->name ?? ''),
                'sku' => (string) ($row->item->sku ?? ''),
                'quantity' => (float) ($row->quantity ?? 0),
                'performed_by_user_id' => (int) ($row->user_id ?? 0),
                'performed_by_name' => (string) ($row->user->name ?? 'System'),
                'notes' => (string) ($row->notes ?? ''),
            ];
        })->values());
    }

    /**
     * @return list<array{item_id: int, quantity: float}>
     */
    private function buildRoomTransfers(Room $room, object $template, bool $shortfallOnly): array
    {
        $onHand = [];
        if ($shortfallOnly) {
            $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
            $onHand = $ctx['on_hand_by_item_id'] ?? [];
        }

        $transfers = [];
        foreach (($template->lines ?? []) as $line) {
            $parQty = (float) ($line->par_qty ?? 0);
            if ($parQty <= 0) {
                continue;
            }

            $itemId = (int) ($line->inventory_item_id ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $qty = $shortfallOnly
                ? max(0, $parQty - (float) ($onHand[$itemId] ?? 0))
                : $parQty;
            if ($qty <= 0) {
                continue;
            }

            $transfers[] = ['item_id' => $itemId, 'quantity' => $qty];
        }

        return $transfers;
    }

    private function locationOnHand(int $itemId, InventoryLocation $location): float
    {
        $qty = DB::table('inventory_item_locations')
            ->where('inventory_item_id', '=', $itemId, 'and')
            ->where('inventory_location_id', '=', $location->id, 'and')
            ->value('quantity');

        return max(0, (float) ($qty ?? 0));
    }

    /**
     * @param  array<int, float>  $requiredByItem
     * @return list<array{inventory_item_id: int, item_name: string, sku: string, required: float, available: float, short_by: float}>|null
     */
    private function validateSourceStock(InventoryLocation $source, array $requiredByItem): ?array
    {
        $shortfalls = [];

        foreach ($requiredByItem as $itemId => $required) {
            $required = (float) $required;
            if ($required <= 0) {
                continue;
            }

            $available = $this->locationOnHand((int) $itemId, $source);
            if ($available + 0.00001 < $required) {
                $item = InventoryItem::query()->find((int) $itemId);
                $shortfalls[] = [
                    'inventory_item_id' => (int) $itemId,
                    'item_name' => (string) ($item->name ?? ('Item #' . $itemId)),
                    'sku' => (string) ($item->sku ?? ''),
                    'required' => $required,
                    'available' => $available,
                    'short_by' => max(0, $required - $available),
                ];
            }
        }

        return empty($shortfalls) ? null : $shortfalls;
    }

    /**
     * @param  list<array{inventory_item_id: int, item_name: string, sku: string, required: float, available: float, short_by: float}>  $insufficient
     */
    private function formatInsufficientStockMessage(InventoryLocation $source, array $insufficient): string
    {
        $lines = array_map(function (array $row): string {
            $label = trim(($row['item_name'] ?? '') . (($row['sku'] ?? '') !== '' ? (' [' . $row['sku'] . ']') : ''));

            return sprintf(
                '%s (need %s, have %s)',
                $label !== '' ? $label : ('Item #' . (int) ($row['inventory_item_id'] ?? 0)),
                $this->formatQty((float) $row['required']),
                $this->formatQty((float) $row['available'])
            );
        }, array_slice($insufficient, 0, 5));

        $suffix = count($insufficient) > 5
            ? sprintf(' and %d more item(s)', count($insufficient) - 5)
            : '';

        return sprintf(
            'Insufficient stock at %s: %s%s.',
            $source->name,
            implode('; ', $lines),
            $suffix
        );
    }

    private function formatQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.00001) {
            return (string) (int) round($qty);
        }

        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    private function transferStock(int $itemId, InventoryLocation $source, InventoryLocation $dest, float $qty, string $notes): void
    {
        if ($qty <= 0) {
            return;
        }

        $item = InventoryItem::query()->findOrFail($itemId);

        /** @var object|null $sourceRow */
        $sourceRow = DB::table('inventory_item_locations')
            ->where('inventory_item_id', '=', $item->id, 'and')
            ->where('inventory_location_id', '=', $source->id, 'and')
            ->lockForUpdate()
            ->first();

        $available = (float) ($sourceRow->quantity ?? 0);
        if ($available + 0.00001 < $qty) {
            throw new \RuntimeException(sprintf(
                'Insufficient stock for "%s" at %s. Available: %s, requested: %s.',
                $item->name,
                $source->name,
                $this->formatQty($available),
                $this->formatQty($qty)
            ));
        }

        DB::table('inventory_item_locations')
            ->where('inventory_item_id', '=', $item->id, 'and')
            ->where('inventory_location_id', '=', $source->id, 'and')
            ->decrement('quantity', $qty);

        $unitCost = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?: 1);
        $refId = (string) Str::uuid();

        InventoryTransaction::query()->create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $source->id,
            'type' => 'out',
            'quantity' => $qty,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => round($qty * $unitCost, 2),
            'reason' => 'Transfer',
            'notes' => $notes . " → {$dest->name}",
            'user_id' => auth()->id(),
            'reference_id' => $refId,
            'reference_type' => 'housekeeping_room_stock',
        ]);

        DB::table('inventory_item_locations')->updateOrInsert(
            ['inventory_item_id' => $item->id, 'inventory_location_id' => $dest->id],
            ['updated_at' => now(), 'created_at' => now()]
        );

        DB::table('inventory_item_locations')
            ->where('inventory_item_id', '=', $item->id, 'and')
            ->where('inventory_location_id', '=', $dest->id, 'and')
            ->increment('quantity', $qty);

        InventoryTransaction::query()->create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $dest->id,
            'type' => 'in',
            'quantity' => $qty,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => round($qty * $unitCost, 2),
            'reason' => 'Transfer',
            'notes' => "Received from {$source->name} — {$notes}",
            'user_id' => auth()->id(),
            'reference_id' => $refId,
            'reference_type' => 'housekeeping_room_stock',
        ]);

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);
    }
}
