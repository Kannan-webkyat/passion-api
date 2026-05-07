<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\HousekeepingJob;
use App\Models\HousekeepingJobLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\RestaurantMaster;
use App\Models\RoomParTemplate;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HousekeepingController extends Controller
{
    private function checkPermission(string $permission): void
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * List active housekeeping status blocks (dirty / in cleaning).
     * By default returns all active blocks so rooms stay visible even when the dirty window is tied to
     * a future checkout day on the chart. Pass overlap_only=1 to restrict to blocks overlapping `date`.
     */
    public function index(Request $request)
    {
        $this->checkPermission('view-rooms');
        $validated = $request->validate([
            'date' => 'nullable|date',
            'floor' => 'nullable|string|max:50',
            'room_type_id' => 'nullable|exists:room_types,id',
            'hk_status' => 'nullable|in:dirty,cleaning,inspected,all',
        ]);

        $d = isset($validated['date'])
            ? Carbon::parse($validated['date'])->toDateString()
            : Carbon::today()->toDateString();
        $dNext = Carbon::parse($d)->addDay()->toDateString();
        $hkStatus = $validated['hk_status'] ?? 'all';

        $statuses = $hkStatus === 'all' ? ['dirty', 'cleaning', 'inspected'] : [$hkStatus];

        // List all active HK blocks — checkout may be scheduled on a future calendar day while the room
        // is already dirty today; strict date overlap would hide those from housekeeping.
        $query = RoomStatusBlock::query()
            ->with(['room.roomType'])
            ->where('is_active', '=', true, 'and')
            ->whereIn('status', $statuses);

        $overlapOnly = $request->boolean('overlap_only');
        if ($overlapOnly) {
            $query->where('start_date', '<', $dNext)
                ->where('end_date', '>', $d);
        }

        $query->whereHas('room', function ($q) use ($validated) {
            if (! empty($validated['floor'])) {
                $q->where('floor', '=', $validated['floor'], 'and');
            }
            if (! empty($validated['room_type_id'])) {
                $q->where('room_type_id', '=', $validated['room_type_id'], 'and');
            }
        });

        $blocks = $query
            ->orderBy('room_id')
            ->orderBy('id')
            ->get();

        return response()->json([
            'date' => $d,
            'blocks' => $blocks,
        ]);
    }

    /**
     * Housekeeping catalog for the sidebar:
     * - amenities: inventory items under Guest Amenities
     * - minibar: direct-sale inventory items that have a linked menu_item_id (so we can room-charge via POS)
     * - checklist/assets templates: static for now
     */
    public function catalog()
    {
        $this->checkPermission('view-rooms');

        $validated = request()->validate([
            'room_id' => 'nullable|integer|exists:rooms,id',
        ]);

        $amenityCats = \App\Models\InventoryCategory::query()
            ->where('name', '=', 'Guest Amenities (Consumables)', 'and')
            ->orWhere('parent_id', function ($q) {
                $q->select('id')
                    ->from('inventory_categories')
                    ->where('name', '=', 'Guest Amenities (Consumables)', 'and')
                    ->limit(1);
            })
            ->pluck('id')
            ->toArray();

        $amenities = InventoryItem::query()
            ->whereIn('category_id', $amenityCats, 'and', false)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category_id']);

        // Minibar items: direct-sale inventory items with a linked menu item for POS posting
        $minibar = InventoryItem::query()
            ->where('is_direct_sale', '=', true, 'and')
            ->with(['category:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category_id']);

        $menuCols = ['id', 'inventory_item_id', 'name', 'price'];
        if (Schema::hasColumn('menu_items', 'tax_rate')) {
            $menuCols[] = 'tax_rate';
        }
        $menuByInventory = MenuItem::query()
            ->whereNotNull('inventory_item_id', 'and')
            ->get($menuCols);

        $menuMap = $menuByInventory->keyBy(fn($m) => (int) $m->inventory_item_id);

        $minibarPayload = $minibar->map(function ($i) use ($menuMap) {
            $m = $menuMap[(int) $i->id] ?? null;
            return [
                'inventory_item_id' => (int) $i->id,
                'sku' => (string) $i->sku,
                'name' => (string) $i->name,
                'category' => $i->category?->name,
                'menu_item_id' => $m ? (int) $m->id : null,
                'menu_price' => $m ? (float) $m->price : null,
                'menu_tax_rate' => $m && Schema::hasColumn('menu_items', 'tax_rate') ? (float) ($m->tax_rate ?? 0) : null,
            ];
        })->values();

        $assets = [
            ['key' => 'coffee_maker', 'label' => 'Coffee maker'],
            ['key' => 'electric_kettle', 'label' => 'Electric kettle'],
            ['key' => 'hair_dryer', 'label' => 'Hair dryer'],
            ['key' => 'television', 'label' => 'Television'],
            ['key' => 'mini_fridge', 'label' => 'Mini-fridge'],
            ['key' => 'safe_deposit_box', 'label' => 'Safe-deposit box'],
            ['key' => 'iron', 'label' => 'Iron'],
            ['key' => 'ironing_board', 'label' => 'Ironing board'],
        ];

        $checklist = [
            ['key' => 'change_sheets', 'label' => 'Change sheets'],
            ['key' => 'clean_bathroom', 'label' => 'Clean bathroom'],
            ['key' => 'vacuum_floor', 'label' => 'Vacuum / mop floor'],
            ['key' => 'dust_surfaces', 'label' => 'Dust surfaces'],
            ['key' => 'trash_removed', 'label' => 'Remove trash'],
        ];

        return response()->json([
            'amenities' => $amenities,
            'minibar' => $minibarPayload,
            'assets' => $assets,
            'checklist' => $checklist,
            'room_context' => $this->roomContextPayload(isset($validated['room_id']) ? (int) $validated['room_id'] : null),
        ]);
    }

    private function roomContextPayload(?int $roomId): ?array
    {
        if (! $roomId) return null;

        $room = Room::with('roomType')->find($roomId, ['id', 'room_number', 'room_type_id']);
        if (! $room) return null;

        $roomLoc = InventoryLocation::where('room_id', '=', $room->id, 'and')->first();

        $template = RoomParTemplate::where('room_type_id', '=', $room->room_type_id, 'and')
            ->orderBy('name', 'asc')
            ->with('lines.inventoryItem')
            ->first();

        $parLines = [];
        if ($template) {
            foreach ($template->lines as $ln) {
                $parLines[] = [
                    'kind' => $ln->kind,
                    'inventory_item_id' => (int) $ln->inventory_item_id,
                    'item_name' => (string) ($ln->inventoryItem?->name ?? ''),
                    'sku' => (string) ($ln->inventoryItem?->sku ?? ''),
                    'is_direct_sale' => (bool) ($ln->inventoryItem?->is_direct_sale ?? false),
                    'par_qty' => (float) ($ln->par_qty ?? 0),
                ];
            }
        }

        $onHand = [];
        $onHandItems = [];
        if ($roomLoc) {
            $rows = DB::table('inventory_item_locations')
                ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                ->pluck('quantity', 'inventory_item_id');
            foreach ($rows as $itemId => $qty) {
                $onHand[(int) $itemId] = (float) $qty;
            }

            $positiveIds = [];
            foreach ($onHand as $iid => $q) {
                if ((float) $q > 0) $positiveIds[] = (int) $iid;
            }
            if (! empty($positiveIds)) {
                $items = InventoryItem::query()
                    ->whereIn('id', $positiveIds, 'and', false)
                    ->get(['id', 'name', 'sku', 'is_direct_sale']);
                foreach ($items as $it) {
                    $onHandItems[] = [
                        'inventory_item_id' => (int) $it->id,
                        'name' => (string) $it->name,
                        'sku' => (string) $it->sku,
                        'is_direct_sale' => (bool) $it->is_direct_sale,
                        'qty' => (float) ($onHand[(int) $it->id] ?? 0),
                    ];
                }
            }
        }

        return [
            'room_id' => (int) $room->id,
            'room_number' => (string) $room->room_number,
            'room_location_id' => $roomLoc ? (int) $roomLoc->id : null,
            'par_template_id' => $template ? (int) $template->id : null,
            'par_lines' => $parLines,
            'on_hand_by_item_id' => $onHand,
            'on_hand_items' => $onHandItems,
        ];
    }

    /**
     * Transition dirty → cleaning (room chart shows Cleaning).
     */
    public function startCleaning(RoomStatusBlock $roomStatusBlock)
    {
        $this->checkPermission('manage-rooms');

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if ($roomStatusBlock->status !== 'dirty') {
            return response()->json([
                'message' => 'Only rooms marked dirty can start cleaning.',
            ], 422);
        }

        $roomStatusBlock->update(['status' => 'cleaning']);
        Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'cleaning']);

        return response()->json($roomStatusBlock->load('room.roomType'));
    }

    /**
     * Draft/update housekeeping job lines while cleaning is in progress.
     */
    public function upsertJob(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->checkPermission('manage-rooms');

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if (! in_array($roomStatusBlock->status, ['cleaning', 'dirty'], true)) {
            return response()->json(['message' => 'This room is not in a housekeeping workflow state.'], 422);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
            'checklist' => 'nullable|array',
            'checklist.*.key' => 'required_with:checklist|string|max:100',
            'checklist.*.label' => 'required_with:checklist|string|max:255',
            'checklist.*.done' => 'required_with:checklist|boolean',
            'amenities' => 'nullable|array',
            'amenities.*.inventory_item_id' => 'required_with:amenities|exists:inventory_items,id',
            'amenities.*.qty' => 'required_with:amenities|numeric|min:0',
            'amenities.*.found_qty' => 'nullable|numeric|min:0',
            'minibar' => 'nullable|array',
            'minibar.*.inventory_item_id' => 'required_with:minibar|exists:inventory_items,id',
            'minibar.*.menu_item_id' => 'nullable|exists:menu_items,id',
            'minibar.*.qty' => 'required_with:minibar|numeric|min:0',
            'minibar.*.found_qty' => 'nullable|numeric|min:0',
            'assets' => 'nullable|array',
            'assets.*.key' => 'required_with:assets|string|max:100',
            'assets.*.label' => 'required_with:assets|string|max:255',
            'assets.*.status' => 'required_with:assets|string|in:ok,needs_repair,missing',
            'assets.*.note' => 'nullable|string|max:500',
        ]);

        $userId = Auth::id();

        DB::beginTransaction();
        try {
            /** @var HousekeepingJob $job */
            $job = HousekeepingJob::firstOrCreate(
                ['room_status_block_id' => $roomStatusBlock->id],
                [
                    'room_id' => $roomStatusBlock->room_id,
                    'status' => 'in_progress',
                    'started_by' => $userId,
                ]
            );

            $job->update([
                'remarks' => $validated['remarks'] ?? null,
            ]);

            // Replace draft lines (simple and predictable)
            $job->lines()->delete();

            foreach (($validated['checklist'] ?? []) as $it) {
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'checklist',
                    'qty' => 0,
                    'meta' => [
                        'key' => $it['key'],
                        'label' => $it['label'],
                        'done' => (bool) $it['done'],
                    ],
                ]);
            }

            foreach (($validated['amenities'] ?? []) as $it) {
                $qty = (float) ($it['qty'] ?? 0);
                if ($qty <= 0) continue;
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'amenity',
                    'inventory_item_id' => (int) $it['inventory_item_id'],
                    'qty' => $qty,
                    'meta' => isset($it['found_qty']) ? ['found_qty' => (float) $it['found_qty']] : null,
                ]);
            }

            foreach (($validated['minibar'] ?? []) as $it) {
                $qty = (float) ($it['qty'] ?? 0);
                if ($qty <= 0) continue;
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'minibar',
                    'inventory_item_id' => (int) $it['inventory_item_id'],
                    'menu_item_id' => $it['menu_item_id'] ? (int) $it['menu_item_id'] : null,
                    'qty' => $qty,
                    'meta' => isset($it['found_qty']) ? ['found_qty' => (float) $it['found_qty']] : null,
                ]);
            }

            foreach (($validated['assets'] ?? []) as $it) {
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'asset',
                    'qty' => 0,
                    'meta' => [
                        'key' => $it['key'],
                        'label' => $it['label'],
                        'status' => $it['status'],
                        'note' => $it['note'] ?? null,
                    ],
                ]);
            }

            DB::commit();

            return response()->json($job->fresh()->load('lines'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Finish cleaning: deduct stock, post minibar to folio (POS room_charge), and move to inspected.
     */
    public function finish(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->checkPermission('manage-rooms');

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if ($roomStatusBlock->status !== 'cleaning') {
            return response()->json([
                'message' => 'Start cleaning before finishing.',
            ], 422);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
        ]);

        $userId = Auth::id();

        DB::beginTransaction();
        try {
            /** @var HousekeepingJob $job */
            $job = HousekeepingJob::firstOrCreate(
                ['room_status_block_id' => $roomStatusBlock->id],
                [
                    'room_id' => $roomStatusBlock->room_id,
                    'status' => 'in_progress',
                    'started_by' => $userId,
                ]
            );
            if (array_key_exists('remarks', $validated)) {
                $job->remarks = $validated['remarks'];
            }
            $job->finished_by = $userId;

            $job->load('lines');

            $hkStore = InventoryLocation::where('name', '=', 'Housekeeping Store', 'and')->first()
                ?: InventoryLocation::where('type', '=', 'main_store', 'and')->first();

            if (! $hkStore) {
                return response()->json(['message' => 'No inventory location available for housekeeping.'], 422);
            }

            $assetProblem = false;
            $assetNotes = [];

            foreach ($job->lines as $ln) {
                if ($ln->kind === 'asset') {
                    $st = (string) (($ln->meta['status'] ?? '') ?: '');
                    if (in_array($st, ['needs_repair', 'missing'], true)) {
                        $assetProblem = true;
                        $assetNotes[] = ($ln->meta['label'] ?? $ln->meta['key'] ?? 'Asset') . ': ' . $st;
                    }
                }
            }

            // Room-location logic:
            // - staff enters found_qty (what is still in-room)
            // - system computes consumed = max(0, par - found)
            // - for amenities/minibar, consumption is deducted from Room location (if it exists)
            // - replenishment is an explicit transfer from HK Store -> Room location using ln.qty

            $roomLoc = InventoryLocation::where('room_id', '=', $roomStatusBlock->room_id, 'and')->first();
            $roomTypeId = (int) (Room::where('id', '=', $roomStatusBlock->room_id, 'and')->value('room_type_id') ?? 0);
            $template = RoomParTemplate::where('room_type_id', '=', $roomTypeId, 'and')
                ->orderBy('name', 'asc')
                ->with('lines')
                ->first();
            $parMap = [];
            if ($template) {
                foreach ($template->lines as $pl) {
                    $parMap[(int) $pl->inventory_item_id] = (float) ($pl->par_qty ?? 0);
                }
            }

            $lines = $job->lines->whereIn('kind', ['amenity', 'minibar'])->values();
            foreach ($lines as $ln) {
                if (! $ln->inventory_item_id) continue;
                $itemId = (int) $ln->inventory_item_id;

                $par = (float) ($parMap[$itemId] ?? 0);
                $found = isset($ln->meta['found_qty']) ? (float) $ln->meta['found_qty'] : null;
                $consumed = ($found === null) ? null : max(0.0, $par - $found);

                // 1) Replenishment transfer (HK Store -> Room location)
                $replenishQty = (float) ($ln->qty ?? 0);
                if ($replenishQty > 0) {
                    // Deduct from HK store
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $itemId, 'inventory_location_id' => $hkStore->id],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', '=', $itemId, 'and')
                        ->where('inventory_location_id', '=', $hkStore->id, 'and')
                        ->decrement('quantity', $replenishQty);

                    // Add to room location if available
                    if ($roomLoc) {
                        DB::table('inventory_item_locations')->updateOrInsert(
                            ['inventory_item_id' => $itemId, 'inventory_location_id' => $roomLoc->id],
                            ['updated_at' => now(), 'created_at' => now()]
                        );
                        DB::table('inventory_item_locations')
                            ->where('inventory_item_id', '=', $itemId, 'and')
                            ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                            ->increment('quantity', $replenishQty);
                    }

                    $item = InventoryItem::lockForUpdate()->find($itemId);
                    $unitCost = floatval($item?->cost_price ?? 0) / floatval($item?->conversion_factor ?: 1);
                    $refId = (string) Str::uuid();

                    InventoryTransaction::create([
                        'inventory_item_id' => $itemId,
                        'inventory_location_id' => $hkStore->id,
                        'type' => 'out',
                        'quantity' => $replenishQty,
                        'unit_cost' => round($unitCost, 4),
                        'total_cost' => round($replenishQty * $unitCost, 2),
                        'reason' => 'HK replenish to room',
                        'notes' => 'Housekeeping replenish',
                        'user_id' => $userId,
                        'reference_id' => $refId,
                        'reference_type' => 'housekeeping',
                    ]);
                    if ($roomLoc) {
                        InventoryTransaction::create([
                            'inventory_item_id' => $itemId,
                            'inventory_location_id' => $roomLoc->id,
                            'type' => 'in',
                            'quantity' => $replenishQty,
                            'unit_cost' => round($unitCost, 4),
                            'total_cost' => round($replenishQty * $unitCost, 2),
                            'reason' => 'HK replenish to room',
                            'notes' => 'Housekeeping replenish',
                            'user_id' => $userId,
                            'reference_id' => $refId,
                            'reference_type' => 'housekeeping',
                        ]);
                    }

                    InventoryItem::syncStoredCurrentStockFromLocations($itemId);
                }

                // 2) Consumption deduction from room location (baseline - found)
                if ($roomLoc && $consumed !== null && $consumed > 0) {
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $itemId, 'inventory_location_id' => $roomLoc->id],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', '=', $itemId, 'and')
                        ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                        ->decrement('quantity', $consumed);

                    $item = InventoryItem::lockForUpdate()->find($itemId);
                    $unitCost = floatval($item?->cost_price ?? 0) / floatval($item?->conversion_factor ?: 1);
                    InventoryTransaction::create([
                        'inventory_item_id' => $itemId,
                        'inventory_location_id' => $roomLoc->id,
                        'type' => 'out',
                        'quantity' => $consumed,
                        'unit_cost' => round($unitCost, 4),
                        'total_cost' => round($consumed * $unitCost, 2),
                        'reason' => 'Room consumption',
                        'notes' => 'Consumed (par vs found) during housekeeping',
                        'user_id' => $userId,
                        'reference_id' => (string) Str::uuid(),
                        'reference_type' => 'housekeeping',
                    ]);
                    InventoryItem::syncStoredCurrentStockFromLocations($itemId);
                }
            }

            // Minibar billing via POS room_charge
            $minibarLines = $job->lines->where('kind', 'minibar')->values();
            if ($minibarLines->isNotEmpty()) {
                $booking = $this->activeBookingForRoom($roomStatusBlock->room_id);
                if ($booking) {
                    $this->postMinibarRoomCharge($booking, $minibarLines, $userId);
                }
            }

            // If any asset is missing/broken, put room on maintenance until cleared.
            if ($assetProblem) {
                $note = 'HK asset issue: ' . implode('; ', array_slice($assetNotes, 0, 3));
                RoomStatusBlock::create([
                    'room_id' => $roomStatusBlock->room_id,
                    'status' => 'maintenance',
                    'start_date' => Carbon::today()->toDateString(),
                    'end_date' => Carbon::today()->addYears(5)->toDateString(),
                    'note' => substr($note, 0, 255),
                    'is_active' => true,
                    'created_by' => $userId,
                ]);
                Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'maintenance']);
                $job->issues_summary = substr($note, 0, 500);
            } else {
                $roomStatusBlock->update(['status' => 'inspected']);
                Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'inspected']);
                $job->status = 'inspected';
            }

            $job->save();

            DB::commit();

            return response()->json([
                'message' => 'Cleaning finished.',
                'block' => $roomStatusBlock->fresh()->load('room.roomType'),
                'job' => $job->fresh()->load('lines'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Supervisor step: inspected → available.
     */
    public function markInspected(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->checkPermission('manage-rooms');

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }
        if ($roomStatusBlock->status !== 'inspected') {
            return response()->json(['message' => 'Room is not awaiting inspection.'], 422);
        }

        $roomStatusBlock->update(['is_active' => false]);
        Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'available']);

        $job = HousekeepingJob::where('room_status_block_id', $roomStatusBlock->id)->first();
        if ($job) {
            $job->status = 'completed';
            $job->save();
        }

        return response()->json([
            'message' => 'Room inspected and available.',
            'block' => $roomStatusBlock->fresh()->load('room.roomType'),
        ]);
    }

    private function activeBookingForRoom(int $roomId): ?Booking
    {
        $now = now();

        // Prefer segments (split-stays)
        $seg = BookingSegment::query()
            ->where('room_id', '=', $roomId, 'and')
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<=', $now)
            ->where('check_out_at', '>=', $now)
            ->with('booking')
            ->orderByDesc('id')
            ->first();

        $b = $seg?->booking;
        if ($b && $b->status === 'checked_in') return $b;

        // Fallback: booking.room_id for legacy
        return Booking::query()
            ->where('room_id', '=', $roomId, 'and')
            ->where('status', '=', 'checked_in', 'and')
            ->orderByDesc('id')
            ->first();
    }

    private function postMinibarRoomCharge(Booking $booking, $minibarLines, ?int $userId): void
    {
        $restaurant = RestaurantMaster::where('name', '=', 'OTTAAL', 'and')->first()
            ?: RestaurantMaster::query()->orderBy('id', 'asc')->first();
        if (! $restaurant) {
            return;
        }

        $hasPosOrders = static function (string $col): bool {
            return Schema::hasColumn('pos_orders', $col);
        };
        $hasPosOrderItems = static function (string $col): bool {
            return Schema::hasColumn('pos_order_items', $col);
        };
        $hasPosPayments = static function (string $col): bool {
            return Schema::hasColumn('pos_payments', $col);
        };

        // Create POS order in paid state with room_charge payment.
        $orderData = [
            'restaurant_id' => $restaurant->id,
            'covers' => 1,
            'status' => 'paid',
            'opened_at' => now(),
            'closed_at' => now(),
            'notes' => 'Minibar posting (housekeeping)',
        ];
        if ($hasPosOrders('order_type')) $orderData['order_type'] = 'room_service';
        if ($hasPosOrders('table_id')) $orderData['table_id'] = null;
        if ($hasPosOrders('business_date')) $orderData['business_date'] = Carbon::today()->toDateString();
        if ($hasPosOrders('waiter_id')) $orderData['waiter_id'] = null;
        if ($hasPosOrders('opened_by')) $orderData['opened_by'] = $userId;
        if ($hasPosOrders('room_id')) $orderData['room_id'] = $booking->room_id;
        if ($hasPosOrders('booking_id')) $orderData['booking_id'] = $booking->id;
        if ($hasPosOrders('customer_name')) $orderData['customer_name'] = $booking->guest_name ?? trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''));
        if ($hasPosOrders('customer_phone')) $orderData['customer_phone'] = $booking->phone ?? null;

        $order = PosOrder::create($orderData);

        $subtotal = 0.0;
        $taxAmount = 0.0;
        $total = 0.0;

        foreach ($minibarLines as $ln) {
            $qty = (float) ($ln->qty ?? 0);
            if ($qty <= 0) continue;

            $menuItemId = (int) ($ln->menu_item_id ?? 0);
            if ($menuItemId <= 0) continue;

            $menu = MenuItem::find($menuItemId, ['id', 'name', 'price', 'tax_rate']);
            if (! $menu) continue;

            $unit = (float) ($menu->price ?? 0);
            $rate = (float) ($menu->tax_rate ?? 0);
            $lineSubtotal = $unit * $qty;
            $lineTax = $rate > 0 ? ($lineSubtotal * $rate / 100) : 0;
            $lineTotal = $lineSubtotal + $lineTax;

            $oi = [
                'order_id' => $order->id,
                'menu_item_id' => $menu->id,
                'quantity' => (int) round($qty),
                'unit_price' => round($unit, 2),
                'tax_rate' => round($rate, 2),
                'line_total' => round($lineTotal, 2),
                'kot_sent' => false,
                'notes' => 'Minibar (HK)',
            ];
            if ($hasPosOrderItems('price_tax_inclusive')) $oi['price_tax_inclusive'] = false;
            if ($hasPosOrderItems('status')) $oi['status'] = 'active';
            if ($hasPosOrderItems('inventory_deducted')) $oi['inventory_deducted'] = true;

            PosOrderItem::create($oi);

            $subtotal += $lineSubtotal;
            $taxAmount += $lineTax;
            $total += $lineTotal;
        }

        $order->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($total, 2),
        ]);

        $pay = [
            'order_id' => $order->id,
            'method' => 'room_charge',
            'amount' => round($total, 2),
            'paid_at' => now(),
            'received_by' => $userId,
        ];
        if ($hasPosPayments('business_date')) $pay['business_date'] = Carbon::today();
        PosPayment::create($pay);

        // Keep booking.extra_charges aligned (UI uses this as “posted to room” total).
        $booking->extra_charges = (float) ($booking->extra_charges ?? 0) + round($total, 2);
        $booking->save();
    }

    /**
     * Backward compatible endpoint: direct cleaning → available.
     * (Kept for any older UI that still calls mark-cleaned.)
     */
    public function markCleaned(RoomStatusBlock $roomStatusBlock)
    {
        $this->checkPermission('manage-rooms');

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if ($roomStatusBlock->status !== 'cleaning') {
            return response()->json([
                'message' => 'Start cleaning before marking the room as cleaned.',
            ], 422);
        }

        $roomStatusBlock->update(['is_active' => false]);
        Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'available']);

        return response()->json([
            'message' => 'Room marked as cleaned.',
            'block' => $roomStatusBlock->fresh()->load('room.roomType'),
        ]);
    }
}
