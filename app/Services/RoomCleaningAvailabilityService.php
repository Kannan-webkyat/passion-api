<?php

namespace App\Services;

use App\Events\HousekeepingStateUpdated;
use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\DailyRoomCleaning;
use App\Models\Room;
use App\Models\RoomCleaningRelease;
use App\Models\RoomCleaningReleaseAudit;
use App\Models\RoomStatusBlock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RoomCleaningAvailabilityService
{
    /**
     * Mark overdue available windows as expired.
     */
    public function expireOverdueWindows(?Carbon $now = null): int
    {
        $now = $now ?? now();
        $expired = RoomCleaningRelease::query()
            ->where('is_active', true)
            ->where('status', RoomCleaningRelease::STATUS_AVAILABLE)
            ->where('window_end', '<', $now)
            ->get();

        $count = 0;
        foreach ($expired as $release) {
            $release->status = RoomCleaningRelease::STATUS_EXPIRED;
            $release->save();
            $this->audit($release, RoomCleaningReleaseAudit::ACTION_EXPIRED, null, 'Cleaning window expired without start.');
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function releaseForCleaning(array $data): RoomCleaningRelease
    {
        $userId = Auth::id();
        $roomId = (int) $data['room_id'];
        $releaseDate = Carbon::parse($data['release_date'])->toDateString();
        $windowStart = Carbon::parse($data['window_start']);
        $windowEnd = Carbon::parse($data['window_end']);

        if ($windowEnd->lte($windowStart)) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }

        return DB::transaction(function () use ($data, $userId, $roomId, $releaseDate, $windowStart, $windowEnd) {
            RoomCleaningRelease::query()
                ->where('room_id', $roomId)
                ->where('is_active', true)
                ->whereNotIn('status', [
                    RoomCleaningRelease::STATUS_READY,
                    RoomCleaningRelease::STATUS_CANCELLED,
                ])
                ->update([
                    'is_active' => false,
                    'status' => RoomCleaningRelease::STATUS_CANCELLED,
                ]);

            $bookingId = isset($data['booking_id']) ? (int) $data['booking_id'] : null;
            $blockId = $this->resolveDirtyBlockId($roomId, $releaseDate);

            $cleaning = null;
            if ($this->roomIsOccupiedOnDate($roomId, Carbon::parse($releaseDate))) {
                $cleaning = DailyRoomCleaning::firstOrCreate(
                    [
                        'room_id' => $roomId,
                        'service_date' => $releaseDate,
                    ],
                    [
                        'booking_id' => $bookingId,
                        'status' => 'pending_cleaning',
                    ]
                );
                if ($bookingId && (int) ($cleaning->booking_id ?? 0) !== $bookingId) {
                    $cleaning->booking_id = $bookingId;
                    $cleaning->save();
                }
            }

            /** @var RoomCleaningRelease $release */
            $release = RoomCleaningRelease::create([
                'room_id' => $roomId,
                'booking_id' => $bookingId,
                'room_status_block_id' => $blockId,
                'daily_room_cleaning_id' => $cleaning?->id,
                'release_date' => $releaseDate,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'status' => RoomCleaningRelease::STATUS_AVAILABLE,
                'priority' => (string) ($data['priority'] ?? 'normal'),
                'assigned_to' => $data['assigned_to'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'is_active' => true,
                'created_by' => $userId,
            ]);

            if ($release->assigned_to && $cleaning) {
                $cleaning->assigned_to = $release->assigned_to;
                $cleaning->save();
            }

            $this->audit($release, RoomCleaningReleaseAudit::ACTION_RELEASED, $data['remarks'] ?? null, [
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
                'priority' => $release->priority,
            ]);

            HousekeepingStateUpdated::dispatchIfEnabled([$roomId], 'cleaning_released');

            return $release->fresh([
                'room.roomType',
                'booking',
                'assignedUser:id,name',
                'dailyRoomCleaning',
            ]);
        });
    }

    public function canStartCleaning(RoomCleaningRelease $release, ?Carbon $now = null): bool
    {
        $now = $now ?? now();
        if (! $release->is_active) {
            return false;
        }
        if ($release->status === RoomCleaningRelease::STATUS_IN_PROGRESS) {
            return true;
        }
        if ($release->status !== RoomCleaningRelease::STATUS_AVAILABLE) {
            return false;
        }

        return $now->gte($release->window_start) && $now->lte($release->window_end);
    }

    public function assertCanStartCleaning(RoomCleaningRelease $release, ?Carbon $now = null): ?string
    {
        $now = $now ?? now();
        if (! $release->is_active) {
            return 'This cleaning release is no longer active.';
        }
        if ($release->status === RoomCleaningRelease::STATUS_EXPIRED) {
            return 'Cleaning window has expired. Ask a supervisor to extend or reschedule.';
        }
        if ($release->status === RoomCleaningRelease::STATUS_CANCELLED) {
            return 'This cleaning release was cancelled.';
        }
        if (! in_array($release->status, [
            RoomCleaningRelease::STATUS_AVAILABLE,
            RoomCleaningRelease::STATUS_IN_PROGRESS,
        ], true)) {
            return 'Room is not available for cleaning in its current state.';
        }
        if ($now->lt($release->window_start)) {
            return 'Room not currently available for cleaning. Cleaning window: '
                . $release->window_start->format('g:i A') . ' – ' . $release->window_end->format('g:i A') . '.';
        }
        if ($now->gt($release->window_end) && $release->status === RoomCleaningRelease::STATUS_AVAILABLE) {
            return 'Cleaning window has expired. Ask a supervisor to extend or reschedule.';
        }

        return null;
    }

    public function activeReleaseForRoom(int $roomId, ?Carbon $date = null): ?RoomCleaningRelease
    {
        $date = $date ?? Carbon::today();

        return RoomCleaningRelease::query()
            ->where('room_id', $roomId)
            ->where('is_active', true)
            ->whereDate('release_date', '<=', $date->toDateString())
            ->whereNotIn('status', [
                RoomCleaningRelease::STATUS_READY,
                RoomCleaningRelease::STATUS_CANCELLED,
            ])
            ->orderByDesc('id')
            ->with(['assignedUser:id,name', 'startedByUser:id,name'])
            ->first();
    }

    public function markCleaningStarted(RoomCleaningRelease $release, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();
        $release->status = RoomCleaningRelease::STATUS_IN_PROGRESS;
        if (! $release->started_at) {
            $release->started_at = now();
            $release->started_by = $userId;
        }
        $release->save();
        $this->audit($release, RoomCleaningReleaseAudit::ACTION_CLEANING_STARTED, null);
    }

    public function markCleaningCompleted(RoomCleaningRelease $release, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();
        $release->status = RoomCleaningRelease::STATUS_INSPECTION_PENDING;
        if (! $release->completed_at) {
            $release->completed_at = now();
            $release->completed_by = $userId;
        }
        $release->save();
        $this->audit($release, RoomCleaningReleaseAudit::ACTION_CLEANING_COMPLETED, null);
    }

    public function markInspectionCompleted(RoomCleaningRelease $release, ?string $remarks = null): void
    {
        $release->status = RoomCleaningRelease::STATUS_READY;
        $release->is_active = false;
        $release->save();
        $this->audit($release, RoomCleaningReleaseAudit::ACTION_INSPECTION_COMPLETED, $remarks);
        $this->audit($release, RoomCleaningReleaseAudit::ACTION_ROOM_READY, $remarks);
    }

    public function markRoomReady(RoomCleaningRelease $release, ?string $remarks = null): void
    {
        $release->status = RoomCleaningRelease::STATUS_READY;
        $release->is_active = false;
        $release->save();
        $this->audit($release, RoomCleaningReleaseAudit::ACTION_ROOM_READY, $remarks);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function extendWindow(RoomCleaningRelease $release, array $data): RoomCleaningRelease
    {
        $windowEnd = Carbon::parse($data['window_end']);
        if ($windowEnd->lte($release->window_start)) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }

        $release->window_end = $windowEnd;
        if ($release->status === RoomCleaningRelease::STATUS_EXPIRED) {
            $release->status = RoomCleaningRelease::STATUS_AVAILABLE;
        }
        $release->save();

        $this->audit($release, RoomCleaningReleaseAudit::ACTION_WINDOW_EXTENDED, $data['remarks'] ?? null, [
            'window_end' => $windowEnd->toIso8601String(),
        ]);

        return $release->fresh(['room.roomType', 'assignedUser:id,name', 'startedByUser:id,name']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function rescheduleWindow(RoomCleaningRelease $release, array $data): RoomCleaningRelease
    {
        $releaseDate = Carbon::parse($data['release_date'])->toDateString();
        $windowStart = Carbon::parse($data['window_start']);
        $windowEnd = Carbon::parse($data['window_end']);

        if ($windowEnd->lte($windowStart)) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }

        $release->release_date = $releaseDate;
        $release->window_start = $windowStart;
        $release->window_end = $windowEnd;
        if ($release->status === RoomCleaningRelease::STATUS_EXPIRED) {
            $release->status = RoomCleaningRelease::STATUS_AVAILABLE;
        }
        $release->save();

        $this->audit($release, RoomCleaningReleaseAudit::ACTION_WINDOW_RESCHEDULED, $data['remarks'] ?? null, [
            'release_date' => $releaseDate,
            'window_start' => $windowStart->toIso8601String(),
            'window_end' => $windowEnd->toIso8601String(),
        ]);

        return $release->fresh(['room.roomType', 'assignedUser:id,name', 'startedByUser:id,name']);
    }

    public function cancelRelease(RoomCleaningRelease $release, ?string $remarks = null): RoomCleaningRelease
    {
        $release->status = RoomCleaningRelease::STATUS_CANCELLED;
        $release->is_active = false;
        $release->save();
        $this->audit($release, RoomCleaningReleaseAudit::ACTION_CANCELLED, $remarks);

        return $release;
    }

    /**
     * @return array<string, int>
     */
    public function dashboardMetrics(?Carbon $date = null): array
    {
        $this->expireOverdueWindows();
        $date = $date ?? Carbon::today();
        $dateStr = $date->toDateString();

        $waitingRelease = (int) Room::query()
            ->where('is_active', true)
            ->whereIn('status', ['dirty', 'occupied'])
            ->whereDoesntHave('cleaningReleases', function ($q) use ($dateStr) {
                $q->where('is_active', true)
                    ->whereDate('release_date', '<=', $dateStr)
                    ->whereNotIn('status', [
                        RoomCleaningRelease::STATUS_READY,
                        RoomCleaningRelease::STATUS_CANCELLED,
                    ]);
            })
            ->count();

        $available = (int) RoomCleaningRelease::query()
            ->where('is_active', true)
            ->where('status', RoomCleaningRelease::STATUS_AVAILABLE)
            ->whereDate('release_date', '<=', $dateStr)
            ->count();

        $inProgress = (int) RoomCleaningRelease::query()
            ->where('is_active', true)
            ->where('status', RoomCleaningRelease::STATUS_IN_PROGRESS)
            ->count();

        $inspectionPending = (int) RoomCleaningRelease::query()
            ->where('is_active', true)
            ->where('status', RoomCleaningRelease::STATUS_INSPECTION_PENDING)
            ->count();

        $ready = (int) RoomCleaningRelease::query()
            ->where('status', RoomCleaningRelease::STATUS_READY)
            ->whereDate('updated_at', $dateStr)
            ->count();

        $expired = (int) RoomCleaningRelease::query()
            ->where('is_active', true)
            ->where('status', RoomCleaningRelease::STATUS_EXPIRED)
            ->count();

        return [
            'waiting_release' => $waitingRelease,
            'available_for_cleaning' => $available,
            'in_progress' => $inProgress,
            'inspection_pending' => $inspectionPending,
            'ready' => $ready,
            'expired_windows' => $expired,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function audit(
        RoomCleaningRelease $release,
        string $action,
        ?string $remarks = null,
        ?array $meta = null,
    ): void {
        RoomCleaningReleaseAudit::create([
            'room_cleaning_release_id' => $release->id,
            'action' => $action,
            'user_id' => Auth::id(),
            'remarks' => $remarks,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    private function resolveDirtyBlockId(int $roomId, string $releaseDate): ?int
    {
        $day = Carbon::parse($releaseDate);
        $co = $day->toDateString();
        $coNext = $day->copy()->addDay()->toDateString();

        $block = RoomStatusBlock::query()
            ->where('room_id', $roomId)
            ->where('is_active', true)
            ->whereIn('status', ['dirty', 'cleaning'])
            ->where('start_date', '<', $coNext)
            ->where('end_date', '>', $co)
            ->orderByDesc('id')
            ->first();

        return $block ? (int) $block->id : null;
    }

    private function roomIsOccupiedOnDate(int $roomId, Carbon $date): bool
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->addDay()->startOfDay();

        return BookingSegment::query()
            ->where('room_id', $roomId)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $dayEnd)
            ->where('check_out_at', '>', $dayStart)
            ->whereHas('booking', fn($b) => $b->where('status', 'checked_in'))
            ->exists();
    }
}
