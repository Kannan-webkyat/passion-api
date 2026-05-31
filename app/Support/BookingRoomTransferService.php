<?php

namespace App\Support;

use App\Events\HousekeepingStateUpdated;
use App\Models\Booking;
use App\Models\BookingRoomTransfer;
use App\Models\BookingSegment;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BookingRoomTransferService
{
    /**
     * @return array{ok: bool, message?: string, preview?: array, booking?: Booking}
     */
    public static function preview(Booking $booking, array $input): array
    {
        $parsed = self::validateInput($input);
        $ctx = self::buildContext($booking, $parsed);
        if (! $ctx['ok']) {
            return $ctx;
        }

        return [
            'ok' => true,
            'preview' => self::pricingPreview($ctx),
        ];
    }

    /**
     * @return array{ok: bool, message?: string, booking?: Booking, transfer?: BookingRoomTransfer}
     */
    public static function execute(Booking $booking, array $input): array
    {
        $parsed = self::validateInput($input);
        $ctx = self::buildContext($booking, $parsed);
        if (! $ctx['ok']) {
            return $ctx;
        }

        $preview = self::pricingPreview($ctx);

        return DB::transaction(function () use ($booking, $ctx, $preview, $parsed) {
            $result = self::applyTransfer($ctx, $preview);
            $transfer = self::recordTransfer($booking, $ctx, $preview, $result);
            self::appendAuditNote($booking, $ctx, $preview, $transfer);
            self::applyHousekeeping($booking, $ctx);

            $booking->refresh()->load([
                'room.roomType.tax',
                'creator',
                'bookingGroup',
                'segments.room.roomType',
                'roomTransfers.fromRoom',
                'roomTransfers.toRoom',
                'roomTransfers.performer',
            ]);

            return [
                'ok' => true,
                'booking' => $booking,
                'transfer' => $transfer,
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function historyPayload(Booking $booking): array
    {
        $booking->loadMissing([
            'roomTransfers.fromRoom.roomType',
            'roomTransfers.toRoom.roomType',
            'roomTransfers.performer',
        ]);

        return $booking->roomTransfers->map(function (BookingRoomTransfer $t) {
            return [
                'id' => $t->id,
                'from_room_number' => $t->fromRoom?->room_number,
                'to_room_number' => $t->toRoom?->room_number,
                'from_room_type' => $t->fromRoom?->roomType?->name,
                'to_room_type' => $t->toRoom?->roomType?->name,
                'transfer_reason' => $t->transfer_reason,
                'transfer_reason_label' => BookingRoomTransfer::reasonLabel($t->transfer_reason),
                'internal_notes' => $t->internal_notes,
                'rate_mode' => $t->rate_mode,
                'is_complimentary_upgrade' => $t->is_complimentary_upgrade,
                'old_total_price' => (float) $t->old_total_price,
                'new_total_price' => (float) $t->new_total_price,
                'price_delta' => (float) $t->price_delta,
                'segment_price' => (float) $t->segment_price,
                'transferred_at' => $t->transferred_at?->toIso8601String(),
                'performed_by_name' => $t->performer?->name,
            ];
        })->values()->all();
    }

    private static function validateInput(array $input): array
    {
        $reason = (string) ($input['transfer_reason'] ?? '');
        if (! in_array($reason, BookingRoomTransfer::REASONS, true)) {
            throw ValidationException::withMessages(['transfer_reason' => 'Select a valid transfer reason.']);
        }
        $notes = trim((string) ($input['internal_notes'] ?? ''));
        if ($reason === 'other' && strlen($notes) < 3) {
            throw ValidationException::withMessages(['internal_notes' => 'Please provide details when reason is Other (min 3 characters).']);
        }
        $rateMode = (string) ($input['rate_mode'] ?? '');
        if (! in_array($rateMode, BookingRoomTransfer::RATE_MODES, true)) {
            throw ValidationException::withMessages(['rate_mode' => 'Select a valid rate option.']);
        }

        return [
            'new_room_id' => (int) ($input['new_room_id'] ?? 0),
            'transfer_reason' => $reason,
            'internal_notes' => $notes !== '' ? $notes : null,
            'rate_mode' => $rateMode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildContext(Booking $booking, array $parsed): array
    {
        if (! in_array($booking->status, ['confirmed', 'checked_in'], true)) {
            return ['ok' => false, 'message' => 'Room transfer is only allowed for confirmed or checked-in bookings.'];
        }

        $newRoomId = $parsed['new_room_id'];
        if ($newRoomId <= 0) {
            return ['ok' => false, 'message' => 'Select a destination room.'];
        }

        $booking->loadMissing(['segments.room.roomType', 'room.roomType']);
        if ($booking->segments->isEmpty()) {
            BookingSegment::create([
                'booking_id' => $booking->id,
                'room_id' => $booking->room_id,
                'check_in' => $booking->check_in,
                'check_out' => $booking->check_out,
                'check_in_at' => $booking->check_in_at ?? Carbon::parse($booking->check_in)->startOfDay(),
                'check_out_at' => $booking->check_out_at ?? Carbon::parse($booking->check_out)->startOfDay(),
                'rate_plan_id' => $booking->rate_plan_id,
                'adults_count' => $booking->adults_count,
                'children_count' => $booking->children_count,
                'extra_beds_count' => $booking->extra_beds_count,
                'total_price' => $booking->total_price,
                'status' => $booking->status === 'checked_in' ? 'checked_in' : 'confirmed',
            ]);
            $booking->load('segments.room.roomType');
        }
        $activeSegment = self::resolveActiveSegment($booking);
        if (! $activeSegment) {
            return ['ok' => false, 'message' => 'No active stay segment found for this booking.'];
        }

        $fromRoom = $activeSegment->room ?? Room::with('roomType')->find($activeSegment->room_id);
        $toRoom = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons'])->find($newRoomId);
        if (! $fromRoom || ! $toRoom) {
            return ['ok' => false, 'message' => 'Invalid room selection.'];
        }

        if ((int) $fromRoom->id === (int) $toRoom->id) {
            return ['ok' => false, 'message' => 'Select a different room than the current one.'];
        }

        $transferAt = self::transferTimestamp($booking, $activeSegment);
        $segmentEnd = Carbon::parse($activeSegment->check_out_at ?? $activeSegment->check_out);
        if ($transferAt->gte($segmentEnd)) {
            return ['ok' => false, 'message' => 'Cannot transfer: stay segment has already ended.'];
        }

        if (! self::isRoomAvailable($toRoom->id, $transferAt, $segmentEnd, (int) $booking->id)) {
            return ['ok' => false, 'message' => 'Selected room is not available for the remaining stay dates.'];
        }

        $fromTypeId = (int) ($fromRoom->room_type_id ?? 0);
        $toTypeId = (int) ($toRoom->room_type_id ?? 0);
        $isCategoryChange = $fromTypeId !== $toTypeId;

        if ($parsed['rate_mode'] === 'apply_new_category' && ! $isCategoryChange) {
            return ['ok' => false, 'message' => 'Apply new category rate is only needed when moving to a different room type.'];
        }

        return [
            'ok' => true,
            'booking' => $booking,
            'parsed' => $parsed,
            'active_segment' => $activeSegment,
            'from_room' => $fromRoom,
            'to_room' => $toRoom,
            'transfer_at' => $transferAt,
            'segment_end' => $segmentEnd,
            'is_category_change' => $isCategoryChange,
            'pre_arrival_swap' => $booking->status === 'confirmed' && now()->lt(
                Carbon::parse($activeSegment->check_in_at ?? $activeSegment->check_in)
            ),
        ];
    }

    private static function resolveActiveSegment(Booking $booking): ?BookingSegment
    {
        $segments = $booking->segments->sortBy(fn($s) => $s->check_out_at ?? $s->check_out);
        if ($segments->isEmpty()) {
            return null;
        }

        if ($booking->status === 'checked_in') {
            $now = now();
            $active = $segments->first(function ($s) use ($now) {
                $ci = Carbon::parse($s->check_in_at ?? $s->check_in);
                $co = Carbon::parse($s->check_out_at ?? $s->check_out);

                return $ci->lte($now) && $co->gt($now);
            });
            if ($active) {
                return $active;
            }
        }

        return $segments->last();
    }

    private static function transferTimestamp(Booking $booking, BookingSegment $segment): Carbon
    {
        if ($booking->status === 'checked_in') {
            return now();
        }

        return Carbon::parse($segment->check_in_at ?? $segment->check_in);
    }

    private static function isRoomAvailable(int $roomId, Carbon $checkInAt, Carbon $checkOutAt, int $excludeBookingId): bool
    {
        $checkInDate = $checkInAt->toDateString();
        $isMidnight = $checkOutAt->format('H:i:s') === '00:00:00';
        $checkOutDateExclusive = $isMidnight
            ? $checkOutAt->toDateString()
            : $checkOutAt->copy()->addDay()->toDateString();

        $overlap = BookingSegment::query()
            ->where('room_id', $roomId)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $checkOutAt)
            ->where('check_out_at', '>', $checkInAt)
            ->where('booking_id', '!=', $excludeBookingId)
            ->exists();

        if ($overlap) {
            return false;
        }

        return ! RoomStatusBlock::query()
            ->where('room_id', $roomId)
            ->where('is_active', true)
            ->where('start_date', '<', $checkOutDateExclusive)
            ->where('end_date', '>', $checkInDate)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private static function pricingPreview(array $ctx): array
    {
        /** @var Booking $booking */
        $booking = $ctx['booking'];
        /** @var BookingSegment $segment */
        $segment = $ctx['active_segment'];
        /** @var Room $toRoom */
        $toRoom = $ctx['to_room'];
        $transferAt = $ctx['transfer_at'];
        $segmentEnd = $ctx['segment_end'];
        $parsed = $ctx['parsed'];
        $warnings = [];

        $oldTotal = (float) ($booking->total_price ?? 0);
        $segments = $booking->segments;

        if ($ctx['pre_arrival_swap']) {
            $newSegmentPrice = self::computeSegmentTotalForWindow(
                $booking,
                $toRoom,
                $transferAt,
                $segmentEnd,
                $parsed['rate_mode'],
                $segment,
                $warnings
            );
            $newBookingTotal = self::sumOtherSegments($segments, (int) $segment->id, 0) + $newSegmentPrice;

            return [
                'old_total' => $oldTotal,
                'new_total' => round($newBookingTotal, 2),
                'delta' => round($newBookingTotal - $oldTotal, 2),
                'segment_price' => round($newSegmentPrice, 2),
                'is_complimentary_upgrade' => $ctx['is_category_change'] && $parsed['rate_mode'] === 'keep_existing',
                'warnings' => $warnings,
            ];
        }

        $elapsedShare = self::elapsedFraction($segment, $transferAt);
        $oldSegmentFull = (float) ($segment->total_price ?? 0);
        $closedSegmentPrice = round($oldSegmentFull * $elapsedShare, 2);
        $remainingFromOld = round($oldSegmentFull - $closedSegmentPrice, 2);

        $newSegmentPrice = self::computeSegmentTotalForWindow(
            $booking,
            $toRoom,
            $transferAt,
            $segmentEnd,
            $parsed['rate_mode'],
            $segment,
            $warnings,
            $parsed['rate_mode'] === 'keep_existing' ? $remainingFromOld : null
        );

        $newBookingTotal = self::sumOtherSegments($segments, (int) $segment->id, $closedSegmentPrice) + $newSegmentPrice;

        return [
            'old_total' => $oldTotal,
            'new_total' => round($newBookingTotal, 2),
            'delta' => round($newBookingTotal - $oldTotal, 2),
            'segment_price' => round($newSegmentPrice, 2),
            'closed_segment_price' => $closedSegmentPrice,
            'is_complimentary_upgrade' => $ctx['is_category_change'] && $parsed['rate_mode'] === 'keep_existing',
            'warnings' => $warnings,
        ];
    }

    private static function elapsedFraction(BookingSegment $segment, Carbon $transferAt): float
    {
        $ci = Carbon::parse($segment->check_in_at ?? $segment->check_in);
        $co = Carbon::parse($segment->check_out_at ?? $segment->check_out);
        $totalMinutes = max(1, $ci->diffInMinutes($co));
        $elapsed = max(0, min($totalMinutes, $ci->diffInMinutes($transferAt)));

        return min(1.0, $elapsed / $totalMinutes);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BookingSegment>  $segments
     */
    private static function sumOtherSegments($segments, int $excludeSegmentId, float $replacementForExcluded): float
    {
        $sum = 0.0;
        foreach ($segments as $s) {
            if ((int) $s->id === $excludeSegmentId) {
                $sum += $replacementForExcluded;
            } else {
                $sum += (float) ($s->total_price ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private static function computeSegmentTotalForWindow(
        Booking $booking,
        Room $room,
        Carbon $from,
        Carbon $to,
        string $rateMode,
        BookingSegment $sourceSegment,
        array &$warnings,
        ?float $keepExistingCap = null
    ): float {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            $planId = (int) ($sourceSegment->rate_plan_id ?? $booking->rate_plan_id ?? 0);
            if ($rateMode === 'apply_new_category') {
                $planId = self::resolveRatePlanId($room, $planId);
            }
            $calc = self::computeHourlyTotal($room, $planId, $from, $to, (int) ($booking->extra_beds_count ?? 0));
            if (! $calc['ok']) {
                $warnings[] = $calc['message'];

                return $keepExistingCap ?? (float) ($sourceSegment->total_price ?? 0);
            }

            $total = (float) $calc['total'];
            if ($rateMode === 'keep_existing' && $keepExistingCap !== null) {
                return min($total, $keepExistingCap);
            }

            return $total;
        }

        if ($rateMode === 'keep_existing') {
            if ($keepExistingCap !== null) {
                return $keepExistingCap;
            }
            $planId = (int) ($sourceSegment->rate_plan_id ?? $booking->rate_plan_id ?? 0);
            $plan = RatePlan::find($planId);
            if ($plan) {
                return self::computeDayStayInclusive($booking, $room, $plan, $from, $to);
            }

            return (float) ($sourceSegment->total_price ?? 0);
        }

        $planId = self::resolveRatePlanId($room, (int) ($booking->rate_plan_id ?? 0));
        $plan = $room->roomType?->ratePlans?->firstWhere('id', $planId)
            ?? $room->roomType?->ratePlans?->first();
        if (! $plan) {
            $warnings[] = 'No rate plan on destination room type; keeping prior segment value.';

            return $keepExistingCap ?? (float) ($sourceSegment->total_price ?? 0);
        }

        return self::computeDayStayInclusive($booking, $room, $plan, $from, $to);
    }

    private static function resolveRatePlanId(Room $room, int $preferredId): int
    {
        $plans = $room->roomType?->ratePlans ?? collect();
        if ($preferredId > 0 && $plans->contains('id', $preferredId)) {
            return $preferredId;
        }
        $first = $plans->first();

        return $first ? (int) $first->id : $preferredId;
    }

    private static function computeDayStayInclusive(Booking $booking, Room $room, RatePlan $plan, Carbon $from, Carbon $to): float
    {
        $basePerNight = (float) ($plan->base_price ?? 0);
        $extraBeds = (int) ($booking->extra_beds_count ?? 0);
        $extraBedCost = (float) ($room->roomType?->extra_bed_cost ?? 0);
        $beforeTax = SeasonalRoomPricing::sumDayRoomRentWithSeasons(
            $basePerNight,
            $extraBedCost,
            $extraBeds,
            $from->copy()->startOfDay(),
            $to->copy()->startOfDay(),
            $room->roomType?->seasons ?? []
        );

        if ($plan->includes_breakfast ?? false) {
            $adults = (int) ($booking->adults_count ?? 1);
            $children = (int) ($booking->children_count ?? 0);
            $nights = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()));
            $beforeTax += (($room->roomType?->breakfast_price ?? 0) * $adults + ($room->roomType?->child_breakfast_price ?? 0) * $children) * $nights;
        }

        $taxRate = (float) ($room->roomType?->tax?->rate ?? 0);
        $roomRatesIncludeGst = filter_var(Setting::get('room_rates_include_gst', '0'), FILTER_VALIDATE_BOOLEAN);

        return $roomRatesIncludeGst
            ? round($beforeTax, 2)
            : round($beforeTax * (1 + ($taxRate / 100)), 2);
    }

    /**
     * @return array{ok: bool, message?: string, total?: float}
     */
    private static function computeHourlyTotal(Room $room, int $ratePlanId, Carbon $checkInAt, Carbon $checkOutAt, int $extraBeds): array
    {
        $rt = $room->roomType;
        $plan = $rt?->ratePlans?->firstWhere('id', $ratePlanId);
        if (! $rt || ! $plan || ($plan->billing_unit ?? 'day') !== 'hour_package') {
            return ['ok' => false, 'message' => 'Invalid hourly rate plan for destination room.'];
        }

        $pkgHours = (int) ($plan->package_hours ?? 0);
        if ($pkgHours <= 0) {
            return ['ok' => false, 'message' => 'Hourly package plan is missing package hours.'];
        }

        $packageEnd = $checkInAt->copy()->addHours($pkgHours);
        $base = (float) ($plan->package_price ?? $plan->base_price ?? 0);
        $rt->loadMissing('seasons');
        $season = SeasonalRoomPricing::seasonForDate($rt->seasons ?? [], $checkInAt->copy()->startOfDay());
        $base = SeasonalRoomPricing::applyToBase($base, $season);
        $total = $base;

        $extraBedCost = (float) ($rt->extra_bed_cost ?? 0);
        if ($extraBeds > 0 && $extraBedCost > 0) {
            $total += $extraBeds * $extraBedCost;
        }

        if ($checkOutAt->gt($packageEnd)) {
            $overtimeRate = $plan->overtime_hour_price;
            if ($overtimeRate === null) {
                return ['ok' => false, 'message' => 'Overtime is not allowed for this package on the destination room.'];
            }
            $grace = (int) ($plan->grace_minutes ?? 0);
            $step = max(1, (int) ($plan->overtime_step_minutes ?? 60));
            $extraMinutes = $packageEnd->diffInMinutes($checkOutAt);
            $billableMinutes = max(0, $extraMinutes - $grace);
            $steps = (int) ceil($billableMinutes / $step);
            $billableHours = ($steps * $step) / 60;
            $total += $billableHours * (float) $overtimeRate;
        }

        if ($rt->tax) {
            $total += $total * ((float) $rt->tax->rate / 100);
        }

        return ['ok' => true, 'total' => round($total, 2)];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $preview
     * @return array{new_segment: BookingSegment, new_segment_id: int}
     */
    private static function applyTransfer(array $ctx, array $preview): array
    {
        /** @var Booking $booking */
        $booking = $ctx['booking'];
        /** @var BookingSegment $segment */
        $segment = $ctx['active_segment'];
        /** @var Room $toRoom */
        $toRoom = $ctx['to_room'];
        $transferAt = $ctx['transfer_at'];
        $segmentEnd = $ctx['segment_end'];
        $parsed = $ctx['parsed'];
        $segmentStatus = $booking->status === 'checked_in' ? 'checked_in' : 'confirmed';

        $newSegmentPrice = (float) $preview['segment_price'];
        $newBookingTotal = (float) $preview['new_total'];
        $planId = $parsed['rate_mode'] === 'apply_new_category'
            ? self::resolveRatePlanId($toRoom, (int) ($booking->rate_plan_id ?? 0))
            : (int) ($segment->rate_plan_id ?? $booking->rate_plan_id);

        if ($ctx['pre_arrival_swap']) {
            $segment->update([
                'room_id' => $toRoom->id,
                'rate_plan_id' => $planId ?: $segment->rate_plan_id,
                'total_price' => $newSegmentPrice,
            ]);
            $booking->update([
                'room_id' => $toRoom->id,
                'total_price' => $newBookingTotal,
                'rate_plan_id' => $planId ?: $booking->rate_plan_id,
            ]);

            return ['new_segment' => $segment->fresh(), 'new_segment_id' => (int) $segment->id];
        }

        $closedPrice = (float) ($preview['closed_segment_price'] ?? 0);
        // Guest has left this room — mark segment checked_out so the room chart does not
        // keep showing occupancy on the vacated room (split-stay extensions keep checked_in).
        $segment->update([
            'check_out' => $transferAt->toDateString(),
            'check_out_at' => $transferAt,
            'total_price' => $closedPrice,
            'status' => 'checked_out',
        ]);

        $newSegment = BookingSegment::create([
            'booking_id' => $booking->id,
            'room_id' => $toRoom->id,
            'check_in' => $transferAt->toDateString(),
            'check_out' => $segmentEnd->toDateString(),
            'check_in_at' => $transferAt,
            'check_out_at' => $segmentEnd,
            'rate_plan_id' => $planId ?: null,
            'adults_count' => $segment->adults_count ?? $booking->adults_count,
            'children_count' => $segment->children_count ?? $booking->children_count,
            'extra_beds_count' => $segment->extra_beds_count ?? $booking->extra_beds_count,
            'total_price' => $newSegmentPrice,
            'status' => $segmentStatus,
        ]);

        $booking->update([
            'room_id' => $toRoom->id,
            'total_price' => $newBookingTotal,
            'rate_plan_id' => $planId ?: $booking->rate_plan_id,
        ]);

        return ['new_segment' => $newSegment, 'new_segment_id' => (int) $newSegment->id];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $result
     */
    private static function recordTransfer(Booking $booking, array $ctx, array $preview, array $result): BookingRoomTransfer
    {
        /** @var Room $fromRoom */
        $fromRoom = $ctx['from_room'];
        /** @var Room $toRoom */
        $toRoom = $ctx['to_room'];
        $parsed = $ctx['parsed'];

        return BookingRoomTransfer::create([
            'booking_id' => $booking->id,
            'booking_segment_id' => $result['new_segment_id'],
            'from_room_id' => $fromRoom->id,
            'to_room_id' => $toRoom->id,
            'from_room_type_id' => (int) $fromRoom->room_type_id,
            'to_room_type_id' => (int) $toRoom->room_type_id,
            'transfer_reason' => $parsed['transfer_reason'],
            'internal_notes' => $parsed['internal_notes'],
            'rate_mode' => $parsed['rate_mode'],
            'is_complimentary_upgrade' => (bool) ($preview['is_complimentary_upgrade'] ?? false),
            'old_total_price' => (float) $preview['old_total'],
            'new_total_price' => (float) $preview['new_total'],
            'price_delta' => (float) $preview['delta'],
            'segment_price' => (float) $preview['segment_price'],
            'transferred_at' => $ctx['transfer_at'],
            'performed_by' => Auth::id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $preview
     */
    private static function appendAuditNote(Booking $booking, array $ctx, array $preview, BookingRoomTransfer $transfer): void
    {
        /** @var Room $fromRoom */
        $fromRoom = $ctx['from_room'];
        /** @var Room $toRoom */
        $toRoom = $ctx['to_room'];
        $parsed = $ctx['parsed'];

        $user = Auth::user();
        $userName = $user ? (string) $user->name : '';
        $byPart = $userName !== '' ? " by {$userName}" : '';
        $when = now()->format('Y-m-d H:i:s');

        $fromLabel = $fromRoom->roomType?->name ?? 'Room';
        $toLabel = $toRoom->roomType?->name ?? 'Room';
        $reasonLabel = BookingRoomTransfer::reasonLabel($parsed['transfer_reason']);
        $rateLabel = $parsed['rate_mode'] === 'keep_existing' ? 'keep existing' : 'new category rate';
        $delta = (float) $preview['delta'];
        $deltaStr = ($delta >= 0 ? '+' : '') . number_format($delta, 2, '.', '');

        $audit = sprintf(
            '[Room Transfer: #%s (%s) → #%s (%s) | Reason: %s | Rate: %s | Δ ₹%s%s on %s]',
            $fromRoom->room_number,
            $fromLabel,
            $toRoom->room_number,
            $toLabel,
            $reasonLabel,
            $rateLabel,
            $deltaStr,
            $byPart,
            $when
        );

        if (! empty($parsed['internal_notes'])) {
            $audit .= ' Note: ' . str_replace(["\n", "\r", ']'], [' ', ' ', ''], (string) $parsed['internal_notes']);
        }

        $notes = $booking->notes ? $booking->notes . "\n" . $audit : $audit;
        $booking->update(['notes' => $notes]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private static function applyHousekeeping(Booking $booking, array $ctx): void
    {
        if ($booking->status !== 'checked_in') {
            return;
        }

        /** @var Room $fromRoom */
        $fromRoom = $ctx['from_room'];
        /** @var Room $toRoom */
        $toRoom = $ctx['to_room'];
        $transferAt = $ctx['transfer_at'];

        Room::where('id', $fromRoom->id)->update(['status' => 'dirty']);
        Room::where('id', $toRoom->id)->update(['status' => 'occupied']);

        $co = $transferAt->copy()->startOfDay();
        $coStr = $co->toDateString();
        $coNext = $co->copy()->addDay()->toDateString();

        RoomStatusBlock::query()
            ->where('room_id', $fromRoom->id)
            ->where('is_active', true)
            ->whereIn('status', ['inspected', 'pending_inspection'])
            ->update(['is_active' => false]);

        $hasBlock = RoomStatusBlock::where('room_id', $fromRoom->id)
            ->where('is_active', true)
            ->where('start_date', '<', $coNext)
            ->where('end_date', '>', $coStr)
            ->exists();

        if (! $hasBlock) {
            RoomStatusBlock::create([
                'room_id' => $fromRoom->id,
                'status' => 'dirty',
                'start_date' => $coStr,
                'end_date' => $coNext,
                'note' => 'Auto: room transfer',
                'is_active' => true,
                'created_by' => Auth::id(),
            ]);
        }

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $fromRoom->id, (int) $toRoom->id], 'room_transfer');
    }
}
