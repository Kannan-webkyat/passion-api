<?php

namespace App\Support;

use App\Models\BookingSegment;
use App\Models\RoomStatusBlock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout / room-move dirty blocks cover the departure day only. A room housekeeping has not finished
 * must stay dirty on later days, so its active dirty/cleaning block (or a cleaned block awaiting
 * supervisor approval) is stretched to cover today.
 * There is no scheduler, so callers run this lazily (room chart, summary, check-in, housekeeping lists).
 * A room a guest is already checked into keeps no stale turnover: that block is closed instead.
 */
final class HousekeepingTurnoverCarryForward
{
    public static function sync(?int $roomId = null): void
    {
        $today = Carbon::today()->toDateString();

        $withApproval = Schema::hasTable('housekeeping_jobs');

        $stale = RoomStatusBlock::query()
            ->where('is_active', true)
            ->where(function ($q) use ($withApproval) {
                $q->whereIn('status', ['dirty', 'cleaning'])
                    ->when($withApproval, fn ($q) => $q->orWhere(function ($awaiting) {
                        $awaiting->where('status', 'inspected')
                            ->whereExists(function ($job) {
                                $job->select(DB::raw(1))
                                    ->from('housekeeping_jobs')
                                    ->whereColumn('housekeeping_jobs.room_status_block_id', 'room_status_blocks.id')
                                    ->where('housekeeping_jobs.status', 'inspected');
                            });
                    }));
            })
            ->where('end_date', '<=', $today)
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->get(['id', 'room_id']);

        if ($stale->isEmpty()) {
            return;
        }

        $inHouseRoomIds = BookingSegment::query()
            ->whereIn('room_id', $stale->pluck('room_id')->unique()->all())
            ->where('status', 'checked_in')
            ->where('check_in_at', '<', Carbon::tomorrow())
            ->whereHas('booking', fn ($q) => $q->where('status', 'checked_in'))
            ->pluck('room_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        [$occupied, $waiting] = $stale->partition(fn (RoomStatusBlock $b) => in_array((int) $b->room_id, $inHouseRoomIds, true));

        if ($occupied->isNotEmpty()) {
            RoomStatusBlock::query()->whereIn('id', $occupied->pluck('id')->all())->update(['is_active' => false]);
        }

        if ($waiting->isNotEmpty()) {
            // Query builder keeps updated_at, which the Dirty Rooms board uses for waiting time.
            DB::table('room_status_blocks')
                ->whereIn('id', $waiting->pluck('id')->all())
                ->update(['end_date' => Carbon::tomorrow()->toDateString()]);
        }
    }
}
