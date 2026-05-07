<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $query = Room::with(['roomType', 'connectedRoom']);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-rooms');
        $validated = $request->validate([
            'room_number' => 'required|string|unique:rooms,room_number',
            'room_type_id' => 'required|exists:room_types,id',
            'is_active' => 'nullable|boolean',
            'status' => 'required|in:available,occupied,maintenance,dirty,cleaning',
            'floor' => 'nullable|string',
            'bed_config' => 'nullable|string',
            'amenities' => 'nullable|array',
            'intercom_extension' => 'nullable|string|max:50',
            'view_type' => 'nullable|string|in:standard,garden_view,sea_view,pool_view',
            'is_smoking_allowed' => 'nullable|boolean',
            'connected_room_id' => 'nullable|exists:rooms,id',
            'internal_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $room = Room::create($validated);

        return response()->json($room, 201);
    }

    public function show(Room $room)
    {
        return $room->load('roomType');
    }

    public function update(Request $request, Room $room)
    {
        $this->checkPermission('manage-rooms');
        $validated = $request->validate([
            'room_number' => 'string|unique:rooms,room_number,' . $room->id,
            'room_type_id' => 'exists:room_types,id',
            'is_active' => 'nullable|boolean',
            'status' => 'in:available,occupied,maintenance,dirty,cleaning',
            'floor' => 'nullable|string',
            'bed_config' => 'nullable|string',
            'amenities' => 'nullable|array',
            'intercom_extension' => 'nullable|string|max:50',
            'view_type' => 'nullable|string|in:standard,garden_view,sea_view,pool_view',
            'is_smoking_allowed' => 'nullable|boolean',
            'connected_room_id' => 'nullable|exists:rooms,id',
            'internal_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $room->update($validated);

        return response()->json($room);
    }

    public function destroy(Room $room)
    {
        $this->checkPermission('manage-rooms');
        try {
            Room::destroy($room->id);

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete room as it has historical bookings or active transactions.'], 409);
            }
            throw $e;
        }
    }

    /**
     * Ensure each room has an inventory location (kind=room, room_id set).
     */
    public function syncInventoryLocations(Request $request)
    {
        $this->checkPermission('manage-rooms');

        $validated = $request->validate([
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'integer|exists:rooms,id',
            'only_active' => 'nullable|boolean',
        ]);

        $onlyActive = (bool) ($validated['only_active'] ?? true);

        $q = Room::query()->select(['id', 'room_number', 'is_active']);
        if (! empty($validated['room_ids'])) {
            $q->whereIn('id', $validated['room_ids']);
        } elseif ($onlyActive) {
            $q->where('is_active', '=', true, 'and');
        }
        $rooms = $q->orderBy('room_number')->get();

        $created = 0;
        $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($rooms as $room) {
                $name = 'Room ' . trim((string) $room->room_number);
                $existing = InventoryLocation::where('room_id', '=', $room->id, 'and')->first();
                if (! $existing) {
                    // If a name-clash exists from older data, suffix with room id.
                    $finalName = $name;
                    $nameClash = InventoryLocation::where('name', '=', $finalName, 'and')->exists();
                    if ($nameClash) {
                        $finalName = $name . ' (' . $room->id . ')';
                    }

                    InventoryLocation::create([
                        'name' => $finalName,
                        'type' => 'satellite',
                        'kind' => 'room',
                        'room_id' => $room->id,
                        'is_active' => true,
                    ]);
                    $created++;
                    continue;
                }

                $desiredName = $name;
                $patch = [];
                if (($existing->kind ?? null) !== 'room') $patch['kind'] = 'room';
                if (($existing->room_id ?? null) !== $room->id) $patch['room_id'] = $room->id;
                if (($existing->name ?? '') === '' || Str::startsWith((string) $existing->name, 'Room ')) {
                    // Keep custom names if set; otherwise align with convention.
                    $patch['name'] = $desiredName;
                }
                if (! empty($patch)) {
                    $existing->update($patch);
                    $updated++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'rooms_scanned' => $rooms->count(),
            'created' => $created,
            'updated' => $updated,
        ]);
    }
}
