<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Models\InventoryLocation;
use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    use AuthorizesSpatiePermissions;

    public function index(Request $request)
    {
        // Read: Rooms master, room chart, bookings, housekeeping (not a substitute for create/edit/delete).
        $this->authorizePermissions([
            'rooms-view',
            'view-rooms',
            'reservation-view',
            'reservation',
        ]);

        $query = Room::with(['roomType', 'connectedRoom', 'parTemplate']);
        if ($request->boolean('archived')) {
            return response()->json($query->onlyTrashed()->orderByDesc('deleted_at')->get());
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->authorizePermissions(['rooms-create']);
        if ($archived = $this->archivedRoomResponse($request->input('room_number'))) {
            return $archived;
        }
        $validated = $request->validate([
            'room_number' => 'required|string|unique:rooms,room_number',
            'room_type_id' => ['required', Rule::exists('room_types', 'id')->whereNull('deleted_at')],
            'is_active' => 'nullable|boolean',
            'status' => 'required|in:available,occupied,maintenance,dirty,cleaning',
            'floor' => 'nullable|string',
            'bed_config' => 'nullable|string',
            'amenities' => 'nullable|array',
            'intercom_extension' => 'nullable|string|max:50',
            'view_type' => 'nullable|string|in:standard,garden_view,sea_view,pool_view',
            'is_smoking_allowed' => 'nullable|boolean',
            'connected_room_id' => ['nullable', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'internal_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $room = Room::create($validated);
        $type = $room->roomType;
        if ($type) {
            \App\Support\HotelApiSync::afterRoomType($type);
        }

        return response()->json($room, 201);
    }

    public function show(Room $room)
    {
        $this->authorizePermissions([
            'rooms-view',
            'view-rooms',
            'reservation-view',
            'reservation',
        ]);

        return $room->load('roomType');
    }

    public function update(Request $request, Room $room)
    {
        $this->authorizePermissions(['rooms-edit']);
        if ($archived = $this->archivedRoomResponse($request->input('room_number'), (int) $room->id)) {
            return $archived;
        }
        $validated = $request->validate([
            'room_number' => 'string|unique:rooms,room_number,' . $room->id,
            'room_type_id' => [Rule::exists('room_types', 'id')->whereNull('deleted_at')],
            'is_active' => 'nullable|boolean',
            'status' => 'in:available,occupied,maintenance,dirty,cleaning',
            'floor' => 'nullable|string',
            'bed_config' => 'nullable|string',
            'amenities' => 'nullable|array',
            'intercom_extension' => 'nullable|string|max:50',
            'view_type' => 'nullable|string|in:standard,garden_view,sea_view,pool_view',
            'is_smoking_allowed' => 'nullable|boolean',
            'connected_room_id' => ['nullable', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'internal_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $room->update($validated);
        $type = $room->roomType()->first();
        if ($type) {
            \App\Support\HotelApiSync::afterRoomType($type);
        }

        return response()->json($room);
    }

    public function destroy(Room $room)
    {
        $this->authorizePermissions(['rooms-delete']);

        $hasStay = BookingSegment::where('room_id', '=', $room->id, 'and')
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_out_at', '>', now(), 'and')
            ->exists();
        if ($hasStay) {
            return response()->json(['message' => "Cannot archive Room #{$room->room_number} while it has a current or upcoming stay. Move or cancel that stay first."], 409);
        }

        $type = $room->roomType()->first();

        DB::transaction(function () use ($room) {
            RoomStatusBlock::where('room_id', '=', $room->id, 'and')
                ->where('is_active', '=', true, 'and')
                ->update(['is_active' => false]);
            Room::where('connected_room_id', '=', $room->id, 'and')->update(['connected_room_id' => null]);
            $room->connected_room_id = null;
            $room->save();
            $room->delete();
        });

        if ($type) {
            \App\Support\HotelApiSync::afterRoomType($type);
        }
        \App\Events\HousekeepingStateUpdated::dispatchIfEnabled([(int) $room->id], 'room_archived');

        return response()->json(null, 204);
    }

    public function restore(Room $room)
    {
        $this->authorizePermissions(['rooms-delete']);

        if (! $room->trashed()) {
            return response()->json(['message' => 'Room is not archived.'], 422);
        }

        $type = $room->roomType()->first();
        if (! $type) {
            return response()->json(['message' => "Room #{$room->room_number} belongs to an archived room type. Restore the room type first."], 422);
        }

        $room->restore();
        \App\Support\HotelApiSync::afterRoomType($type);
        \App\Events\HousekeepingStateUpdated::dispatchIfEnabled([(int) $room->id], 'room_restored');

        return response()->json($room->fresh()->load(['roomType', 'connectedRoom', 'parTemplate']));
    }

    private function archivedRoomResponse(mixed $roomNumber, ?int $exceptId = null): ?\Illuminate\Http\JsonResponse
    {
        if (! is_string($roomNumber) || trim($roomNumber) === '') {
            return null;
        }

        $archived = Room::onlyTrashed()
            ->where('room_number', '=', $roomNumber, 'and')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId, 'and'))
            ->exists();

        return $archived
            ? response()->json(['message' => "Room #{$roomNumber} is archived. Restore it from Archived rooms instead of adding it again."], 422)
            : null;
    }

    /**
     * Ensure each room has an inventory location (kind=room, room_id set).
     */
    public function syncInventoryLocations(Request $request)
    {
        $this->authorizePermissions(['rooms-create', 'rooms-edit']);

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
