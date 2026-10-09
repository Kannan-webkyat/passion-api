<?php

namespace App\Support;

use App\Models\BookingSegment;
use App\Models\HousekeepingJob;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\RoomType;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared availability / capacity rules for reservations (create, update, lookups).
 *
 * Occupancy is per physical room via segment datetime overlap.
 * Maintenance and on-hold blocks always prevent selling the room.
 * Dirty / cleaning blocks only block immediate check-in (confirmed future stays remain sellable).
 */
final class BookingRoomAvailability
{
    /** Statuses that free the room for a new reservation. */
    public const INACTIVE_SEGMENT_STATUSES = ['cancelled', 'checked_out', 'completed'];

    /** Blocks that always prevent selling the room for any stay overlapping the window. */
    public const HARD_BLOCK_STATUSES = ['maintenance', 'on_hold'];

    /** Blocks that only prevent checking a guest in (room may still take a confirmed reservation). */
    public const CHECKIN_ONLY_BLOCK_STATUSES = ['dirty', 'cleaning'];

    public static function dateEndExclusiveFromDateTime(Carbon $dt): string
    {
        $isMidnight = $dt->format('H:i:s') === '00:00:00';

        return $isMidnight ? $dt->toDateString() : $dt->copy()->addDay()->toDateString();
    }

    public static function hasSegmentOverlap(
        int $roomId,
        Carbon $checkInAt,
        Carbon $checkOutAt,
        ?int $excludeBookingId = null,
    ): bool {
        $q = BookingSegment::query()
            ->where('room_id', $roomId)
            ->whereNotIn('status', self::INACTIVE_SEGMENT_STATUSES)
            ->where('check_in_at', '<', $checkOutAt)
            ->where('check_out_at', '>', $checkInAt);

        if ($excludeBookingId) {
            $q->where('booking_id', '!=', $excludeBookingId);
        }

        return $q->exists() || self::hasDepartureMorningOverlap($roomId, $checkInAt, $checkOutAt, $excludeBookingId);
    }

    /**
     * Day stays store check-out as midnight, but the guest keeps the room until the standard check-out time
     * (or their late checkout) on the departure day. An hourly window that day cannot start before then,
     * and a new day stay cannot depart into an hourly stay that starts before standard check-out.
     */
    private static function hasDepartureMorningOverlap(int $roomId, Carbon $checkInAt, Carbon $checkOutAt, ?int $excludeBookingId): bool
    {
        $isMidnight = static fn (Carbon $dt): bool => $dt->format('H:i:s') === '00:00:00';

        $active = static function () use ($roomId, $excludeBookingId) {
            $q = BookingSegment::query()
                ->where('room_id', $roomId)
                ->whereNotIn('status', self::INACTIVE_SEGMENT_STATUSES);
            if ($excludeBookingId) {
                $q->where('booking_id', '!=', $excludeBookingId);
            }

            return $q;
        };

        if (! $isMidnight($checkInAt)) {
            $dayStart = $checkInAt->copy()->startOfDay();
            $departing = $active()->with('booking:id,check_out,late_checkout_time')
                ->where('check_out_at', $dayStart)
                ->where('check_in_at', '<', $dayStart)
                ->get();
            foreach ($departing as $segment) {
                $standardCheckOut ??= self::standardCheckOutTime();
                $late = $segment->booking
                    && Carbon::parse($segment->booking->check_out)->toDateString() === $dayStart->toDateString()
                    && $segment->booking->late_checkout_time
                    ? substr((string) $segment->booking->late_checkout_time, 0, 5)
                    : null;
                $leavesAt = max($standardCheckOut, $late ?? $standardCheckOut);
                if ($checkInAt->format('H:i') < $leavesAt) {
                    return true;
                }
            }
        }

        if ($isMidnight($checkInAt) && $isMidnight($checkOutAt) && $checkOutAt->gt($checkInAt)) {
            $departureDay = $checkOutAt->copy()->startOfDay();
            $firstHourly = $active()
                ->where('check_in_at', '>', $departureDay)
                ->where('check_in_at', '<', $departureDay->copy()->addDay())
                ->min('check_in_at');

            return $firstHourly !== null
                && Carbon::parse($firstHourly)->format('H:i') < self::standardCheckOutTime();
        }

        return false;
    }

