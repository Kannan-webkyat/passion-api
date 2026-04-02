<?php

namespace App\Http\Controllers;

use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RoomStatusBlockController extends Controller
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
        $this->checkPermission('manage-rooms');
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'room_id' => 'nullable|exists:rooms,id',
            'status' => 'nullable|in:maintenance,dirty,cleaning,on_hold',
            'is_active' => 'nullable|boolean',
        ]);

        $start = isset($validated['start']) ? Carbon::parse($validated['start'])->toDateString() : null;
        $end = isset($validated['end']) ? Carbon::parse($validated['end'])->toDateString() : null;

        return RoomStatusBlock::with('room')
            ->when(array_key_exists('is_active', $validated), fn ($q) => $q->where('is_active', (bool) $validated['is_active']))
            ->when($validated['room_id'] ?? null, fn ($q, $roomId) => $q->where('room_id', $roomId))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($start && $end, function ($q) use ($start, $end) {
                // overlap: start_date < end AND end_date > start
                $q->where('start_date', '<', $end)->where('end_date', '>', $start);
            })
            ->orderBy('start_date')
            ->get();
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-rooms');
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'status' => 'required|in:maintenance,dirty,cleaning,on_hold',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'note' => in_array($request->input('status'), ['on_hold', 'maintenance'], true)
                ? 'required|string|max:255'
                : 'nullable|string|max:255',
        ]);

        // Do not allow blocking a room if it already has a reservation segment in this period.
        // Overlap uses the same convention as stays: [start_date, end_date)
        $startAt = Carbon::parse($validated['start_date'])->startOfDay();
        $endAt = Carbon::parse($validated['end_date'])->startOfDay();
        $hasReservation = BookingSegment::where('room_id', $validated['room_id'])
            // Checked-out/completed segments should not block housekeeping transitions.
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $endAt)
            ->where('check_out_at', '>', $startAt)
            ->exists();

        if ($hasReservation) {
            $room = Room::find($validated['room_id']);

            return response()->json([
                'message' => "Cannot mark Room #{$room?->room_number} as {$validated['status']} because it already has a reservation in this date range.",
            ], 422);
        }

        // Prevent overlapping blocks on same room (any status) when active
        $overlap = RoomStatusBlock::where('room_id', $validated['room_id'])
            ->where('is_active', true)
            ->where('start_date', '<', $validated['end_date'])
            ->where('end_date', '>', $validated['start_date'])
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'Room already has an active status block in this period.',
            ], 422);
        }

        $userId = $request->user()?->id;
        $block = RoomStatusBlock::create([
            ...$validated,
            'is_active' => true,
            'created_by' => $userId,
        ]);

        // Sync Room status column
        Room::where('id', $block->room_id)->update(['status' => $block->status]);

        return response()->json($block->load('room'), 201);
    }

    public function update(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->checkPermission('manage-rooms');
        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
            'note' => 'nullable|string|max:255',
        ]);

        $roomStatusBlock->update($validated);

        // If inactive or status changed, sync room status
        if ($roomStatusBlock->is_active) {
            Room::where('id', $roomStatusBlock->room_id)->update(['status' => $roomStatusBlock->status]);
        } else {
            Room::where('id', $roomStatusBlock->room_id)->update(['status' => 'available']);
        }

        return response()->json($roomStatusBlock->load('room'));
    }

    public function destroy(RoomStatusBlock $roomStatusBlock)
    {
        $this->checkPermission('manage-rooms');
        $roomId = $roomStatusBlock->room_id;
        $roomStatusBlock->delete();

        // Restore room to available
        Room::where('id', $roomId)->update(['status' => 'available']);

        return response()->json(null, 204);
    }
}
