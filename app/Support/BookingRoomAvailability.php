<?php

namespace App\Support;

use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\RoomType;
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

        return $q->exists();
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
    public static function assertCapacity(Room $room, int $adults, int $children, int $extraBeds): void
    {
        $room->loadMissing('roomType');
        $rt = $room->roomType;
        if (! $rt) {
            return;
        }

        $errors = self::capacityErrors($rt, $adults, $children, $extraBeds);
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
