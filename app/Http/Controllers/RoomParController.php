<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\RoomParTemplate;
use App\Models\RoomParTemplateLine;
use App\Models\StoreRequest;
use App\Models\StoreRequestItem;
use App\Models\InventoryLocation;
use App\Models\Room;
use App\Support\RoomParInventoryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoomParController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;

    private function checkPermission(string $permission)
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function checkReadPermission(): void
    {
        $this->authorizePermissions([
            'manage-inventory',
            'reservation-view',
            'reservation',
            'rooms-view',
            'view-rooms',
            'housekeeping-dirty-rooms',
            'housekeeping-checkout-inspection',
            'housekeeping-cleaning-tasks',
            'housekeeping-daily-room-cleaning',
            'housekeeping-clean-rooms',
            'housekeeping-laundry',
        ]);
    }

    public function roomContext(Room $room)
    {
        $this->checkReadPermission();

        $payload = RoomParInventoryContext::forRoomId((int) $room->id);
        if (! $payload) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        return response()->json($payload);
    }

    public function ensureRoomLocation(Room $room)
    {
        $this->checkPermission('manage-inventory');

        $loc = RoomParInventoryContext::ensureRoomLocation($room);

        return response()->json([
            'room_location_id' => (int) $loc->id,
            'context' => RoomParInventoryContext::forRoomId((int) $room->id),
        ]);
    }

    /**
     * Assign a stock template to one or more rooms (defines required items — no stock movement).
     */
    public function assign(Request $request)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'template_id' => 'required|exists:room_par_templates,id',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
        ]);

        $template = RoomParTemplate::findOrFail((int) $validated['template_id']);
        $rooms = Room::query()
            ->whereIn('id', $validated['room_ids'], 'and', false)
            ->get(['id', 'room_number', 'room_type_id', 'par_template_id']);

        $assigned = [];
        $skipped = [];

        DB::beginTransaction();
        try {
            foreach ($rooms as $room) {
                if ((int) $room->room_type_id !== (int) $template->room_type_id) {
                    $skipped[] = [
                        'room_id' => (int) $room->id,
                        'room_number' => (string) $room->room_number,
                        'reason' => 'Room type does not match template.',
                    ];
                    continue;
                }

                $room->par_template_id = (int) $template->id;
                $room->save();

                $roomLoc = RoomParInventoryContext::ensureRoomLocation($room);

                $assigned[] = [
                    'room_id' => (int) $room->id,
                    'room_number' => (string) $room->room_number,
                    'par_template_id' => (int) $template->id,
                    'par_template_name' => (string) $template->name,
                    'room_location_id' => (int) $roomLoc->id,
                ];
            }

            if (empty($assigned)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'No rooms assigned — check that room types match the template.',
                    'skipped' => $skipped,
                ], 422);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'count' => count($assigned),
            'rooms' => $assigned,
            'skipped' => $skipped,
        ]);
    }

    public function assignRoom(Request $request, Room $room)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'template_id' => 'nullable|exists:room_par_templates,id',
        ]);

        if (empty($validated['template_id'])) {
            $room->par_template_id = null;
            $room->save();

            return response()->json([
                'context' => RoomParInventoryContext::forRoomId((int) $room->id),
            ]);
        }

        $template = RoomParTemplate::findOrFail((int) $validated['template_id']);
        if ((int) $template->room_type_id !== (int) $room->room_type_id) {
            return response()->json(['message' => 'Template does not match this room type.'], 422);
        }

        $room->par_template_id = (int) $template->id;
        $room->save();

        RoomParInventoryContext::ensureRoomLocation($room);

        return response()->json([
            'context' => RoomParInventoryContext::forRoomId((int) $room->id),
        ]);
    }

    /**
     * Transfer template stock from store into room locations immediately (no store request).
     */
    public function allocate(Request $request)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'template_id' => 'nullable|exists:room_par_templates,id',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'source_location_id' => 'nullable|exists:inventory_locations,id',
            'notes' => 'nullable|string|max:500',
            'shortfall_only' => 'nullable|boolean',
        ]);

        $shortfallOnly = (bool) ($validated['shortfall_only'] ?? true);
        $source = $this->resolveSourceLocation($validated['source_location_id'] ?? null);
        $explicitTemplateId = ! empty($validated['template_id']) ? (int) $validated['template_id'] : null;

        $rooms = Room::query()
            ->whereIn('id', $validated['room_ids'], 'and', false)
            ->get(['id', 'room_number', 'room_type_id', 'par_template_id']);

        $pending = [];
        $requiredByItem = [];
        $skipped = [];

        DB::beginTransaction();
        try {
            foreach ($rooms as $room) {
                $template = null;
                if ($explicitTemplateId) {
                    $template = RoomParTemplate::with('lines')->find($explicitTemplateId);
                    if ($template && (int) $template->room_type_id !== (int) $room->room_type_id) {
                        $template = null;
                    }
                } else {
                    $template = RoomParInventoryContext::resolveTemplateForRoom($room);
                }

                if (! $template) {
                    $skipped[] = [
                        'room_id' => (int) $room->id,
                        'room_number' => (string) $room->room_number,
                        'reason' => 'No stock template assigned to this room.',
                    ];
                    continue;
                }

                if (! $template->relationLoaded('lines')) {
                    $template->load('lines');
                }

                $transfers = $this->buildRoomParTransfers($room, $template, $shortfallOnly);
                if (empty($transfers)) {
                    $skipped[] = [
                        'room_id' => (int) $room->id,
                        'room_number' => (string) $room->room_number,
                        'reason' => $shortfallOnly
                            ? 'Already at or above PAR.'
                            : 'No PAR lines on template.',
                    ];
                    continue;
                }

                $pending[] = [
                    'room' => $room,
                    'template' => $template,
                    'transfers' => $transfers,
                ];

                foreach ($transfers as $t) {
                    $itemId = (int) $t['item_id'];
                    $requiredByItem[$itemId] = ($requiredByItem[$itemId] ?? 0) + (float) $t['quantity'];
                }
            }

            if (empty($pending)) {
                DB::rollBack();

                return response()->json([
                    'message' => $shortfallOnly
                        ? 'No stock transferred — selected rooms are already at or above PAR (or room type mismatch).'
                        : 'No stock transferred — check template lines and room type.',
                    'skipped' => $skipped,
                ], 422);
            }

            $insufficient = $this->validateSourceStock($source, $requiredByItem);
            if ($insufficient !== null) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Insufficient stock at source location for one or more items.',
                    'insufficient' => $insufficient,
                    'source_location_id' => (int) $source->id,
                    'source_location_name' => (string) $source->name,
                    'skipped' => $skipped,
                ], 422);
            }

            $allocated = [];
            $noteText = trim((string) ($validated['notes'] ?? ''));

            foreach ($pending as $batch) {
                $allocated[] = $this->executeRoomParTransfers(
                    $batch['room'],
                    $batch['template'],
                    $source,
                    $batch['transfers'],
                    $shortfallOnly,
                    $noteText !== '' ? $noteText : null
                );
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
            'count' => count($allocated),
            'rooms' => $allocated,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Transfer shortfall quantities from main store (or chosen source) into the room location.
     */
    public function issueToPar(Request $request, Room $room)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'template_id' => 'nullable|exists:room_par_templates,id',
            'source_location_id' => 'nullable|exists:inventory_locations,id',
            'notes' => 'nullable|string|max:500',
            'shortfall_only' => 'nullable|boolean',
        ]);

        $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
        if (! $ctx) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        $room->load('parTemplate');
        $template = null;
        if (! empty($validated['template_id'])) {
            $template = RoomParTemplate::with('lines')->find((int) $validated['template_id']);
            if ($template && (int) $template->room_type_id !== (int) $room->room_type_id) {
                return response()->json(['message' => 'Template does not match room type.'], 422);
            }
        } else {
            $template = RoomParInventoryContext::resolveTemplateForRoom($room);
        }

        if (! $template) {
            return response()->json(['message' => 'No stock template assigned to this room. Assign a template first.'], 422);
        }

        if (! $template->relationLoaded('lines')) {
            $template->load('lines');
        }

        $source = $this->resolveSourceLocation($validated['source_location_id'] ?? null);
        $shortfallOnly = (bool) ($validated['shortfall_only'] ?? true);

        DB::beginTransaction();
        try {
            $result = $this->allocateRoomToPar($room, $template, $source, $shortfallOnly, $validated['notes'] ?? null);
            if ($result === null) {
                DB::rollBack();

                return response()->json([
                    'message' => $shortfallOnly
                        ? 'Room is already at or above PAR for all template lines.'
                        : 'No PAR lines to transfer.',
                ], 422);
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
            'transferred_lines' => $result['transferred_lines'],
            'context' => RoomParInventoryContext::forRoomId((int) $room->id),
        ]);
    }

    private function resolveSourceLocation(?int $sourceLocationId): InventoryLocation
    {
        if ($sourceLocationId) {
            return InventoryLocation::findOrFail($sourceLocationId);
        }

        $source = InventoryLocation::where('type', '=', 'main_store', 'and')->first();
        if (! $source) {
            abort(422, 'Source store location not found.');
        }

        return $source;
    }

    /**
     * @return list<array{item_id: int, quantity: float}>
     */
    private function buildRoomParTransfers(Room $room, RoomParTemplate $template, bool $shortfallOnly): array
    {
        $onHand = [];
        if ($shortfallOnly) {
            $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
            $onHand = $ctx['on_hand_by_item_id'] ?? [];
        }

        $transfers = [];
        foreach ($template->lines as $ln) {
            $parQty = (float) ($ln->par_qty ?? 0);
            if ($parQty <= 0) {
                continue;
            }
            $itemId = (int) $ln->inventory_item_id;
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
                $item = InventoryItem::find($itemId);
                $shortfalls[] = [
                    'inventory_item_id' => (int) $itemId,
                    'item_name' => (string) ($item->name ?? 'Item #' . $itemId),
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
        $lines = array_map(function (array $row) {
            return sprintf(
                '%s (need %s, available %s)',
                $row['item_name'],
                $this->formatQty($row['required']),
                $this->formatQty($row['available'])
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

    /**
     * @param  list<array{item_id: int, quantity: float}>  $transfers
     * @return array{room_id: int, room_number: string, transferred_lines: int}
     */
    private function executeRoomParTransfers(
        Room $room,
        RoomParTemplate $template,
        InventoryLocation $source,
        array $transfers,
        bool $shortfallOnly,
        ?string $notes
    ): array {
        $roomLoc = RoomParInventoryContext::ensureRoomLocation($room);

        $noteText = trim((string) ($notes ?? '')) ?: (
            ($shortfallOnly ? 'Restock to PAR' : 'Allocate template')
            . ' — Room ' . $room->room_number
            . ' (' . $template->name . ')'
        );

        foreach ($transfers as $t) {
            $this->transferStock(
                (int) $t['item_id'],
                $source,
                $roomLoc,
                (float) $t['quantity'],
                $noteText
            );
        }

        return [
            'room_id' => (int) $room->id,
            'room_number' => (string) $room->room_number,
            'transferred_lines' => count($transfers),
        ];
    }

    /**
     * @return array{room_id: int, room_number: string, transferred_lines: int}|null
     */
    private function allocateRoomToPar(
        Room $room,
        RoomParTemplate $template,
        InventoryLocation $source,
        bool $shortfallOnly,
        ?string $notes
    ): ?array {
        $transfers = $this->buildRoomParTransfers($room, $template, $shortfallOnly);
        if (empty($transfers)) {
            return null;
        }

        $requiredByItem = [];
        foreach ($transfers as $t) {
            $itemId = (int) $t['item_id'];
            $requiredByItem[$itemId] = ($requiredByItem[$itemId] ?? 0) + (float) $t['quantity'];
        }

        $insufficient = $this->validateSourceStock($source, $requiredByItem);
        if ($insufficient !== null) {
            throw new \RuntimeException($this->formatInsufficientStockMessage($source, $insufficient));
        }

        return $this->executeRoomParTransfers($room, $template, $source, $transfers, $shortfallOnly, $notes);
    }

    /**
     * @param  array<int, array{item_id: int, quantity: float}>  $transfers
     */
    private function transferStock(int $itemId, InventoryLocation $source, InventoryLocation $dest, float $qty, string $notes): void
    {
        if ($qty <= 0) {
            return;
        }

        $item = InventoryItem::findOrFail($itemId);

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
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $source->id)
            ->decrement('quantity', $qty);

        $unitCost = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?: 1);
        $refId = (string) Str::uuid();

        InventoryTransaction::create([
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
            'reference_type' => 'room_par',
        ]);

        DB::table('inventory_item_locations')->updateOrInsert(
            ['inventory_item_id' => $item->id, 'inventory_location_id' => $dest->id],
            ['updated_at' => now(), 'created_at' => now()]
        );

        DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $dest->id)
            ->increment('quantity', $qty);

        InventoryTransaction::create([
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
            'reference_type' => 'room_par',
        ]);

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);
    }

    public function index()
    {
        $this->checkPermission('manage-inventory');

        return response()->json(
            RoomParTemplate::with(['roomType', 'lines.inventoryItem'])
                ->orderBy('room_type_id')
                ->orderBy('name')
                ->get()
        );
    }

    public function show(RoomParTemplate $template)
    {
        $this->checkPermission('manage-inventory');

        return response()->json($template->load(['roomType', 'lines.inventoryItem']));
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'name' => 'nullable|string|max:120',
            'lines' => 'nullable|array',
            'lines.*.kind' => 'required|string|in:amenity,minibar,asset',
            'lines.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'lines.*.par_qty' => 'required|numeric|min:0',
            'lines.*.meta' => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            /** @var RoomParTemplate $template */
            $template = RoomParTemplate::firstOrCreate(
                [
                    'room_type_id' => $validated['room_type_id'],
                    'name' => trim((string) ($validated['name'] ?? 'Default')) ?: 'Default',
                ],
                []
            );

            $template->lines()->delete();

            foreach (($validated['lines'] ?? []) as $ln) {
                $qty = (float) ($ln['par_qty'] ?? 0);
                if ($qty <= 0) continue;

                RoomParTemplateLine::create([
                    'template_id' => $template->id,
                    'kind' => $ln['kind'],
                    'inventory_item_id' => (int) $ln['inventory_item_id'],
                    'par_qty' => $qty,
                    'meta' => $ln['meta'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json($template->fresh()->load(['roomType', 'lines.inventoryItem']), 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, RoomParTemplate $template)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'name' => 'nullable|string|max:120',
            'lines' => 'nullable|array',
            'lines.*.kind' => 'required|string|in:amenity,minibar,asset',
            'lines.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'lines.*.par_qty' => 'required|numeric|min:0',
            'lines.*.meta' => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            if (array_key_exists('name', $validated)) {
                $template->name = trim((string) ($validated['name'] ?? '')) ?: $template->name;
                $template->save();
            }

            if (array_key_exists('lines', $validated)) {
                $template->lines()->delete();
                foreach (($validated['lines'] ?? []) as $ln) {
                    $qty = (float) ($ln['par_qty'] ?? 0);
                    if ($qty <= 0) continue;
                    RoomParTemplateLine::create([
                        'template_id' => $template->id,
                        'kind' => $ln['kind'],
                        'inventory_item_id' => (int) $ln['inventory_item_id'],
                        'par_qty' => $qty,
                        'meta' => $ln['meta'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json($template->fresh()->load(['roomType', 'lines.inventoryItem']));
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create Store Requests to fill room locations to the par template.
     * Note: StoreRequestController's semantics treat to_location_id as the source store.
     */
    public function fill(Request $request)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'template_id' => 'nullable|exists:room_par_templates,id',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'source_location_id' => 'nullable|exists:inventory_locations,id',
            'notes' => 'nullable|string|max:2000',
            'shortfall_only' => 'nullable|boolean',
        ]);

        $shortfallOnly = (bool) ($validated['shortfall_only'] ?? true);
        $explicitTemplateId = ! empty($validated['template_id']) ? (int) $validated['template_id'] : null;

        $source = null;
        if (! empty($validated['source_location_id'])) {
            $source = InventoryLocation::findOrFail((int) $validated['source_location_id']);
        } else {
            $source = InventoryLocation::where('type', '=', 'main_store', 'and')->first();
        }
        if (! $source) {
            return response()->json(['message' => 'Source store location not found.'], 422);
        }

        $rooms = Room::query()
            ->whereIn('id', $validated['room_ids'], 'and', false)
            ->get(['id', 'room_number', 'room_type_id', 'par_template_id']);

        $created = [];
        $skipped = [];

        DB::beginTransaction();
        try {
            foreach ($rooms as $room) {
                $template = null;
                if ($explicitTemplateId) {
                    $template = RoomParTemplate::with('lines')->find($explicitTemplateId);
                    if ($template && (int) $template->room_type_id !== (int) $room->room_type_id) {
                        $template = null;
                    }
                } else {
                    $template = RoomParInventoryContext::resolveTemplateForRoom($room);
                    if ($template && ! $template->relationLoaded('lines')) {
                        $template->load('lines');
                    }
                }

                if (! $template) {
                    $skipped[] = [
                        'room_id' => (int) $room->id,
                        'room_number' => (string) $room->room_number,
                        'reason' => 'No stock template assigned to this room.',
                    ];
                    continue;
                }

                $roomLoc = RoomParInventoryContext::ensureRoomLocation($room);
                $onHand = [];
                if ($shortfallOnly) {
                    $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
                    $onHand = $ctx['on_hand_by_item_id'] ?? [];
                }

                $lineQtys = [];
                foreach ($template->lines as $ln) {
                    $parQty = (float) ($ln->par_qty ?? 0);
                    if ($parQty <= 0) {
                        continue;
                    }
                    $itemId = (int) $ln->inventory_item_id;
                    $qty = $shortfallOnly
                        ? max(0, $parQty - (float) ($onHand[$itemId] ?? 0))
                        : $parQty;
                    if ($qty <= 0) {
                        continue;
                    }
                    $lineQtys[] = ['inventory_item_id' => $itemId, 'quantity' => $qty];
                }

                if ($shortfallOnly && empty($lineQtys)) {
                    continue;
                }

                $defaultNotes = $shortfallOnly
                    ? ('Restock to PAR (Template: ' . $template->name . ')')
                    : ('Initial Room Setup (Template: ' . $template->name . ')');

                $sr = StoreRequest::create([
                    'request_number' => 'REQ-' . date('Ymd') . '-' . strtoupper(uniqid()),
                    // destination (room)
                    'from_location_id' => $roomLoc->id,
                    // source (Main Store)
                    'to_location_id' => $source->id,
                    'department_id' => $roomLoc->department_id,
                    'requested_by' => Auth::id(),
                    'status' => 'pending',
                    'notes' => trim((string) ($validated['notes'] ?? '')) ?: $defaultNotes,
                    'requested_at' => now(),
                ]);

                foreach ($lineQtys as $row) {
                    StoreRequestItem::create([
                        'store_request_id' => $sr->id,
                        'inventory_item_id' => $row['inventory_item_id'],
                        'quantity_requested' => $row['quantity'],
                        'quantity_issued' => 0,
                        'quantity_pending_acceptance' => 0,
                    ]);
                }

                $created[] = $sr->load(['fromLocation', 'toLocation', 'items.item']);
            }

            if ($shortfallOnly && empty($created)) {
                DB::rollBack();

                return response()->json(['message' => 'All selected rooms are already at or above PAR.'], 422);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'count' => count($created),
            'store_requests' => $created,
        ]);
    }
}