    public static function standardCheckOutTime(): string
    {
        $raw = trim((string) Setting::get('standard_check_out_time', '11:00'));
        try {
            return Carbon::parse($raw !== '' ? $raw : '11:00')->format('H:i');
        } catch (\Throwable) {
            return '11:00';
        }
    }

    /**
     * @return list<RoomStatusBlock>
     */
    public static function overlappingActiveBlocks(int $roomId, Carbon $checkInAt, Carbon $checkOutAt): array
    {
        $startDate = $checkInAt->toDateString();
        $endDateExclusive = self::dateEndExclusiveFromDateTime($checkOutAt);

        return RoomStatusBlock::query()
            ->where('room_id', $roomId)
            ->where('is_active', true)
            ->where('start_date', '<', $endDateExclusive)
            ->where('end_date', '>', $startDate)
            ->get()
            ->all();
    }

    /**
     * @param  list<RoomStatusBlock>  $blocks
     */
    public static function hardBlockMessage(Room $room, array $blocks): ?string
    {
        foreach ($blocks as $block) {
            if ($block->status === 'maintenance') {
                return "Room #{$room->room_number} is under maintenance.";
            }
            if ($block->status === 'on_hold') {
                return "Room #{$room->room_number} is on hold and cannot be booked for these dates.";
            }
        }

        return null;
    }

    /**
     * @param  list<RoomStatusBlock>  $blocks
     */
    public static function checkInBlockMessage(Room $room, array $blocks): ?string
    {
        foreach ($blocks as $block) {
            if (in_array($block->status, self::CHECKIN_ONLY_BLOCK_STATUSES, true)) {
                return "Room #{$room->room_number} requires cleaning before check-in.";
            }
        }

        $inspectedIds = collect($blocks)->where('status', 'inspected')->pluck('id')->all();
        if ($inspectedIds !== [] && HousekeepingJob::query()
            ->whereIn('room_status_block_id', $inspectedIds)
            ->where('status', 'inspected')
            ->exists()) {
            return "Room #{$room->room_number} is cleaned and waiting for supervisor approval. Check-in is allowed after approval.";
        }

        return null;
    }

    /**
     * Assert the room can be sold for the window. Throws ValidationException on conflict.
     *
     * @throws ValidationException
     */
    public static function assertSellable(
        int $roomId,
        Carbon $checkInAt,
        Carbon $checkOutAt,
        string $status = 'confirmed',
        ?int $excludeBookingId = null,
    ): void {
        $room = Room::query()->findOrFail($roomId);

        if (self::hasSegmentOverlap($roomId, $checkInAt, $checkOutAt, $excludeBookingId)) {
            throw ValidationException::withMessages([
                'room_id' => ['Room #' . ($room->room_number ?? (string) $roomId) . ' is already reserved for the selected dates.'],
            ]);
        }

        if ($status === 'checked_in') {
            HousekeepingTurnoverCarryForward::sync($roomId);
        }
        $blocks = self::overlappingActiveBlocks($roomId, $checkInAt, $checkOutAt);
        $hard = self::hardBlockMessage($room, $blocks);
        if ($hard !== null) {
            throw ValidationException::withMessages(['room_id' => [$hard]]);
        }

        if ($status === 'checked_in') {
            $checkInMsg = self::checkInBlockMessage($room, $blocks);
            if ($checkInMsg !== null) {
                throw ValidationException::withMessages(['room_id' => [$checkInMsg]]);
            }
        }
    }

