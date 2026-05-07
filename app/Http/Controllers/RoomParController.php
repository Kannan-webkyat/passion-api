<?php

namespace App\Http\Controllers;

use App\Models\RoomParTemplate;
use App\Models\RoomParTemplateLine;
use App\Models\StoreRequest;
use App\Models\StoreRequestItem;
use App\Models\InventoryLocation;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoomParController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
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
            'template_id' => 'required|exists:room_par_templates,id',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'source_location_id' => 'nullable|exists:inventory_locations,id',
            'notes' => 'nullable|string|max:2000',
        ]);

        $template = RoomParTemplate::with('lines')->findOrFail((int) $validated['template_id']);

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
            ->get(['id', 'room_number']);

        $created = [];

        DB::beginTransaction();
        try {
            foreach ($rooms as $room) {
                // Ensure room inventory location exists
                $roomLoc = InventoryLocation::where('room_id', '=', $room->id, 'and')->first();
                if (! $roomLoc) {
                    $baseName = 'Room ' . trim((string) $room->room_number);
                    $finalName = $baseName;
                    if (InventoryLocation::where('name', '=', $finalName, 'and')->exists()) {
                        $finalName = $baseName . ' (' . $room->id . ')';
                    }
                    $roomLoc = InventoryLocation::create([
                        'name' => $finalName,
                        'type' => 'satellite',
                        'kind' => 'room',
                        'room_id' => $room->id,
                        'is_active' => true,
                    ]);
                }

                $sr = StoreRequest::create([
                    'request_number' => 'REQ-' . date('Ymd') . '-' . strtoupper(uniqid()),
                    // destination (room)
                    'from_location_id' => $roomLoc->id,
                    // source (Main Store)
                    'to_location_id' => $source->id,
                    'department_id' => $roomLoc->department_id,
                    'requested_by' => Auth::id(),
                    'status' => 'pending',
                    'notes' => trim((string) ($validated['notes'] ?? '')) ?: ('Initial Room Setup (Template: ' . $template->name . ')'),
                    'requested_at' => now(),
                ]);

                foreach ($template->lines as $ln) {
                    $qty = (float) ($ln->par_qty ?? 0);
                    if ($qty <= 0) continue;
                    StoreRequestItem::create([
                        'store_request_id' => $sr->id,
                        'inventory_item_id' => $ln->inventory_item_id,
                        'quantity_requested' => $qty,
                        'quantity_issued' => 0,
                        'quantity_pending_acceptance' => 0,
                    ]);
                }

                $created[] = $sr->load(['fromLocation', 'toLocation', 'items.item']);
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
