<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $query = Room::with('roomType');
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
            'room_number' => 'string|unique:rooms,room_number,'.$room->id,
            'room_type_id' => 'exists:room_types,id',
            'is_active' => 'nullable|boolean',
            'status' => 'in:available,occupied,maintenance,dirty,cleaning',
            'floor' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $room->update($validated);

        return response()->json($room);
    }

    public function destroy(Room $room)
    {
        $this->checkPermission('manage-rooms');
        try {
            $room->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete room as it has historical bookings or active transactions.'], 409);
            }
            throw $e;
        }
    }
}
