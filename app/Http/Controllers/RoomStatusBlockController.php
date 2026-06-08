<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Events\HousekeepingStateUpdated;
use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoomStatusBlockController extends Controller
{
    use AuthorizesSpatiePermissions;

    public function index(Request $request)
    {
        $this->authorizePermissions([
            'manage-rooms',
            'view-rooms',
            'reservation-hold-room',
            'reservation-maintenance-room',
        ]);
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'room_id' => 'nullable|exists:rooms,id',
            'status' => 'nullable|in:maintenance,dirty,cleaning,on_hold',
            'is_active' => 'nullable|boolean',
        ]);

        $start = isset($validated['start']) ? Carbon::parse($validated['start'])->toDateString() : null;
        $end = isset($validated['end']) ? Carbon::parse($validated['end'])->toDateString() : null;

        return RoomStatusBlock::with(['room.roomType', 'creator:id,name'])
            ->when(array_key_exists('is_active', $validated), fn($q) => $q->where('is_active', (bool) $validated['is_active']))
            ->when($validated['room_id'] ?? null, fn($q, $roomId) => $q->where('room_id', $roomId))
            ->when($validated['status'] ?? null, fn($q, $status) => $q->where('status', $status))
            ->when($start && $end, function ($q) use ($start, $end) {
                // overlap: start_date < end AND end_date > start
                $q->where('start_date', '<', $end)->where('end_date', '>', $start);
            })
            ->orderBy('start_date')
            ->get();
    }

    /**
     * @param  'on_hold'|'maintenance'|'dirty'|'cleaning'  $status
     */
    private function authorizeStatusBlockStore(string $status): void
    {
        if ($status === 'on_hold') {
            $this->authorizePermissions(['reservation-hold-room']);
        } elseif ($status === 'maintenance') {
            $this->authorizePermissions(['reservation-maintenance-room']);
        } else {
            $this->authorizePermissions([
                'manage-rooms',
                'housekeeping-dirty-rooms',
                'housekeeping-cleaning-tasks',
            ]);
        }
    }

    private function authorizeStatusBlockMutation(RoomStatusBlock $block): void
    {
        $status = (string) $block->status;
        if ($status === 'on_hold') {
            $this->authorizePermissions(['reservation-hold-room']);
        } elseif ($status === 'maintenance') {
            $this->authorizePermissions(['reservation-maintenance-room']);
        } else {
            $this->authorizePermissions([
                'manage-rooms',
                'housekeeping-dirty-rooms',
                'housekeeping-cleaning-tasks',
            ]);
        }
    }

    /**
     * Holds and maintenance blocks may only start today or in the future.
     */
    private function assertBlockStartNotInPast(string $startDate, string $status): void
    {
        if (! in_array($status, ['on_hold', 'maintenance'], true)) {
            return;
        }

        if (Carbon::parse($startDate)->startOfDay()->lt(Carbon::today())) {
            $field = $status === 'on_hold' ? 'start_date' : 'start_date';
            $message = $status === 'on_hold'
                ? 'Room holds cannot be created for past dates.'
                : 'Maintenance blocks can only start today or in the future.';

            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'status' => 'required|in:maintenance,dirty,cleaning,on_hold',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'note' => in_array($request->input('status'), ['on_hold', 'maintenance'], true)
                ? 'required|string|max:255'
                : 'nullable|string|max:255',
        ]);

        $this->authorizeStatusBlockStore($validated['status']);
        $this->assertBlockStartNotInPast($validated['start_date'], $validated['status']);
        // Do not allow blocking a room if it already has a reservation segment in this period.
        // Overlap uses the same convention as stays: [start_date, end_date)
        $startAt = Carbon::parse($validated['start_date'])->startOfDay();
        $endAt = Carbon::parse($validated['end_date'])->startOfDay();
        $hasReservation = BookingSegment::where('room_id', '=', $validated['room_id'], 'and')
            // Checked-out/completed segments should not block housekeeping transitions.
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $endAt, 'and')
            ->where('check_out_at', '>', $startAt, 'and')
            ->exists();

        if ($hasReservation) {
            $room = Room::find($validated['room_id'], ['room_number']);

            return response()->json([
                'message' => "Cannot mark Room #{$room?->room_number} as {$validated['status']} because it already has a reservation in this date range.",
            ], 422);
        }

        // Prevent overlapping blocks on same room (any status) when active
        $overlap = RoomStatusBlock::where('room_id', '=', $validated['room_id'], 'and')
            ->where('is_active', '=', true, 'and')
            ->where('start_date', '<', $validated['end_date'], 'and')
            ->where('end_date', '>', $validated['start_date'], 'and')
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
        Room::where('id', '=', $block->room_id, 'and')->update(['status' => $block->status]);

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $block->room_id], 'room_status_block_store');

        return response()->json($block->load('room'), 201);
    }

    public function update(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->authorizeStatusBlockMutation($roomStatusBlock);

        $nextStatus = $request->input('status', $roomStatusBlock->status);
        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
            'note' => in_array($nextStatus, ['on_hold', 'maintenance'], true)
                ? 'sometimes|required|string|max:255'
                : 'nullable|string|max:255',
            'status' => 'sometimes|in:maintenance,dirty,cleaning,on_hold',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
        ]);

        if (
            isset($validated['start_date'], $validated['end_date'])
            && Carbon::parse($validated['end_date'])->lte(Carbon::parse($validated['start_date']))
        ) {
            return response()->json(['message' => 'End date must be after start date.'], 422);
        }

        $startDate = $validated['start_date'] ?? $roomStatusBlock->start_date;
        $endDate = $validated['end_date'] ?? $roomStatusBlock->end_date;
        $effectiveStatus = (string) ($validated['status'] ?? $roomStatusBlock->status);

        if ($request->has('start_date')) {
            $this->assertBlockStartNotInPast($startDate, $effectiveStatus);
        }

        if ($request->hasAny(['start_date', 'end_date']) && ($roomStatusBlock->is_active ?? true)) {
            $startAt = Carbon::parse($startDate)->startOfDay();
            $endAt = Carbon::parse($endDate)->startOfDay();

            $hasReservation = BookingSegment::where('room_id', '=', $roomStatusBlock->room_id, 'and')
                ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
                ->where('check_in_at', '<', $endAt, 'and')
                ->where('check_out_at', '>', $startAt, 'and')
                ->exists();

            if ($hasReservation) {
                $room = Room::find($roomStatusBlock->room_id, ['room_number']);

                return response()->json([
                    'message' => "Cannot update block for Room #{$room?->room_number} — a reservation overlaps this date range.",
                ], 422);
            }

            $overlap = RoomStatusBlock::where('room_id', '=', $roomStatusBlock->room_id, 'and')
                ->where('id', '!=', $roomStatusBlock->id, 'and')
                ->where('is_active', '=', true, 'and')
                ->where('start_date', '<', $endDate, 'and')
                ->where('end_date', '>', $startDate, 'and')
                ->exists();

            if ($overlap) {
                return response()->json([
                    'message' => 'Room already has an active status block in this period.',
                ], 422);
            }
        }

        if (isset($validated['status']) && $validated['status'] !== $roomStatusBlock->status) {
            $this->authorizeStatusBlockStore($validated['status']);
        }

        $roomStatusBlock->update($validated);

        // If inactive or status changed, sync room status
        if ($roomStatusBlock->is_active) {
            Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => $roomStatusBlock->status]);
        } else {
            Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'available']);
        }

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $roomStatusBlock->room_id], 'room_status_block_update');

        return response()->json($roomStatusBlock->load(['room.roomType', 'creator:id,name']));
    }

    public function destroy(RoomStatusBlock $roomStatusBlock)
    {
        $this->authorizeStatusBlockMutation($roomStatusBlock);
        $roomId = $roomStatusBlock->room_id;
        RoomStatusBlock::destroy($roomStatusBlock->id);

        // Restore room to available
        Room::where('id', '=', $roomId, 'and')->update(['status' => 'available']);

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $roomId], 'room_status_block_destroy');

        return response()->json(null, 204);
    }
}
