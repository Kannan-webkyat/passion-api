<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $query = Room::with('roomType');
        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        return response()->json($query->get());
    }

    public function store(Request $request)
    {
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
        $validated = $request->validate([
            'room_number' => 'string|unique:rooms,room_number,' . $room->id,
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
        $room->delete();
        return response()->json(null, 204);
    }
}
