<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomStatusBlock;
use Carbon\Carbon;
use Illuminate\Http\Request;

class HousekeepingController extends Controller
{
    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * List housekeeping-relevant status blocks (dirty / in cleaning) overlapping a calendar date.
     */
    public function index(Request $request)
    {
        $this->checkPermission('view-rooms');
        $validated = $request->validate([
            'date' => 'nullable|date',
            'floor' => 'nullable|string|max:50',
            'room_type_id' => 'nullable|exists:room_types,id',
            'hk_status' => 'nullable|in:dirty,cleaning,all',
        ]);

        $d = isset($validated['date'])
            ? Carbon::parse($validated['date'])->toDateString()
            : Carbon::today()->toDateString();
        $dNext = Carbon::parse($d)->addDay()->toDateString();
        $hkStatus = $validated['hk_status'] ?? 'all';

        $statuses = $hkStatus === 'all' ? ['dirty', 'cleaning'] : [$hkStatus];

        $query = RoomStatusBlock::query()
            ->with(['room.roomType'])
            ->where('is_active', true)
            ->whereIn('status', $statuses)
            ->where('start_date', '<', $dNext)
            ->where('end_date', '>', $d);

        $query->whereHas('room', function ($q) use ($validated) {
            if (! empty($validated['floor'])) {
                $q->where('floor', $validated['floor']);
            }
            if (! empty($validated['room_type_id'])) {
                $q->where('room_type_id', $validated['room_type_id']);
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
        Room::where('id', $roomStatusBlock->room_id)->update(['status' => 'cleaning']);

        return response()->json($roomStatusBlock->load('room.roomType'));
    }

    /**
     * Finish cleaning: deactivate block and set room available (room chart shows Available).
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
        Room::where('id', $roomStatusBlock->room_id)->update(['status' => 'available']);

        return response()->json([
            'message' => 'Room marked as cleaned.',
            'block' => $roomStatusBlock->fresh()->load('room.roomType'),
        ]);
    }
}