    /**
     * Lock room rows then re-assert sellability (concurrent booking safety).
     *
     * @param  list<int>  $roomIds
     *
     * @throws ValidationException
     */
    public static function lockAndAssertSellable(
        array $roomIds,
        Carbon $checkInAt,
        Carbon $checkOutAt,
        string $status = 'confirmed',
        ?int $excludeBookingId = null,
    ): void {
        $ids = array_values(array_unique(array_map('intval', $roomIds)));
        sort($ids);

        foreach ($ids as $roomId) {
            Room::query()->whereKey($roomId)->lockForUpdate()->firstOrFail();
            self::assertSellable($roomId, $checkInAt, $checkOutAt, $status, $excludeBookingId);
        }
    }

    /**
     * Capacity rules mirror the room chart UI: adults+children ≤ capacity;
     * extra beds must cover guests beyond base occupancy + child sharing.
     *
     * @return list<string>
     */
    public static function capacityErrors(
        RoomType $roomType,
        int $adults,
        int $children,
        int $extraBeds,
        ?array $childAges = null,
    ): array {
        $baseOcc = (int) ($roomType->base_occupancy ?? 2);
        $maxCap = (int) ($roomType->capacity ?? 2);
        $maxExBed = (int) ($roomType->extra_bed_capacity ?? 0);
        $childLimit = (int) ($roomType->child_sharing_limit ?? 1);

        $errors = [];
        if ($adults < 1) {
            $errors[] = 'At least 1 adult is required.';
        }

        $totalGuests = $adults + $children;
        if ($totalGuests > $maxCap) {
            $errors[] = "Total guests ({$totalGuests}) exceeds max capacity ({$maxCap}) for this room type.";
        }

        $extraAdults = max(0, $adults - $baseOcc);
        $remBase = max(0, $baseOcc - $adults);
        $extraChildrenMin = max(0, $children - $remBase - $childLimit);
        $actualMinBedsRequired = $extraAdults + $extraChildrenMin;
        $aged = SeasonalRoomPricing::requiredExtraBeds($roomType, $adults, $children, $childAges);
        if ($aged !== null) {
            $actualMinBedsRequired = $aged['adult'] + $aged['child'];
        }

        if ($actualMinBedsRequired > $maxExBed) {
            $errors[] = "This guest mix requires {$actualMinBedsRequired} extra bed(s), but only {$maxExBed} are available.";
        } elseif ($extraBeds < min($actualMinBedsRequired, $maxExBed)) {
            $errors[] = min($actualMinBedsRequired, $maxExBed) . ' extra bed(s) required for this guest count.';
        }

        return $errors;
    }

    /**
     * @throws ValidationException
     */
    public static function assertCapacity(Room $room, int $adults, int $children, int $extraBeds, ?array $childAges = null): void
    {
        $room->loadMissing('roomType');
        $rt = $room->roomType;
        if (! $rt) {
            return;
        }

        $errors = self::capacityErrors($rt, $adults, $children, $extraBeds, $childAges);
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'adults_count' => $errors,
            ]);
        }
    }

    /**
     * Whether a room should appear in available-rooms listings for a confirmed (non check-in) stay.
     * Dirty/cleaning rooms remain listable; maintenance and holds do not.
     */
    public static function isListedAsAvailable(int $roomId, Carbon $checkInAt, Carbon $checkOutAt, ?int $excludeBookingId = null): bool
    {
        if (self::hasSegmentOverlap($roomId, $checkInAt, $checkOutAt, $excludeBookingId)) {
            return false;
        }

        $blocks = self::overlappingActiveBlocks($roomId, $checkInAt, $checkOutAt);
        foreach ($blocks as $block) {
            if (in_array($block->status, self::HARD_BLOCK_STATUSES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run $callback inside a DB transaction after locking the given rooms.
     *
     * @template T
     *
     * @param  list<int>  $roomIds
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withRoomLocks(array $roomIds, callable $callback): mixed
    {
        return DB::transaction(function () use ($roomIds, $callback) {
            $ids = array_values(array_unique(array_map('intval', $roomIds)));
            sort($ids);
            foreach ($ids as $roomId) {
                Room::query()->whereKey($roomId)->lockForUpdate()->firstOrFail();
            }

            return $callback();
        });
    }
}
