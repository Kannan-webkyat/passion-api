<?php

namespace App\Support;

use App\Events\HousekeepingStateUpdated;
use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Room moves inside an in-house split stay. There is no scheduler, so callers run this lazily
 * (room chart, housekeeping nav counts / board, checkout) to catch segment boundaries that passed.
 */
final class BookingSplitStayRoomMove
{
    public static function sync(?int $bookingId = null): void
    {
        self::releaseRoomsLeft($bookingId);
        self::markReachedRoomsOccupied($bookingId);
    }

    /**
     * A segment that ended while the guest stays on in a later segment: close it and hand the room
     * to housekeeping on the day the guest left, as a mid-stay room transfer does.
     */
    private static function releaseRoomsLeft(?int $bookingId): void
    {
        $now = now();
        $ended = BookingSegment::query()
            ->where('status', 'checked_in')
            ->where('check_out_at', '<=', $now)
            ->when($bookingId, fn($q) => $q->where('booking_id', $bookingId))
            ->whereHas('booking', fn($q) => $q->where('status', 'checked_in'))
            ->whereExists(fn($q) => $q->selectRaw('1')
                ->from('booking_segments as later')
                ->whereColumn('later.booking_id', 'booking_segments.booking_id')
                ->whereColumn('later.check_out_at', '>', 'booking_segments.check_out_at')
                ->where('later.status', 'checked_in'))
            ->orderBy('check_out_at')
            ->get();

        if ($ended->isEmpty()) {
            return;
        }

        $notifyRoomIds = [];
        DB::transaction(function () use ($ended, $now, &$notifyRoomIds) {
            foreach ($ended as $segment) {
                $segment->update(['status' => 'checked_out']);
                $rid = (int) $segment->room_id;

                $guestStillInRoom = BookingSegment::query()
                    ->where('room_id', $rid)
                    ->where('status', 'checked_in')
                    ->where('check_out_at', '>', $now)
                    ->where(fn($q) => $q->where('booking_id', $segment->booking_id)
                        ->orWhere('check_in_at', '<=', $now))
                    ->exists();
                if ($guestStillInRoom) {
                    continue;
                }

                $co = Carbon::parse($segment->check_out ?? $segment->check_out_at)->startOfDay();
                $coStr = $co->toDateString();
                $coNext = $co->copy()->addDay()->toDateString();

                Room::where('id', $rid)->update(['status' => 'dirty']);

                RoomStatusBlock::query()
                    ->where('room_id', $rid)
                    ->where('is_active', true)
                    ->whereIn('status', ['inspected', 'pending_inspection'])
                    ->update(['is_active' => false]);

                $hasBlock = RoomStatusBlock::where('room_id', $rid)
                    ->where('is_active', true)
                    ->where('start_date', '<', $coNext)
                    ->where('end_date', '>', $coStr)
                    ->exists();

                if (! $hasBlock) {
                    RoomStatusBlock::create([
                        'room_id' => $rid,
                        'status' => 'dirty',
                        'start_date' => $coStr,
                        'end_date' => $coNext,
                        'note' => 'Auto: split stay room move',
                        'is_active' => true,
                        'created_by' => Auth::id(),
                    ]);
                }

                $notifyRoomIds[] = $rid;
            }
        });

        if ($notifyRoomIds !== []) {
            HousekeepingStateUpdated::dispatchIfEnabled($notifyRoomIds, 'split_stay_room_move');
        }
    }

    /**
     * rooms.status for split stays: the check-in only marks the arrival room, so mark each later
     * room occupied once an in-house guest's segment in it has started.
     */
    private static function markReachedRoomsOccupied(?int $bookingId): void
    {
        $now = now();
        $roomIds = BookingSegment::query()
            ->where('status', 'checked_in')
            ->where('check_in_at', '<=', $now)
            ->where('check_out_at', '>', $now)
            ->when($bookingId, fn($q) => $q->where('booking_id', $bookingId))
            ->whereHas('booking', fn($q) => $q->where('status', 'checked_in'))
            ->whereIn('booking_id', BookingSegment::query()
                ->select('booking_id')
                ->groupBy('booking_id')
                ->havingRaw('COUNT(*) > 1'))
            ->pluck('room_id')
            ->unique()
            ->all();

        if ($roomIds !== []) {
            Room::whereIn('id', $roomIds)->whereIn('status', ['available', 'vacant'])->update(['status' => 'occupied']);
        }
    }
}
