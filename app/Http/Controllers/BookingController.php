<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Events\HousekeepingStateUpdated;
use App\Models\Booking;
use App\Models\BookingExtraCharge;
use App\Models\BookingGroup;
use App\Models\BookingPayment;
use App\Models\BookingSegment;
use App\Models\DailyRoomCleaning;
use App\Models\PosOrder;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomCleaningRelease;
use App\Models\RoomStatusBlock;
use App\Models\RoomType;
use App\Models\Setting;
use App\Support\BookingCancellationPolicy;
use App\Support\BookingInspectionChargeLines;
use App\Support\BookingInvoiceNumber;
use App\Support\BookingInvoiceRoomStay;
use App\Support\BookingNumber;
use App\Support\BookingPaymentLedger;
use App\Support\BookingRoomAvailability;
use App\Support\BookingRoomTransferService;
use App\Support\BookingSplitStayRoomMove;
use App\Support\DoorloomStaySync;
use App\Support\HotelApiSync;
use App\Support\HousekeepingTurnoverCarryForward;
use App\Support\PortalNotifications;
use App\Support\CheckoutInspectionInspector;
use App\Support\CheckoutInspectionPenaltyAmount;
use App\Support\ReservationInvoiceViewData;
use App\Services\GuestIdentityImageService;
use App\Support\SeasonalRoomPricing;
use App\Services\Accounting\BookingCheckoutPoster;
use App\Services\Accounting\BookingRefundPoster;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BookingController extends Controller
{
    use AuthorizesSpatiePermissions;

    /** Status changes allowed through PATCH /bookings/{id}; cancel has its own endpoint. */
    private const STATUS_TRANSITIONS = [
        'pending' => ['confirmed', 'checked_in'],
        'confirmed' => ['pending', 'checked_in'],
        'checked_in' => ['checked_out'],
        'checked_out' => [],
        'cancelled' => [],
    ];

    /** Stay, room and money fields that cannot change once a booking is checked out or cancelled. */
    private const CHART_MAX_DAYS = 62;

    private const FIELDS_FROZEN_AFTER_CLOSE = [
        'room_id', 'booking_unit', 'check_in', 'check_out', 'check_in_at', 'check_out_at',
        'total_price', 'deposit_amount', 'refund_amount', 'refund_method', 'payment_method',
        'rate_plan_id', 'booking_group_id', 'adults_count', 'children_count', 'child_ages',
        'infants_count', 'extra_beds_count', 'adult_breakfast_count', 'child_breakfast_count',
    ];

    /** Room chart grid + summary tiles (no booking drawer / folio). */
    private function allowReservationChartRead(): void
    {
        $this->authorizePermissions([
            'reservation-view',
            'reservation',
            'view-rooms',
            'manage-rooms',
            'rooms-view',
        ]);
    }

    private function allowReservationRead(): void
    {
        $this->authorizePermissions(['reservation-view', 'reservation']);
    }

    private function allowReservationDetail(): void
    {
        $this->authorizePermissions(['reservation-view', 'reservation']);
    }

    /** Guest lookup while creating a booking (no view permission required). */
    private function allowReservationGuestSearch(): void
    {
        $this->authorizePermissions([
            'reservation-view',
            'reservation-create',
            'reservation-create-group',
            'reservation-edit',
        ]);
    }

    /** Single-room (non-group) POST /bookings. */
    private function allowReservationCreateSingle(): void
    {
        $this->authorizePermissions(['reservation-create']);
    }

    /** Multi-room or group-name POST /bookings, or POST /booking-groups. */
    private function allowReservationCreateGroup(): void
    {
        $this->authorizePermissions(['reservation-create-group']);
    }

    private function allowReservationEdit(): void
    {
        $this->authorizePermissions(['reservation-edit']);
    }

    private function allowReservationDelete(): void
    {
        $this->authorizePermissions(['reservation-delete']);
    }

    private function allowReservationBillingExport(): void
    {
        $this->authorizePermissions(['reservation-view', 'reservation-edit']);
    }

    private function allowAvailableRoomsLookup(): void
    {
        $this->authorizePermissions(['reservation-create', 'reservation-create-group', 'reservation-edit', 'reservation', 'view-rooms']);
    }

    /**
     * Append structured audit lines to booking notes when PATCH changes guest, rate, deposit, etc.
     * Format: [Tag: … by Name on Y-m-d H:i:s] (by Name optional)
     */
    private function appendAuditNotesForBookingUpdate(Booking $booking, array &$validated, Request $request): void
    {
        $user = Auth::user();
        $userName = $user ? (string) $user->name : '';
        $when = now()->format('Y-m-d H:i:s');
        $byPart = $userName !== '' ? " by {$userName}" : '';
        $onPart = " on {$when}";

        $sanitize = static fn(?string $s): string => trim(str_replace(["\n", "\r", ']'], [' ', ' ', ''], (string) $s));

        $lines = [];

        $guestBits = [];
        foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'phone' => 'Phone', 'city' => 'City', 'country' => 'Country', 'bill_to_name' => 'Bill to name', 'guest_gstin' => 'Guest GSTIN'] as $field => $label) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $old = $sanitize((string) ($booking->{$field} ?? ''));
            $new = $sanitize((string) $validated[$field]);
            if ($old !== $new) {
                $guestBits[] = "{$label}: " . ($old !== '' ? $old : '—') . ' → ' . ($new !== '' ? $new : '—');
            }
        }

        foreach (['adults_count' => 'Adults', 'children_count' => 'Children', 'infants_count' => 'Infants', 'extra_beds_count' => 'Extra beds'] as $field => $label) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $old = (int) ($booking->{$field} ?? 0);
            $new = (int) $validated[$field];
            if ($old !== $new) {
                $guestBits[] = "{$label}: {$old} → {$new}";
            }
        }

        if (array_key_exists('child_ages', $validated)) {
            $oldJson = json_encode($booking->child_ages ?? []);
            $newJson = json_encode($validated['child_ages'] ?? []);
            if ($oldJson !== $newJson) {
                $guestBits[] = 'Child ages updated';
            }
        }

        if (array_key_exists('booking_unit', $validated)) {
            $old = (string) ($booking->booking_unit ?? 'day');
            $new = (string) $validated['booking_unit'];
            if ($old !== $new) {
                $guestBits[] = "Booking unit: {$old} → {$new}";
            }
        }

        if ($guestBits !== []) {
            $lines[] = '[Guest / stay: ' . implode('; ', $guestBits) . $byPart . $onPart . ']';
        }

        if (array_key_exists('rate_plan_id', $validated)) {
            $oldId = $booking->rate_plan_id ? (int) $booking->rate_plan_id : null;
            $nv = $validated['rate_plan_id'] ?? null;
            $newId = $nv !== null && $nv !== '' ? (int) $nv : null;
            if ($oldId !== $newId) {
                $oldName = $oldId ? (RatePlan::find($oldId, ['name'])?->name ?? "#{$oldId}") : '—';
                $newName = $newId ? (RatePlan::find($newId, ['name'])?->name ?? "#{$newId}") : '—';
                $lines[] = "[Rate plan: {$oldName} → {$newName}{$byPart}{$onPart}]";
            }
        }

        if (array_key_exists('deposit_amount', $validated)) {
            $old = (float) ($booking->deposit_amount ?? 0);
            $new = (float) $validated['deposit_amount'];
            if (abs($old - $new) > 0.004) {
                $d = $new - $old;
                $sign = $d >= 0 ? '+' : '−';
                $lines[] = sprintf(
                    '[Deposit: ₹%s → ₹%s (%s₹%s)%s%s]',
                    number_format($old, 2, '.', ''),
                    number_format($new, 2, '.', ''),
                    $sign,
                    number_format(abs($d), 2, '.', ''),
                    $byPart,
                    $onPart
                );
            }
        }

        if (array_key_exists('refund_amount', $validated) && $validated['refund_amount'] !== null) {
            $old = (float) ($booking->refund_amount ?? 0);
            $new = (float) $validated['refund_amount'];
            if (abs($old - $new) > 0.004) {
                $method = (string) ($validated['refund_method'] ?? $booking->refund_method ?? '');
                $lines[] = sprintf(
                    '[Refund recorded: ₹%s%s%s]',
                    number_format($new, 2, '.', ''),
                    $method !== '' ? " ({$method})" : '',
                    $byPart . $onPart
                );
            }
        }

        if (array_key_exists('total_price', $validated)) {
            $old = (float) ($booking->total_price ?? 0);
            $new = (float) $validated['total_price'];
            if (abs($old - $new) > 0.004) {
                $lines[] = sprintf('[Total: ₹%s → ₹%s%s%s]', number_format($old, 2, '.', ''), number_format($new, 2, '.', ''), $byPart, $onPart);
            }
        }

        if (array_key_exists('payment_status', $validated)) {
            $old = (string) ($booking->payment_status ?? '');
            $new = (string) $validated['payment_status'];
            if ($old !== $new) {
                $lines[] = "[Payment status: {$old} → {$new}{$byPart}{$onPart}]";
            }
        }

        if (array_key_exists('payment_method', $validated)) {
            $old = (string) ($booking->payment_method ?? '');
            $new = (string) ($validated['payment_method'] ?? '');
            if ($old !== $new) {
                $lines[] = '[Payment method: ' . ($old !== '' ? $old : '—') . ' → ' . ($new !== '' ? $new : '—') . $byPart . $onPart . ']';
            }
        }

        foreach (['adult_breakfast_count' => 'Adult breakfast', 'child_breakfast_count' => 'Child breakfast'] as $field => $label) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $old = (int) ($booking->{$field} ?? 0);
            $new = (int) $validated[$field];
            if ($old !== $new) {
                $lines[] = "[{$label}: {$old} → {$new}{$byPart}{$onPart}]";
            }
        }

        if ($request->has('guest_identities')) {
            $oldSig = json_encode($booking->guest_identities ?? []);
            $newSig = json_encode($validated['guest_identities'] ?? []);
            if ($oldSig !== $newSig) {
                $lines[] = "[Guest IDs: documents updated{$byPart}{$onPart}]";
            }
        }

        if (array_key_exists('room_id', $validated)) {
            $oldR = (int) $booking->room_id;
            $newR = (int) $validated['room_id'];
            if ($oldR !== $newR) {
                $o = Room::find($oldR, ['room_number'])?->room_number ?? (string) $oldR;
                $n = Room::find($newR, ['room_number'])?->room_number ?? (string) $newR;
                $lines[] = "[Room: #{$o} → #{$n}{$byPart}{$onPart}]";
            }
        }

        if (array_key_exists('check_in', $validated) || array_key_exists('check_out', $validated)) {
            $oldCi = (string) $booking->check_in;
            $newCi = (string) ($validated['check_in'] ?? $booking->check_in);
            $oldCo = (string) $booking->check_out;
            $newCo = (string) ($validated['check_out'] ?? $booking->check_out);
            if ($oldCi !== $newCi || $oldCo !== $newCo) {
                $lines[] = "[Stay dates: {$oldCi} → {$oldCo} changed to {$newCi} → {$newCo}{$byPart}{$onPart}]";
            }
        }

        if (array_key_exists('checkout_discount_amount', $validated)) {
            $old = (float) ($booking->checkout_discount_amount ?? 0);
            $new = (float) $validated['checkout_discount_amount'];
            if (abs($old - $new) > 0.004) {
                $reason = $sanitize((string) ($validated['checkout_discount_reason'] ?? $booking->checkout_discount_reason ?? ''));
                $reasonBit = $reason !== '' ? ' — ' . $reason : '';
                $lines[] = sprintf(
                    '[Checkout discount: ₹%s → ₹%s%s%s%s]',
                    number_format($old, 2, '.', ''),
                    number_format($new, 2, '.', ''),
                    $reasonBit,
                    $byPart,
                    $onPart
                );
            }
        }

        if ($lines === []) {
            return;
        }

        $block = implode("\n", $lines);
        $existing = $validated['notes'] ?? $booking->notes ?? '';
        $validated['notes'] = $existing !== '' ? $existing . "\n" . $block : $block;
    }

    private function dateEndExclusiveFromDateTime(Carbon $dt): string
    {
        // If checkout is exactly at midnight, the date itself is already exclusive.
        // Otherwise, the occupancy includes that calendar date, so end-exclusive is next day.
        $isMidnight = $dt->format('H:i:s') === '00:00:00';

        return $isMidnight ? $dt->toDateString() : $dt->copy()->addDay()->toDateString();
    }

    private function computeHourlyPackageTotal(Room $room, int $ratePlanId, Carbon $checkInAt, Carbon $checkOutAt, int $extraBeds = 0, int $adults = 0, int $children = 0, ?array $childAges = null): array
    {
        $rt = $room->roomType;
        $plan = $rt?->ratePlans?->firstWhere('id', $ratePlanId);
        if (! $rt || ! $plan) {
            return ['ok' => false, 'message' => 'Invalid rate plan for selected room.'];
        }
        if (($plan->billing_unit ?? 'day') !== 'hour_package') {
            return ['ok' => false, 'message' => 'Selected rate plan is not an hourly package.'];
        }
        $pkgHours = (int) ($plan->package_hours ?? 0);
        if ($pkgHours <= 0) {
            return ['ok' => false, 'message' => 'Hourly package plan is missing package hours.'];
        }

        $packageEnd = $checkInAt->copy()->addHours($pkgHours);
        if ($checkOutAt->lt($packageEnd)) {
            return ['ok' => false, 'message' => "Checkout cannot be earlier than package end time ({$pkgHours}h)."];
        }

        $base = (float) ($plan->package_price ?? $plan->base_price ?? 0);
        $rt->loadMissing('seasons');
        $season = SeasonalRoomPricing::seasonForDate($rt->seasons ?? [], $checkInAt->copy()->startOfDay());
        $base = SeasonalRoomPricing::applyToBase($base, $season);
        $total = $base;

        // Hourly package: extra bed is charged once per booking/package window.
        $total += SeasonalRoomPricing::extraBedPreTax($rt, $adults, $children, $childAges, $extraBeds);

        // Optional overtime charging
        if ($checkOutAt->gt($packageEnd)) {
            $overtimeRate = $plan->overtime_hour_price;
            if ($overtimeRate === null) {
                return ['ok' => false, 'message' => 'Overtime is not allowed for this package.'];
            }

            $grace = (int) ($plan->grace_minutes ?? 0);
            $step = max(1, (int) ($plan->overtime_step_minutes ?? 60));
            $extraMinutes = $packageEnd->diffInMinutes($checkOutAt);
            $billableMinutes = max(0, $extraMinutes - $grace);
            $steps = (int) ceil($billableMinutes / $step);
            $billableHours = ($steps * $step) / 60;
            $total += $billableHours * (float) $overtimeRate;
        }

        // Taxes follow existing behavior (apply on subtotal)
        if ($rt->tax) {
            $total += $total * ((float) $rt->tax->rate / 100);
        }

        return ['ok' => true, 'total' => round($total, 2), 'package_end' => $packageEnd];
    }

    /**
     * Room + tax (recomputed or stored) + folio extras, before checkout discount.
     * Uses {@see BookingInvoiceRoomStay::summarizeForInvoice} so totals match the reservation invoice PDF and room chart.
     */
    private function bookingGrossBeforeCheckoutDiscount(Booking $booking): float
    {
        $booking->loadMissing(['room.roomType.tax', 'room.roomType.ratePlans']);

        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return (float) ($booking->total_price ?? 0) + (float) ($booking->extra_charges ?? 0);
        }

        return BookingInvoiceRoomStay::summarizeForInvoice($booking)['gross_before_checkout_discount'];
    }

    /**
     * Lock the booking row for the current transaction. Guards ran on the copy loaded at request start,
     * so refuse when another request changed its status, dates or money in between (double click, two desks).
     */
    private function lockBookingForChange(Booking $booking): void
    {
        $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

        $moneyChanged = false;
        foreach (['total_price', 'deposit_amount', 'refund_amount', 'extra_charges'] as $field) {
            if (abs((float) ($locked->{$field} ?? 0) - (float) ($booking->{$field} ?? 0)) > 0.004) {
                $moneyChanged = true;
            }
        }
        $stayChanged = (string) $locked->status !== (string) $booking->status
            || (int) $locked->room_id !== (int) $booking->room_id
            || (string) $locked->check_in !== (string) $booking->check_in
            || (string) $locked->check_out !== (string) $booking->check_out
            || (string) $locked->check_out_at !== (string) $booking->check_out_at
            || (string) $locked->early_checkin_time !== (string) $booking->early_checkin_time
            || (string) $locked->late_checkout_time !== (string) $booking->late_checkout_time;

        if ($moneyChanged || $stayChanged) {
            throw ValidationException::withMessages([
                'booking' => 'This reservation was just changed by another action. Reload it and try again.',
            ]);
        }
    }

    private function paymentStatusFor(float $net, float $bill): string
    {
        if ($net <= 0.004) {
            return 'pending';
        }

        return $bill > 0.004 && $net >= $bill - 0.004 ? 'paid' : 'partial';
    }

    /** Payable total after checkout (commercial) discount. */
    private function effectiveBookingGrand(Booking $booking): float
    {
        $gross = $this->bookingGrossBeforeCheckoutDiscount($booking);
        $disc = max(0.0, (float) ($booking->checkout_discount_amount ?? 0));

        return max(0.0, round($gross - min($disc, $gross), 2));
    }

    /** Cancelled group members contribute their cancellation fee, not the original stay. */
    private function groupMemberPayable(Booking $booking): float
    {
        if ($booking->status === 'cancelled') {
            return round((float) ($booking->cancellation_fee_amount ?? 0), 2);
        }

        return $this->effectiveBookingGrand($booking);
    }

    /**
     * Checked-in group members that leave with this checkout: due today, or sharing this room's departure day.
     *
     * @return \Illuminate\Support\Collection<int, Booking>
     */
    private function departingGroupMembers(Booking $booking, string $checkoutScope): \Illuminate\Support\Collection
    {
        $members = collect([$booking]);
        if ($checkoutScope !== 'group' || ! $booking->booking_group_id) {
            return $members;
        }

        $today = Carbon::today()->toDateString();
        $thisDay = $this->bookingCheckoutCalendarDay($booking);
        $mates = Booking::query()
            ->where('booking_group_id', '=', $booking->booking_group_id, 'and')
            ->where('id', '!=', $booking->id)
            ->where('status', '=', 'checked_in', 'and')
            ->get()
            ->filter(function (Booking $mate) use ($today, $thisDay) {
                $day = $this->bookingCheckoutCalendarDay($mate);

                return $day <= $today || $day === $thisDay;
            });

        return $members->concat($mates)->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Booking>  $members
     * @return array{status: 'done'|'pending'|'none', rooms: list<string>}
     */
    private function checkoutInspectionForDeparture(\Illuminate\Support\Collection $members): array
    {
        $rooms = [];
        $anyNone = false;
        foreach ($members as $member) {
            $state = $this->checkoutInspectionState($member);
            if ($state['status'] === 'done') {
                continue;
            }
            $rooms = array_merge($rooms, $state['rooms']);
            if ($state['status'] !== 'pending') {
                $anyNone = true;
            }
        }
        $rooms = array_values(array_unique($rooms));
        if ($rooms === []) {
            return ['status' => 'done', 'rooms' => []];
        }

        return ['status' => $anyNone ? 'none' : 'pending', 'rooms' => $rooms];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkedOutStayAttributes(Booking $booking): array
    {
        $attrs = ['status' => 'checked_out'];
        $today = Carbon::today()->toDateString();
        if ((string) $booking->check_out > $today) {
            $attrs['check_out'] = $today;
            $attrs['check_out_at'] = Carbon::parse($today)->startOfDay()->addDay();
            $userName = Auth::user()?->name ?: '';
            $auditMsg = "[Early CO: on {$today}" . ($userName !== '' ? " by {$userName}" : '') . ']';
            $existing = $booking->notes;
            $attrs['notes'] = $existing ? $existing . "\n" . $auditMsg : $auditMsg;
        }

        return $attrs;
    }

    private function recordGroupCheckoutRefund(Booking $booking, float $amount, string $method, ?float $billHint): void
    {
        $remaining = round($amount, 2);
        $members = Booking::query()
            ->where('booking_group_id', '=', $booking->booking_group_id, 'and')
            ->where('status', '!=', 'cancelled')
            ->get()
            ->sortBy(fn (Booking $member) => (int) $member->id === (int) $booking->id ? 0 : 1)
            ->values();

        foreach ($members as $member) {
            if ($remaining <= 0.004) {
                break;
            }
            $net = BookingPaymentLedger::totals($member->fresh())['net'];
            $slice = round(min($remaining, max(0.0, $net)), 2);
            if ($slice <= 0.004) {
                continue;
            }
            BookingPaymentLedger::recordRefund($member, [
                'amount' => $slice,
                'method' => $method,
                'source' => 'checkout',
                'notes' => 'Refund recorded via booking update',
                'bill_total' => $billHint,
                'allow_closed' => true,
            ]);
            $remaining = round($remaining - $slice, 2);
        }

        if ($remaining > 0.004) {
            throw ValidationException::withMessages([
                'amount' => 'Refund cannot exceed the net amount collected on the group.',
            ]);
        }
    }

    /** Segment, room, and dirty-block side effects for a group room that departed with another. */
    private function applyCheckoutRoomEffects(Booking $booking): void
    {
        $segmentCount = $booking->segments()->count();
        if ($segmentCount === 0) {
            $booking->segments()->create([
                'room_id' => $booking->room_id,
                'check_in' => $booking->check_in,
                'check_out' => $booking->check_out,
                'check_in_at' => $booking->check_in_at ?? Carbon::parse($booking->check_in)->startOfDay(),
                'check_out_at' => $booking->check_out_at ?? Carbon::parse($booking->check_out)->startOfDay(),
                'total_price' => $booking->total_price,
                'status' => 'checked_out',
            ]);
        } elseif ($segmentCount === 1) {
            $booking->segments()->first()->update([
                'check_in' => $booking->check_in,
                'check_out' => $booking->check_out,
                'check_in_at' => $booking->check_in_at ?? Carbon::parse($booking->check_in)->startOfDay(),
                'check_out_at' => $booking->check_out_at ?? Carbon::parse($booking->check_out)->startOfDay(),
                'total_price' => $booking->total_price,
                'status' => 'checked_out',
            ]);
        } else {
            $booking->segments()->update(['status' => 'checked_out']);
        }

        $roomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->map(fn ($id) => (int) $id)->unique()->filter();
        Room::whereIn('id', $roomIds->all(), 'and', false)->update(['status' => 'dirty']);

        $notify = [];
        $dirtyRoomIds = [];
        foreach ($booking->segments()->get() as $segment) {
            $rid = (int) $segment->room_id;
            if ($rid <= 0) {
                continue;
            }
            $notify[] = $rid;
            $checkoutDay = Carbon::parse((string) ($segment->check_out ?? $booking->check_out))->startOfDay();
            $co = $checkoutDay->toDateString();
            $coNext = $checkoutDay->copy()->addDay()->toDateString();

            RoomStatusBlock::query()
                ->where('room_id', '=', $rid, 'and')
                ->where('is_active', '=', true, 'and')
                ->whereIn('status', ['inspected', 'pending_inspection'], 'and', false)
                ->update(['is_active' => false]);
            RoomStatusBlock::query()
                ->where('room_id', '=', $rid, 'and')
                ->where('is_active', '=', true, 'and')
                ->whereIn('status', ['cleaning', 'dirty'], 'and', false)
                ->where('start_date', '<', $coNext)
                ->where('end_date', '>', $co)
                ->update(['is_active' => false]);

            $hasBlock = RoomStatusBlock::where('room_id', '=', $rid, 'and')
                ->where('is_active', true)
                ->where('start_date', '<', $coNext)
                ->where('end_date', '>', $co)
                ->exists();
            if (! $hasBlock) {
                RoomStatusBlock::create([
                    'room_id' => $rid,
                    'status' => 'dirty',
                    'start_date' => $co,
                    'end_date' => $coNext,
                    'note' => 'Auto: checkout',
                    'is_active' => true,
                    'created_by' => Auth::id(),
                ]);
                $dirtyRoomIds[] = $rid;
            }
        }

        $guestName = trim((string) ($booking->guest_name ?? ''));
        $actorId = Auth::id() ? (int) Auth::id() : null;
        foreach (array_values(array_unique($dirtyRoomIds)) as $dirtyRoomId) {
            PortalNotifications::recordDirtyRoom(
                (int) $dirtyRoomId,
                (int) $booking->id,
                $guestName !== '' ? $guestName : null,
                $actorId,
            );
        }

        HousekeepingStateUpdated::dispatchIfEnabled(array_values(array_unique($notify)), 'booking_checkout');
    }

    /**
     * Paid-in-full rule for checkout. Group bookings pool payments unless scope is "room".
     * Money held (payments minus refunds) must cover the current bill; payment_status is not trusted because folio
     * charges posted after payment (inspection, POS room charge, fees) leave it stale at "paid".
     *
     * @return array{is_paid: bool, bill: float, paid: float, pooled: bool}
     */
    private function checkoutPaymentState(Booking $booking, string $checkoutScope): array
    {
        $pooled = ! empty($booking->booking_group_id) && $checkoutScope !== 'room';

        if ($pooled) {
            $groupBookings = Booking::where('booking_group_id', '=', $booking->booking_group_id, 'and')
                ->with(['room.roomType.tax', 'room.roomType.ratePlans'])
                ->get();
            $bill = (float) $groupBookings->sum(fn($b) => $this->groupMemberPayable($b));
            $paid = (float) $groupBookings->sum(fn($b) => (float) ($b->deposit_amount ?? 0) - (float) ($b->refund_amount ?? 0));
        } else {
            $bill = $this->effectiveBookingGrand($booking);
            $paid = (float) ($booking->deposit_amount ?? 0) - (float) ($booking->refund_amount ?? 0);
        }

        return [
            'is_paid' => $paid + 0.009 >= $bill,
            'bill' => round($bill, 2),
            'paid' => round($paid, 2),
            'pooled' => $pooled,
        ];
    }

    /**
     * Checkout inspection progress for every room the guest still occupies (vacated split-stay /
     * transfer rooms are skipped). "done" = each room has an active inspected handoff for this booking.
     *
     * @return array{status: 'done'|'pending'|'none', rooms: list<string>}
     */
    private function checkoutInspectionState(Booking $booking): array
    {
        $roomIds = $booking->segments()
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->pluck('room_id')
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        if ($roomIds->isEmpty() && (int) $booking->room_id > 0) {
            $roomIds = collect([(int) $booking->room_id]);
        }

        $forBooking = function (RoomStatusBlock $block) use ($booking): bool {
            $snapBookingId = $block->inspection_snapshot['booking_id'] ?? null;

            return $snapBookingId === null || (int) $snapBookingId === (int) $booking->id;
        };
        $handoffs = RoomStatusBlock::query()
            ->whereIn('room_id', $roomIds->all(), 'and', false)
            ->where('is_active', '=', true, 'and')
            ->whereIn('status', ['inspected', 'pending_inspection'], 'and', false)
            ->get(['room_id', 'status', 'inspection_snapshot'])
            ->filter($forBooking);

        $inspectedRoomIds = $handoffs
            ->filter(fn(RoomStatusBlock $b) => $b->status === 'inspected' && $b->inspection_snapshot !== null)
            ->pluck('room_id')
            ->map(fn($id) => (int) $id);
        $missing = $roomIds->diff($inspectedRoomIds)->values();
        if ($missing->isEmpty()) {
            return ['status' => 'done', 'rooms' => []];
        }

        $pending = $handoffs
            ->where('status', 'pending_inspection')
            ->pluck('room_id')
            ->map(fn($id) => (int) $id)
            ->intersect($missing)
            ->isNotEmpty();

        return [
            'status' => $pending ? 'pending' : 'none',
            'rooms' => Room::whereIn('id', $missing->all(), 'and', false)
                ->orderBy('room_number')
                ->pluck('room_number')
                ->map(fn($n) => (string) $n)
                ->all(),
        ];
    }

    /**
     * Checkout settlement as the checkout guard sees it (room scope), for front-desk actions outside the room chart.
     */
    public function previewCheckout(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if ($booking->status !== 'checked_in') {
            return response()->json(['message' => 'Only checked-in guests can be checked out.'], 422);
        }

        $validated = $request->validate([
            'checkout_scope' => 'nullable|in:room,group',
        ]);
        $scope = $validated['checkout_scope'] ?? (empty($booking->booking_group_id) ? 'room' : 'group');

        BookingSplitStayRoomMove::sync((int) $booking->id);
        $booking->refresh();

        $state = $this->checkoutPaymentState($booking, $scope);
        $inspection = $this->checkoutInspectionForDeparture($this->departingGroupMembers($booking, $scope));
        $summary = $this->bookingBillingSummary($booking);
        $refunded = round((float) ($booking->refund_amount ?? 0), 2);
        $netReceived = $state['paid'];
        $checkoutDay = $this->bookingCheckoutCalendarDay($booking);
        $today = Carbon::today()->toDateString();
        $refundDue = max(0.0, round($netReceived - $state['bill'], 2));

        return response()->json([
            'booking_id' => $booking->id,
            'checkout_scope' => $scope,
            'pooled' => $state['pooled'],
            'bill' => $state['bill'],
            'received' => $netReceived,
            'balance_due' => max(0.0, round($state['bill'] - $state['paid'], 2)),
            'refund_due' => $refundDue,
            'refund_amount_after' => round($refunded + $refundDue, 2),
            'can_checkout' => $state['is_paid'] && $inspection['status'] === 'done',
            'inspection_status' => $inspection['status'],
            'inspection_rooms' => $inspection['rooms'],
            'checkout_day' => $checkoutDay,
            'is_early_checkout' => $checkoutDay > $today,
            'checkout_discount_amount' => round((float) ($booking->checkout_discount_amount ?? 0), 2),
            'extra_charges' => round((float) ($booking->extra_charges ?? 0), 2),
            'room_total' => $summary['room'],
            'folio_extras' => $summary['extras'],
        ]);
    }

    public function index(Request $request)
    {
        $this->allowReservationRead();

        // Legacy callers (room chart group lookups) expect a plain array.
        if (! $request->has('page')) {
            return Booking::with(['room.roomType', 'ratePlan', 'creator:id,name', 'bookingGroup'])
                ->when($request->booking_group_id, function ($q) use ($request) {
                    $q->where('booking_group_id', $request->booking_group_id);
                })
                ->orderBy('check_in')
                ->get();
        }

        $validated = $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
            'view' => 'nullable|in:current,past,all',
            'quick' => 'nullable|in:all,in_house,arrivals,departures,balance_due,attention',
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|in:all,pending,confirmed,checked_in,checked_out,cancelled,no_show',
            'source' => 'nullable|string|max:100',
            'room_type_id' => 'nullable|integer',
            'rate_plan_id' => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'sort' => 'nullable|in:check_in,check_out,guest,room,bill,balance,status,created_at,updated_at,id',
            'dir' => 'nullable|in:asc,desc',
        ]);

        $today = Carbon::today()->toDateString();
        $view = $validated['view'] ?? 'current';
        $quick = $validated['quick'] ?? 'all';

        $query = Booking::query()->with(['room.roomType', 'ratePlan', 'creator:id,name', 'bookingGroup']);
        if ($quick !== 'all') {
            $this->applyBookingListQuick($query, $quick, $today);
        } else {
            $this->applyBookingListView($query, $view, $today);
        }

        $status = $validated['status'] ?? 'all';
        if ($status === 'no_show') {
            $query->where('status', 'cancelled')->where('cancellation_reason', 'no_show');
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if (! empty($validated['source']) && $validated['source'] !== 'all') {
            $query->whereRaw('LOWER(booking_source) = ?', [strtolower($validated['source'])]);
        }
        if (! empty($validated['room_type_id'])) {
            $roomTypeId = (int) $validated['room_type_id'];
            $query->whereHas('room', fn ($q) => $q->where('room_type_id', $roomTypeId));
        }
        if (! empty($validated['rate_plan_id'])) {
            $query->where('rate_plan_id', (int) $validated['rate_plan_id']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('check_out', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('check_in', '<=', $validated['date_to']);
        }

        $terms = array_slice(preg_split('/\s+/', trim((string) ($validated['search'] ?? ''))) ?: [], 0, 5);
        foreach (array_filter($terms, fn ($t) => $t !== '') as $term) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $query->where(function ($q) use ($like, $term) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('source_reference', 'like', $like)
                    ->orWhere('booking_source', 'like', $like)
                    ->orWhereHas('room', fn ($r) => $r->where('room_number', 'like', $like))
                    ->orWhereHas('room.roomType', fn ($r) => $r->where('name', 'like', $like))
                    ->orWhereHas('ratePlan', fn ($r) => $r->where('name', 'like', $like));
                if (ctype_digit(ltrim($term, '#'))) {
                    $q->orWhere('bookings.id', (int) ltrim($term, '#'));
                } else {
                    $q->orWhere('booking_number', 'like', $like);
                }
            });
        }

        $sort = $validated['sort'] ?? 'check_in';
        $dir = $validated['dir'] ?? ($quick === 'all' && $view === 'past' ? 'desc' : 'asc');
        switch ($sort) {
            case 'guest':
                $query->orderBy('first_name', $dir)->orderBy('last_name', $dir);
                break;
            case 'room':
                $query->orderBy(Room::query()->selectRaw('LENGTH(room_number)')->whereColumn('rooms.id', 'bookings.room_id'), $dir)
                    ->orderBy(Room::query()->select('room_number')->whereColumn('rooms.id', 'bookings.room_id'), $dir);
                break;
            case 'bill':
                $query->orderByRaw(self::BOOKING_LIST_BILL_SQL.' '.$dir);
                break;
            case 'balance':
                $query->orderByRaw(self::BOOKING_LIST_BALANCE_SQL.' '.$dir);
                break;
            case 'status':
                $query->orderByRaw(
                    "CASE status WHEN 'checked_in' THEN 1 WHEN 'confirmed' THEN 2 WHEN 'pending' THEN 3 WHEN 'checked_out' THEN 4 ELSE 5 END {$dir}"
                );
                break;
            default:
                $query->orderBy($sort, $dir);
        }
        $query->orderBy('bookings.id', $dir);

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 15));
        $this->attachGroupFolios($paginator->getCollection());

        return response()->json(array_merge($paginator->toArray(), [
            'stats' => $this->bookingListStats($today),
            'filter_options' => [
                'sources' => Booking::query()
                    ->whereNotNull('booking_source')
                    ->where('booking_source', '!=', '')
                    ->distinct()
                    ->orderBy('booking_source')
                    ->pluck('booking_source')
                    ->values(),
                'room_types' => RoomType::query()->orderBy('name')->get(['id', 'name']),
                'rate_plans' => RatePlan::query()->orderBy('name')->get(['id', 'name']),
            ],
        ]));
    }

    /** Room total + folio − checkout discount (tax-exclusive estimate, same as the Bookings page). */
    private const BOOKING_LIST_BILL_SQL = '(COALESCE(bookings.total_price, 0) + COALESCE(bookings.extra_charges, 0) - COALESCE(bookings.checkout_discount_amount, 0))';

    /** Bill minus net received (payments − refunds). */
    private const BOOKING_LIST_BALANCE_SQL = '((COALESCE(bookings.total_price, 0) + COALESCE(bookings.extra_charges, 0) - COALESCE(bookings.checkout_discount_amount, 0)) - (COALESCE(bookings.deposit_amount, 0) - COALESCE(bookings.refund_amount, 0)))';

    /**
     * Group bill minus group net received. A payment recorded on one room covers the whole group.
     * Cancelled members contribute their fee only.
     */
    private const BOOKING_LIST_GROUP_NET_SQL = '(SELECT
        SUM(CASE WHEN g.status = \'cancelled\' THEN COALESCE(g.cancellation_fee_amount, 0)
            ELSE COALESCE(g.total_price, 0) + COALESCE(g.extra_charges, 0) - COALESCE(g.checkout_discount_amount, 0) END)
        - SUM(CASE WHEN COALESCE(g.deposit_amount, 0) - COALESCE(g.refund_amount, 0) > 0
            THEN COALESCE(g.deposit_amount, 0) - COALESCE(g.refund_amount, 0) ELSE 0 END)
        FROM bookings AS g
        WHERE g.booking_group_id = bookings.booking_group_id)';

    /**
     * Tell the Bookings list that cash recorded on one room settles the whole group.
     *
     * @param  \Illuminate\Support\Collection<int, Booking>  $bookings
     */
    private function attachGroupFolios($bookings): void
    {
        $groupIds = $bookings->pluck('booking_group_id')->filter()->unique()->values();
        if ($groupIds->isEmpty()) {
            return;
        }

        $members = Booking::query()
            ->whereIn('booking_group_id', $groupIds->all())
            ->orderBy('id')
            ->get([
                'id',
                'booking_group_id',
                'status',
                'total_price',
                'extra_charges',
                'checkout_discount_amount',
                'cancellation_fee_amount',
                'deposit_amount',
                'refund_amount',
            ]);

        $folios = [];
        foreach ($members->groupBy('booking_group_id') as $groupId => $rows) {
            $bill = 0.0;
            $received = 0.0;
            $holderId = null;
            $holderNet = -1.0;
            foreach ($rows as $row) {
                $bill += $this->listMemberBill($row);
                $net = $this->listMemberReceived($row);
                $received += $net;
                if ($holderId === null || $net > $holderNet) {
                    $holderNet = $net;
                    $holderId = (int) $row->id;
                }
            }
            $bill = round($bill, 2);
            $received = round($received, 2);
            $credit = round(max(0, $received - $bill), 2);
            $folios[(int) $groupId] = [
                'bill' => $bill,
                'received' => $received,
                'balance' => round(max(0, $bill - $received), 2),
                'credit' => $credit,
                'credit_booking_id' => $credit > 0.009 ? $holderId : null,
            ];
        }

        foreach ($bookings as $booking) {
            $groupId = (int) ($booking->booking_group_id ?? 0);
            if ($groupId > 0 && isset($folios[$groupId])) {
                $booking->setAttribute('group_folio', $folios[$groupId]);
            }
        }
    }

    private function listMemberBill(Booking $booking): float
    {
        if ($booking->status === 'cancelled') {
            return round(max(0, (float) ($booking->cancellation_fee_amount ?? 0)), 2);
        }

        $bill = (float) ($booking->total_price ?? 0)
            + (float) ($booking->extra_charges ?? 0)
            - (float) ($booking->checkout_discount_amount ?? 0);

        return round(max(0, $bill), 2);
    }

    private function listMemberReceived(Booking $booking): float
    {
        return round(max(0, (float) ($booking->deposit_amount ?? 0) - (float) ($booking->refund_amount ?? 0)), 2);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Booking>  $query */
    private function applyBookingListView($query, string $view, string $today): void
    {
        if ($view === 'past') {
            $query->where(function ($q) use ($today) {
                $q->whereIn('status', ['checked_out', 'cancelled'])
                    ->orWhere(function ($q2) use ($today) {
                        $q2->where('status', '!=', 'checked_in')->whereDate('check_out', '<', $today);
                    });
            });
        } elseif ($view === 'current') {
            $query->whereNotIn('status', ['checked_out', 'cancelled'])
                ->where(function ($q) use ($today) {
                    $q->where('status', 'checked_in')->orWhereDate('check_out', '>=', $today);
                });
        }
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Booking>  $query */
    private function applyBookingListQuick($query, string $quick, string $today): void
    {
        match ($quick) {
            'in_house' => $query->where('status', 'checked_in'),
            'arrivals' => $query->whereDate('check_in', $today)->where('status', '!=', 'cancelled'),
            'departures' => $query->whereDate('check_out', $today)->whereIn('status', ['checked_in', 'checked_out']),
            'balance_due' => $query->whereIn('status', ['checked_in', 'checked_out'])
                ->whereRaw(self::BOOKING_LIST_BALANCE_SQL.' > 0.01')
                ->where(function ($q) {
                    $q->whereNull('booking_group_id')
                        ->orWhereRaw(self::BOOKING_LIST_GROUP_NET_SQL.' > 0.01');
                }),
            'attention' => $query->where(function ($q) use ($today) {
                $q->where(fn ($a) => $a->where('status', 'checked_in')->whereDate('check_out', '<', $today))
                    ->orWhere(fn ($a) => $a->whereIn('status', ['confirmed', 'pending'])->whereDate('check_in', '<', $today))
                    ->orWhere(fn ($a) => $a->where('status', 'checked_in')->whereDate('check_in', '>', $today))
                    ->orWhere(function ($a) {
                        $a->where('status', 'checked_out')
                            ->whereRaw(self::BOOKING_LIST_BALANCE_SQL.' > 0.01')
                            ->where(function ($g) {
                                $g->whereNull('booking_group_id')
                                    ->orWhereRaw(self::BOOKING_LIST_GROUP_NET_SQL.' > 0.01');
                            });
                    });
            }),
            default => null,
        };
    }

    /** @return array<string, int|float> */
    private function bookingListStats(string $today): array
    {
        $count = function (callable $scope): int {
            $q = Booking::query();
            $scope($q);

            return (int) $q->count();
        };

        $balanceQ = Booking::query();
        $this->applyBookingListQuick($balanceQ, 'balance_due', $today);

        return [
            'in_house' => $count(fn ($q) => $this->applyBookingListQuick($q, 'in_house', $today)),
            'arrivals_expected' => $count(fn ($q) => $q->whereDate('check_in', $today)->whereIn('status', ['confirmed', 'pending'])),
            'arrivals_done' => $count(fn ($q) => $q->whereDate('check_in', $today)->whereIn('status', ['checked_in', 'checked_out'])),
            'departures_pending' => $count(fn ($q) => $q->whereDate('check_out', $today)->where('status', 'checked_in')),
            'departures_done' => $count(fn ($q) => $q->whereDate('check_out', $today)->where('status', 'checked_out')),
            'balance_due_count' => (int) (clone $balanceQ)->count(),
            'balance_due_amount' => round((float) (clone $balanceQ)->sum(DB::raw(self::BOOKING_LIST_BALANCE_SQL)), 2),
            'attention' => $count(fn ($q) => $this->applyBookingListQuick($q, 'attention', $today)),
            'current' => $count(fn ($q) => $this->applyBookingListView($q, 'current', $today)),
            'past' => $count(fn ($q) => $this->applyBookingListView($q, 'past', $today)),
            'all' => $count(fn ($q) => $q),
        ];
    }

    public function guestSearch(Request $request)
    {
        $this->allowReservationGuestSearch();

        $phone = trim((string) $request->query('phone', ''));
        if (
            preg_match('/^[0-9+\-\s()]{1,32}$/', $phone) !== 1
            || strlen((string) preg_replace('/\D/', '', $phone)) < 7
        ) {
            return response()->json(['message' => 'Provide at least 7 digits of the phone number to search.'], 422);
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $phone).'%';

        /** @var \App\Models\Booking|null $booking */
        $booking = Booking::query()->where('phone', 'like', $like)
            ->orderByDesc('created_at')
            ->first();

        if (! $booking instanceof Booking) {
            return response()->json(['message' => 'No guest found with this phone number.'], 404);
        }

        return response()->json([
            'first_name' => $booking->first_name,
            'last_name' => $booking->last_name,
            'email' => $booking->email,
            'phone' => $booking->phone,
            'city' => $booking->city,
            'country' => $booking->country,
            'bill_to_name' => $booking->bill_to_name,
            'guest_gstin' => $booking->guest_gstin,
        ]);
    }

    public function chart(Request $request)
    {
        $this->allowReservationChartRead();
        $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
        ]);
        $start = Carbon::parse($request->query('start', Carbon::today()))->startOfDay();
        // Show 14 days by default for better visibility
        $end = Carbon::parse($request->query('end', Carbon::today()->addDays(13)))->startOfDay();
        if ($end->lt($start)) {
            return response()->json(['message' => 'The end date must be on or after the start date.'], 422);
        }
        if ($start->diffInDays($end) >= self::CHART_MAX_DAYS) {
            return response()->json(['message' => 'The room chart shows at most '.self::CHART_MAX_DAYS.' days at a time.'], 422);
        }
        BookingSplitStayRoomMove::sync();
        HousekeepingTurnoverCarryForward::sync();
        $rangeStartAt = $start->copy()->startOfDay();
        // end is a date on the grid; include the whole end day by making end-exclusive = next day start
        $rangeEndAt = $end->copy()->addDay()->startOfDay();

        $rooms = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons', 'statusBlocks' => function ($q) use ($start, $rangeEndAt) {
            // Active HK workflow blocks + closed checkout-inspection records (inactive but with snapshot)
            // so Room Chart / drawer still show "inspected" and inspection details after apply/clear.
            $q->where('start_date', '<', $rangeEndAt->toDateString())
                ->where('end_date', '>', $start->toDateString())
                ->where(function ($w) {
                    $w->where('is_active', true)
                        ->orWhere(function ($w2) {
                            $w2->where('status', 'inspected')
                                ->whereNotNull('inspection_snapshot');
                        });
                });
        }, 'cleaningReleases' => function ($q) use ($start, $end) {
            $q->whereDate('release_date', '>=', $start->toDateString())
                ->whereDate('release_date', '<=', $end->toDateString())
                ->where(function ($w) {
                    $w->where(function ($active) {
                        $active->where('is_active', true)
                            ->where('status', '!=', RoomCleaningRelease::STATUS_CANCELLED);
                    })->orWhere(function ($done) {
                        $done->where('is_active', false)
                            ->where('status', RoomCleaningRelease::STATUS_READY);
                    });
                })
                ->orderByDesc('id')
                ->with(['assignedUser:id,name', 'startedByUser:id,name', 'completedByUser:id,name']);
        }, 'segments' => function ($q) use ($rangeStartAt, $rangeEndAt) {
            // Include checked_out segments so the chart can tell departure day (dirty) vs completed inspection.
            $q->where('check_in_at', '<', $rangeEndAt)
                ->where('check_out_at', '>', $rangeStartAt)
                ->whereNotIn('status', ['cancelled'])
                ->with(['booking', 'ratePlan']);
        }])->get();

        $this->enrichCheckoutInspectionInspectorNamesOnRooms($rooms);

        $user = Auth::user();
        if (! ($user?->can('reservation-view') || $user?->can('reservation'))) {
            foreach ($rooms as $room) {
                foreach ($room->segments as $segment) {
                    $segment->booking?->setVisible(self::CHART_BOOKING_FIELDS_WITHOUT_VIEW);
                }
            }
        }

        return response()->json([
            'rooms' => $rooms,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ]);
    }

    /** Booking fields the grid needs for users who may see the chart but not open a reservation. */
    private const CHART_BOOKING_FIELDS_WITHOUT_VIEW = [
        'id', 'booking_number', 'room_id', 'booking_group_id', 'status', 'booking_unit',
        'check_in', 'check_out', 'check_in_at', 'check_out_at',
        'early_checkin_time', 'late_checkout_time', 'estimated_arrival_time',
        'first_name', 'last_name', 'guest_name',
        'adults_count', 'children_count', 'infants_count', 'extra_beds_count',
    ];

    /**
     * Room chart snapshots may only store inspector_user_id; attach inspector_name for display.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Room>|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Room>  $rooms
     */
    private function enrichCheckoutInspectionInspectorNamesOnRooms($rooms): void
    {
        $userIds = [];
        foreach ($rooms as $room) {
            foreach ($room->statusBlocks ?? [] as $block) {
                $snap = $block->inspection_snapshot;
                if (! is_array($snap)) {
                    continue;
                }
                $uid = (int) ($snap['inspector_user_id'] ?? 0);
                if ($uid > 0 && trim((string) ($snap['inspector_name'] ?? '')) === '') {
                    $userIds[$uid] = true;
                }
            }
        }
        if ($userIds === []) {
            return;
        }

        foreach ($rooms as $room) {
            foreach ($room->statusBlocks ?? [] as $block) {
                $snap = $block->inspection_snapshot;
                if (! is_array($snap)) {
                    continue;
                }
                $enriched = CheckoutInspectionInspector::enrichSnapshot($snap);
                if ($enriched !== null) {
                    $block->setAttribute('inspection_snapshot', $enriched);
                }
            }
        }
    }

    private function roomIdGuestIsInNow(Booking $booking): int
    {
        $now = now();
        $segments = $booking->segments()->orderBy('check_in_at')->get();
        $current = $segments->first(fn($s) => Carbon::parse($s->check_in_at ?? $s->check_in)->lte($now)
            && Carbon::parse($s->check_out_at ?? $s->check_out)->gt($now));

        return (int) (($current ?? $segments->first())?->room_id ?? $booking->room_id);
    }

    public function summary(Request $request)
    {
        $this->allowReservationChartRead();
        HousekeepingTurnoverCarryForward::sync();
        $date = Carbon::parse($request->query('date', Carbon::today()));
        $today = Carbon::today();
        $dayStartAt = $date->copy()->startOfDay();
        $dayEndAt = $date->copy()->addDay()->startOfDay();

        $rooms = Room::with(['statusBlocks' => function ($q) use ($date) {
            $d = $date->toDateString();
            $q->where('is_active', true)
                // day is active if start_date <= d < end_date  (end_date is exclusive)
                ->where('start_date', '<=', $d)
                ->where('end_date', '>', $d);
        }, 'segments' => function ($q) use ($dayStartAt, $dayEndAt) {
            $q->where('status', '!=', 'cancelled')
                ->where('check_in_at', '<', $dayEndAt)
                ->where('check_out_at', '>', $dayStartAt);
        }, 'segments.booking'])->get();

        $counts = [
            'total' => $rooms->count(),
            'occupied' => 0,
            'reserved' => 0,
            'maintenance' => 0,
            'on_hold' => 0,
            'dirty' => 0,
            'cleaning' => 0,
            'available' => 0,
            'checkins_today' => Booking::whereDate('check_in', '=', $today, 'and')->whereIn('status', ['confirmed', 'checked_in'])->count(),
            'checkouts_today' => Booking::whereDate('check_out', '=', $today, 'and')->whereIn('status', ['checked_in', 'checked_out'])->count(),
        ];

        foreach ($rooms as $room) {
            if ($room->segments->isNotEmpty()) {
                // If any active segment's booking is checked_in, treat as occupied; else reserved
                $isCheckedIn = $room->segments->contains(function ($seg) {
                    $bStatus = $seg->booking?->status;

                    return $bStatus === 'checked_in' || $seg->status === 'checked_in';
                });

                if ($isCheckedIn) {
                    $counts['occupied']++;
                } else {
                    $counts['reserved']++;
                }
            } elseif ($room->statusBlocks->isNotEmpty()) {
                // if there are multiple blocks (shouldn't), take first
                $st = $room->statusBlocks->first()->status;
                if ($st === 'maintenance') {
                    $counts['maintenance']++;
                } elseif ($st === 'on_hold') {
                    $counts['on_hold']++;
                } elseif ($st === 'dirty') {
                    $counts['dirty']++;
                } elseif ($st === 'cleaning') {
                    $counts['cleaning']++;
                } else {
                    $counts['available']++;
                }
            } else {
                $counts['available']++;
            }
        }

        return response()->json($counts);
    }

    /**
     * Hotel calendar checkout day for inspection rules (day stays use check_out, not UTC slice of check_out_at).
     */
    private function bookingCheckoutCalendarDay(Booking $booking, ?BookingSegment $segment = null): string
    {
        if ($segment) {
            if ($segment->check_out_at) {
                return Carbon::parse($segment->check_out_at)->toDateString();
            }

            return Carbon::parse($segment->check_out ?? $booking->check_out)->toDateString();
        }

        $unit = (string) ($booking->booking_unit ?? 'day');
        if ($unit !== 'hour_package') {
            return Carbon::parse($booking->check_out)->toDateString();
        }

        if ($booking->check_out_at) {
            return Carbon::parse($booking->check_out_at)->toDateString();
        }

        return Carbon::parse($booking->check_out)->toDateString();
    }

    /**
     * Reception: request a pre-checkout inspection (moves room to pending_inspection).
     * Creates one scoped block per active booking segment (supports room transfers / split stays).
     */
    public function requestInspection(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if ($booking->status !== 'checked_in') {
            return response()->json([
                'message' => 'Inspection can only be requested for a checked-in booking.',
            ], 422);
        }

        BookingSplitStayRoomMove::sync((int) $booking->id);
        $booking->refresh()->load(['segments']);

        $segments = $booking->segments
            ->filter(fn(BookingSegment $s) => ! in_array($s->status, ['cancelled', 'checked_out'], true));

        if ($segments->isEmpty()) {
            if ((int) $booking->room_id <= 0) {
                return response()->json(['message' => 'Booking has no room assigned.'], 422);
            }
            $segments = collect([
                new BookingSegment([
                    'booking_id' => $booking->id,
                    'room_id' => $booking->room_id,
                    'check_in' => $booking->check_in,
                    'check_out' => $booking->check_out,
                    'check_in_at' => $booking->check_in_at,
                    'check_out_at' => $booking->check_out_at,
                    'status' => 'checked_in',
                ]),
            ]);
        }

        $today = now()->toDateString();
        $allowBeforeCheckoutDay = config('booking.allow_early_checkout_inspection') && ! app()->isProduction();
        foreach ($segments as $segment) {
            $checkoutDay = $this->bookingCheckoutCalendarDay($booking, $segment);
            if ($checkoutDay !== $today && ! $allowBeforeCheckoutDay) {
                return response()->json([
                    'message' => 'Checkout inspection can only be requested on the guest\'s checkout date.',
                    'checkout_date' => $checkoutDay,
                ], 422);
            }
        }

        $createdBlocks = [];
        $affectedRoomIds = [];

        try {
            DB::transaction(function () use ($booking, $segments, &$createdBlocks, &$affectedRoomIds) {
                $this->lockBookingForChange($booking);
                $this->writeInspectionRequestBlocks($booking, $segments, $createdBlocks, $affectedRoomIds);
                if ($createdBlocks === []) {
                    throw ValidationException::withMessages(['booking' => 'No active room segments to inspect.']);
                }

                foreach (array_keys($affectedRoomIds) as $rid) {
                    Room::where('id', '=', $rid, 'and')->update(['status' => 'pending_inspection']);
                }

                $userName = Auth::user() ? (string) Auth::user()->name : '';
                $roomNumbers = Room::whereIn('id', array_keys($affectedRoomIds))->orderBy('room_number')->pluck('room_number')->implode(', ');
                $line = sprintf(
                    '[Checkout inspection requested%s%s on %s]',
                    $roomNumbers !== '' ? " for room {$roomNumbers}" : '',
                    $userName !== '' ? " by {$userName}" : '',
                    now()->format('Y-m-d H:i:s'),
                );
                $existing = trim((string) ($booking->notes ?? ''));
                $booking->update(['notes' => $existing !== '' ? $existing."\n".$line : $line]);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: 'Could not request inspection.',
            ], 422);
        }

        HousekeepingStateUpdated::dispatchIfEnabled(array_keys($affectedRoomIds), 'request_inspection');

        $guestName = trim((string) ($booking->guest_name ?? ''));
        $actorId = Auth::id() ? (int) Auth::id() : null;
        foreach (array_keys($affectedRoomIds) as $rid) {
            PortalNotifications::recordCheckoutInspectionRequested(
                (int) $rid,
                (int) $booking->id,
                $guestName !== '' ? $guestName : null,
                $actorId,
            );
        }

        return response()->json([
            'message' => 'Inspection requested.',
            'blocks' => collect($createdBlocks)->map(fn($b) => $b->fresh()->load('room.roomType'))->values(),
            'block' => $createdBlocks[0]->fresh()->load('room.roomType'),
        ]);
    }

    /**
     * Replace this booking's pending-inspection handoff with one `pending_inspection` block per open room segment.
     *
     * @param  \Illuminate\Support\Collection<int, BookingSegment>  $segments
     * @param  list<RoomStatusBlock>  $createdBlocks
     * @param  array<int, true>  $affectedRoomIds
     */
    private function writeInspectionRequestBlocks(Booking $booking, $segments, array &$createdBlocks, array &$affectedRoomIds): void
    {
        RoomStatusBlock::where('is_active', '=', true, 'and')
            ->where('status', '=', 'pending_inspection', 'and')
            ->where('inspection_snapshot->booking_id', '=', $booking->id)
            ->update(['is_active' => false]);

        foreach ($segments as $segment) {
            $roomId = (int) $segment->room_id;
            if ($roomId <= 0) {
                continue;
            }

            $checkInAt = $segment->check_in_at
                ? Carbon::parse($segment->check_in_at)
                : Carbon::parse($segment->check_in)->startOfDay();
            $checkOutAt = $segment->check_out_at
                ? Carbon::parse($segment->check_out_at)
                : Carbon::parse($segment->check_out)->startOfDay();

            $startDate = $checkInAt->copy()->startOfDay()->toDateString();
            $endExclusive = $this->dateEndExclusiveFromDateTime($checkOutAt);

            RoomStatusBlock::where('room_id', '=', $roomId, 'and')
                ->where('is_active', '=', true, 'and')
                ->whereIn('status', ['dirty', 'cleaning', 'inspected', 'pending_inspection'], 'and', false)
                ->where('start_date', '<', $endExclusive)
                ->where('end_date', '>', $startDate)
                ->update(['is_active' => false]);

            $createdBlocks[] = RoomStatusBlock::create([
                'room_id' => $roomId,
                'status' => 'pending_inspection',
                'start_date' => $startDate,
                'end_date' => $endExclusive,
                'note' => 'Reception: requested checkout inspection',
                'is_active' => true,
                'created_by' => Auth::id(),
                'inspection_snapshot' => [
                    'booking_id' => $booking->id,
                    'room_id' => $roomId,
                    'segment_id' => $segment->id ?? null,
                ],
            ]);

            $affectedRoomIds[$roomId] = true;
        }
    }

    /**
     * Per-room day total: rent, extra beds, meal plan, and early arrival, then GST.
     * Returns null when the room has no day rate plan so the client total is kept.
     *
     * @param  array<string, mixed>  $bookingData
     */
    private function roomDayTotal(Room $room, array $bookingData, Carbon $checkInAt, Carbon $checkOutAt): ?float
    {
        $planId = (int) ($bookingData['rate_plan_id'] ?? 0);
        $plan = $room->roomType?->ratePlans?->firstWhere('id', $planId);
        if (! $plan || ($plan->billing_unit ?? 'day') === 'hour_package') {
            return null;
        }

        $start = $checkInAt->copy()->startOfDay();
        $end = $checkOutAt->copy()->startOfDay();
        $nights = max(1, (int) $start->diffInDays($end));
        $extraPerNight = $room->roomType
            ? SeasonalRoomPricing::extraBedPreTax(
                $room->roomType,
                (int) ($bookingData['adults_count'] ?? 1),
                (int) ($bookingData['children_count'] ?? 0),
                is_array($bookingData['child_ages'] ?? null) ? $bookingData['child_ages'] : null,
                (int) ($bookingData['extra_beds_count'] ?? 0),
            )
            : 0.0;
        $beforeTax = SeasonalRoomPricing::sumDayRoomRentWithSeasons(
            (float) ($plan->base_price ?? 0),
            $extraPerNight,
            $extraPerNight > 0 ? 1 : 0,
            $start,
            $end,
            $room->roomType?->seasons ?? []
        );
        $beforeTax += $this->mealPlanCharges(
            $plan,
            $room,
            (int) ($bookingData['adults_count'] ?? 1),
            (int) ($bookingData['children_count'] ?? 0),
            $nights
        );
        $beforeTax += $this->earlyCheckInFeeForCreate($room, (string) ($bookingData['estimated_arrival_time'] ?? ''), (float) ($plan->base_price ?? 0));

        $taxRate = (float) ($room->roomType?->tax?->rate ?? 0);
        $roomRatesIncludeGst = filter_var(Setting::get('room_rates_include_gst', '0'), FILTER_VALIDATE_BOOLEAN);

        return $roomRatesIncludeGst
            ? round($beforeTax, 2)
            : round($beforeTax * (1 + ($taxRate / 100)), 2);
    }

    /**
     * New room total after a guest-mix or rate-plan edit: the stored total moved by the price difference
     * between the old and new mix, so negotiated rates, extensions and transfers already in the total stay.
     * Null when nothing price-relevant changed or the stay cannot be priced.
     *
     * @param  array<string, mixed>  $validated
     */
    private function repricedTotalForGuestChange(Booking $booking, array $validated): ?float
    {
        $keys = ['adults_count', 'children_count', 'child_ages', 'extra_beds_count', 'rate_plan_id'];
        if (array_intersect($keys, array_keys($validated)) === []) {
            return null;
        }

        $booking->loadMissing(['room.roomType.ratePlans', 'room.roomType.tax', 'room.roomType.seasons']);
        $room = $booking->room;
        if (! $room) {
            return null;
        }

        $old = [
            'adults_count' => (int) ($booking->adults_count ?? 1),
            'children_count' => (int) ($booking->children_count ?? 0),
            'child_ages' => is_array($booking->child_ages) ? $booking->child_ages : null,
            'extra_beds_count' => (int) ($booking->extra_beds_count ?? 0),
            'rate_plan_id' => $booking->rate_plan_id,
            'estimated_arrival_time' => (string) ($booking->estimated_arrival_time ?? ''),
        ];
        $new = array_merge($old, array_intersect_key($validated, array_flip($keys)));
        if ((int) $new['children_count'] === 0) {
            $new['child_ages'] = [];
        }

        $checkInAt = Carbon::parse($booking->check_in_at ?? $booking->check_in);
        $checkOutAt = Carbon::parse($booking->check_out_at ?? $booking->check_out);
        $price = function (array $mix) use ($booking, $room, $checkInAt, $checkOutAt): ?float {
            if (($booking->booking_unit ?? 'day') === 'hour_package') {
                $calc = $this->computeHourlyPackageTotal(
                    $room,
                    (int) ($mix['rate_plan_id'] ?? 0),
                    $checkInAt->copy(),
                    $checkOutAt->copy(),
                    (int) $mix['extra_beds_count'],
                    (int) $mix['adults_count'],
                    (int) $mix['children_count'],
                    $mix['child_ages'],
                );

                return $calc['ok'] ? (float) $calc['total'] : null;
            }

            return $this->roomDayTotal($room, $mix, $checkInAt->copy(), $checkOutAt->copy());
        };

        $newPrice = $price($new);
        if ($newPrice === null) {
            return null;
        }
        $oldPrice = $price($old);
        if ($oldPrice === null) {
            return $newPrice;
        }

        return round(max(0.0, (float) ($booking->total_price ?? 0) + $newPrice - $oldPrice), 2);
    }

    private function mealPlanCharges(RatePlan $plan, Room $room, int $adults, int $children, int $nights): float
    {
        $rt = $room->roomType;
        if (! $rt) {
            return 0.0;
        }

        return BookingInvoiceRoomStay::nightlyPlanMealsPreTax($rt, $plan, $adults, $children) * $nights;
    }

    /**
     * Early-arrival fee included in the room total at create (same basis as the room chart).
     */
    private function earlyCheckInFeeForCreate(Room $room, string $arrival, float $nightlyPreTax): float
    {
        $arrival = trim($arrival);
        if ($arrival === '') {
            return 0.0;
        }
        try {
            $actual = Carbon::parse($arrival)->format('H:i');
        } catch (\Throwable) {
            return 0.0;
        }
        $standard = (string) Setting::get('standard_check_in_time', '14:00');
        try {
            $standard = Carbon::parse($standard)->format('H:i');
        } catch (\Throwable) {
            $standard = '14:00';
        }
        $rt = $room->roomType;
        if (! $rt || $actual >= $standard) {
            return 0.0;
        }
        $fee = (float) ($rt->early_check_in_fee ?? 0);
        if ($fee <= 0) {
            return 0.0;
        }
        $gap = (int) Carbon::createFromFormat('H:i', $actual)->diffInMinutes(Carbon::createFromFormat('H:i', $standard));
        $billable = max(0, $gap - (int) ($rt->early_check_in_buffer_minutes ?? 0));

        return BookingInvoiceRoomStay::timeFeeAmount($rt->early_check_in_type, $fee, $billable, $nightlyPreTax);
    }

    public function store(Request $request)
    {
        $roomIdsRaw = $request->input('room_ids');
        $roomIdsGate = is_array($roomIdsRaw) && count($roomIdsRaw) > 0
            ? array_values(array_filter(array_map('intval', $roomIdsRaw), static fn(int $id): bool => $id > 0))
            : ($request->filled('room_id') ? [(int) $request->input('room_id')] : []);
        $isGroupBooking = count($roomIdsGate) > 1 || $request->filled('group_name');
        if ($isGroupBooking) {
            $this->allowReservationCreateGroup();
        } else {
            $this->allowReservationCreateSingle();
        }

        if ($request->has('guest_gstin')) {
            $gstin = strtoupper(trim((string) $request->input('guest_gstin')));
            $request->merge(['guest_gstin' => $gstin !== '' ? $gstin : null]);
        }
        if ($request->has('bill_to_name')) {
            $billTo = trim((string) $request->input('bill_to_name'));
            $request->merge(['bill_to_name' => $billTo !== '' ? $billTo : null]);
        }

        $validated = $request->validate([
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'exists:rooms,id',
            'room_id' => 'required_without:room_ids|exists:rooms,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:32',
            'guest_identity_types' => 'nullable|array|max:20',
            'guest_identity_types.*' => 'nullable|string|max:255',
            'guest_identities' => 'nullable|array|max:20',
            'guest_identities.*' => 'nullable|string|max:'.$this->guestIdentityMaxChars(), // Base64 data URLs
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'bill_to_name' => 'nullable|string|max:255',
            'guest_gstin' => 'nullable|string|max:15|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            'adults_count' => 'required|integer|min:1',
            'children_count' => 'nullable|integer|min:0',
            'child_ages' => 'nullable|array',
            'child_ages.*' => 'nullable|integer|min:1|max:17',
            'infants_count' => 'nullable|integer|min:0',
            'extra_beds_count' => 'nullable|integer|min:0',
            'booking_unit' => 'nullable|in:day,hour_package',
            'check_in' => 'required|date',
            // For hour_package, check_out can be omitted (computed from package).
            'check_out' => 'nullable|date|after:check_in',
            'estimated_arrival_time' => 'nullable|string',
            // Kept only when the room has no type to price from; otherwise computed server-side.
            'total_price' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'deposit_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:pending,confirmed,checked_in',
            'notes' => 'nullable|string',
            'group_name' => 'nullable|string|max:255', // For group master
            'adult_breakfast_count' => 'nullable|integer|min:0',
            'child_breakfast_count' => 'nullable|integer|min:0',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
            'room_occupancy' => 'nullable|array',
            'room_occupancy.*' => 'array',
            'room_occupancy.*.adults' => 'nullable|integer|min:1',
            'room_occupancy.*.children' => 'nullable|integer|min:0',
            'room_occupancy.*.infants' => 'nullable|integer|min:0',
            'room_occupancy.*.extra_beds' => 'nullable|integer|min:0',
            'room_occupancy.*.adult_breakfast' => 'nullable|integer|min:0',
            'room_occupancy.*.child_breakfast' => 'nullable|integer|min:0',
            'room_occupancy.*.child_ages' => 'nullable|array',
            'room_occupancy.*.child_ages.*' => 'nullable|integer|min:1|max:17',
            'room_occupancy.*.rate_plan_id' => 'nullable|exists:rate_plans,id',
        ]);
        unset($validated['room_occupancy']);

        $creatorId = Auth::id();
        $roomIds = $roomIdsGate;
        if ($roomIds === []) {
            return response()->json(['message' => 'Select at least one room.'], 422);
        }
        if (count($roomIds) !== count(array_unique($roomIds))) {
            return response()->json(['message' => 'Each room can only be selected once in a group.'], 422);
        }
        $bookingUnit = $validated['booking_unit'] ?? 'day';
        // Normalize into APP_TIMEZONE wall time before persisting. Clients may send either a
        // hotel-local naive datetime (preferred) or a UTC ISO instant; without this, UTC
        // instants are stored as UTC wall-clock values and display shifts by the offset
        // (e.g. 4:00 PM IST → 10:30Z → stored 10:30 → shown as 10:30 AM).
        $checkInAt = $this->parseHotelDateTime($validated['check_in']);
        $checkOutAt = isset($validated['check_out']) ? $this->parseHotelDateTime($validated['check_out']) : null;
        $status = $validated['status'] ?? 'confirmed';

        // New reservations only from today onward (hotel calendar day in app timezone).
        if ($checkInAt->copy()->startOfDay()->lt(Carbon::today())) {
            throw ValidationException::withMessages([
                'check_in' => 'Reservations cannot be created for past dates.',
            ]);
        }

        if ($status === 'checked_in') {
            $checkInDay = $checkInAt->toDateString();
            if (! $this->checkInAllowedOn($checkInDay)) {
                return response()->json([
                    'message' => 'Check-in is only allowed on the guest\'s scheduled arrival date (today).',
                ], 422);
            }
        }

        if ($bookingUnit === 'day') {
            if (! $checkOutAt) {
                return response()->json(['message' => 'check_out is required for day bookings.'], 422);
            }
            // normalize to midnight so old semantics stay consistent
            $checkInAt = $checkInAt->copy()->startOfDay();
            $checkOutAt = $checkOutAt->copy()->startOfDay();
            $doorloomRefusal = DoorloomStaySync::refusalMessage($roomIds, $checkInAt, $checkOutAt);
            if ($doorloomRefusal) {
                return response()->json(['message' => $doorloomRefusal], 422);
            }
        }

        // Breakfast count validation
        $totalAdults = (int) ($validated['adults_count'] ?? 1);
        $totalChildren = (int) ($validated['children_count'] ?? 0);
        $adultB = (int) ($validated['adult_breakfast_count'] ?? 0);
        $childB = (int) ($validated['child_breakfast_count'] ?? 0);

        if ($adultB > $totalAdults || $childB > $totalChildren) {
            return response()->json([
                'message' => 'Breakfast counts cannot exceed guest counts.',
                'errors' => [
                    'adult_breakfast_count' => $adultB > $totalAdults ? ['Must be <= adults count'] : [],
                    'child_breakfast_count' => $childB > $totalChildren ? ['Must be <= children count'] : [],
                ],
            ], 422);
        }

        // Availability: segment overlap + hard status blocks (maintenance / hold).
        // Dirty/cleaning only block when creating as checked_in. Re-checked under row locks before insert.
        $availabilityEnd = $checkOutAt ?: $checkInAt->copy()->addHours(12);
        foreach ($roomIds as $roomId) {
            try {
                BookingRoomAvailability::assertSellable((int) $roomId, $checkInAt, $availabilityEnd, $status);
            } catch (ValidationException $e) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first() ?: 'Room is not available for the selected dates.',
                ], 422);
            }
        }

        $isGroup = count($roomIds) > 1 || $request->filled('group_name');

        $storedIdentityPaths = [];
        DB::beginTransaction();
        try {
        $bookingGroupId = null;
        if ($isGroup) {
            $group = BookingGroup::create([
                'name' => $request->input('group_name') ?: ('Group - ' . $validated['first_name'] . ' ' . $validated['last_name']),
                'contact_person' => $validated['first_name'] . ' ' . $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'status' => 'confirmed',
                'notes' => $validated['notes'] ?? null,
            ]);
            $bookingGroupId = $group->id;
        }

        // Handle Identity Images (new uploads only; another booking's stored file cannot be attached)
        $imagePaths = [];
        $guestIdentityUploadMeta = [];
        if ($request->has('guest_identities')) {
            $images = $request->input('guest_identities') ?: [];
            $identityService = app(GuestIdentityImageService::class);
            foreach ($images as $index => $imageData) {
                if (! $imageData) {
                    continue;
                }

                try {
                    if (str_starts_with((string) $imageData, 'data:')) {
                        $stored = $identityService->storeDataUrl((string) $imageData, (int) $index);
                    } elseif ($request->hasFile("guest_identities.{$index}")) {
                        $stored = $identityService->storeUploadedFile($request->file("guest_identities.{$index}"), (int) $index);
                    } else {
                        throw new \InvalidArgumentException('Upload a new photo of the guest ID; stored documents from other bookings cannot be reused.');
                    }
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['guest_identities' => $e->getMessage()]);
                }
                $imagePaths[] = $stored['path'];
                $storedIdentityPaths[] = $stored['path'];
                $guestIdentityUploadMeta[] = array_merge(['index' => (int) $index], $stored);
            }
        }

        $bookings = [];
        $roomOccupancy = $request->input('room_occupancy', []);

        foreach ($roomIds as $index => $roomId) {
            $bookingData = $validated;
            unset($bookingData['room_ids']);
            unset($bookingData['group_name']);

            $bookingData['room_id'] = $roomId; // Retain for legacy
            $bookingData['created_by'] = $creatorId;
            $bookingData['booking_group_id'] = $bookingGroupId;
            $bookingData['guest_identities'] = $imagePaths;
            $bookingData['adult_breakfast_count'] = $validated['adult_breakfast_count'] ?? 0;
            $bookingData['child_breakfast_count'] = $validated['child_breakfast_count'] ?? 0;
            $bookingData['rate_plan_id'] = $validated['rate_plan_id'] ?? null;
            $bookingData['booking_unit'] = $bookingUnit;
            $bookingData['adults_count'] = (int) ($bookingData['adults_count'] ?? 1);
            $bookingData['children_count'] = (int) ($bookingData['children_count'] ?? 0);
            $bookingData['infants_count'] = (int) ($bookingData['infants_count'] ?? 0);
            $bookingData['extra_beds_count'] = (int) ($bookingData['extra_beds_count'] ?? 0);

            // Audit Fix: Only apply group deposit/discount to the FIRST booking in the loop
            if ($isGroup && $index > 0) {
                $bookingData['deposit_amount'] = 0;
            }

            // Apply individual room occupancy if provided
            if (isset($roomOccupancy[$roomId])) {
                $occ = $roomOccupancy[$roomId];
                $bookingData['adults_count'] = $occ['adults'] ?? $bookingData['adults_count'];
                $bookingData['children_count'] = $occ['children'] ?? ($bookingData['children_count'] ?? 0);
                $bookingData['infants_count'] = $occ['infants'] ?? ($bookingData['infants_count'] ?? 0);
                $bookingData['extra_beds_count'] = $occ['extra_beds'] ?? ($bookingData['extra_beds_count'] ?? 0);
                $bookingData['adult_breakfast_count'] = $occ['adult_breakfast'] ?? $bookingData['adult_breakfast_count'];
                $bookingData['child_breakfast_count'] = $occ['child_breakfast'] ?? $bookingData['child_breakfast_count'];
                if (! empty($occ['child_ages']) && is_array($occ['child_ages'])) {
                    $bookingData['child_ages'] = array_values(array_map('intval', $occ['child_ages']));
                }
                if (! empty($occ['rate_plan_id'])) {
                    $bookingData['rate_plan_id'] = $occ['rate_plan_id'];
                }
            }

            $room = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons'])->findOrFail($roomId);

            if ((int) $bookingData['adult_breakfast_count'] > (int) $bookingData['adults_count']
                || (int) $bookingData['child_breakfast_count'] > (int) $bookingData['children_count']) {
                throw ValidationException::withMessages([
                    'room_occupancy' => "Breakfast counts for room #{$room->room_number} cannot exceed its guest counts.",
                ]);
            }
            $roomPlan = ! empty($bookingData['rate_plan_id'])
                ? $room->roomType?->ratePlans?->firstWhere('id', (int) $bookingData['rate_plan_id'])
                : null;
            if (! empty($bookingData['rate_plan_id']) && ! $roomPlan) {
                throw ValidationException::withMessages([
                    'rate_plan_id' => "Select a rate plan of room #{$room->room_number}'s room type.",
                ]);
            }
            if ($bookingUnit === 'day' && (! $roomPlan || ($roomPlan->billing_unit ?? 'day') === 'hour_package')) {
                throw ValidationException::withMessages([
                    'rate_plan_id' => "Select a nightly rate plan for room #{$room->room_number}.",
                ]);
            }

            BookingRoomAvailability::assertCapacity(
                $room,
                (int) ($bookingData['adults_count'] ?? 1),
                (int) ($bookingData['children_count'] ?? 0),
                (int) ($bookingData['extra_beds_count'] ?? 0),
                is_array($bookingData['child_ages'] ?? null) ? $bookingData['child_ages'] : null,
            );

            // Compute/check datetime and totals for hourly packages
            $finalCheckInAt = $checkInAt->copy();
            $finalCheckOutAt = $checkOutAt ? $checkOutAt->copy() : null;
            $totalPrice = isset($bookingData['total_price']) ? (float) $bookingData['total_price'] : 0.0;

            if ($bookingUnit === 'hour_package') {
                $planId = (int) ($bookingData['rate_plan_id'] ?? 0);
                if ($planId <= 0) {
                    throw ValidationException::withMessages([
                        'rate_plan_id' => 'rate_plan_id is required for hourly package bookings.',
                    ]);
                }

                // If checkout not provided, compute it as package end
                if (! $finalCheckOutAt) {
                    // Package end depends on package_hours
                    $plan = $room->roomType?->ratePlans?->firstWhere('id', $planId);
                    $pkgHours = (int) ($plan?->package_hours ?? 0);
                    if (($plan?->billing_unit ?? 'day') !== 'hour_package' || $pkgHours <= 0) {
                        throw ValidationException::withMessages([
                            'rate_plan_id' => 'Invalid hourly package plan for this room.',
                        ]);
                    }
                    $finalCheckOutAt = $finalCheckInAt->copy()->addHours($pkgHours);
                }

                $extraBedsForRoom = (int) ($bookingData['extra_beds_count'] ?? 0);
                $calc = $this->computeHourlyPackageTotal(
                    $room,
                    $planId,
                    $finalCheckInAt,
                    $finalCheckOutAt,
                    $extraBedsForRoom,
                    (int) ($bookingData['adults_count'] ?? 1),
                    (int) ($bookingData['children_count'] ?? 0),
                    is_array($bookingData['child_ages'] ?? null) ? $bookingData['child_ages'] : null,
                );
                if (! $calc['ok']) {
                    throw ValidationException::withMessages(['booking' => $calc['message']]);
                }
                $totalPrice = (float) $calc['total'];
            } else {
                // Day total is computed per room server-side (single and group); the client figure is not trusted.
                if ($finalCheckOutAt) {
                    $priced = $this->roomDayTotal($room, $bookingData, $finalCheckInAt, $finalCheckOutAt);
                    if ($priced !== null) {
                        $totalPrice = $priced;
                    }
                }
            }

            // Sync legacy date columns for compatibility
            $bookingData['check_in_at'] = $finalCheckInAt;
            $bookingData['check_out_at'] = $finalCheckOutAt;
            $bookingData['check_in'] = $finalCheckInAt->toDateString();
            $bookingData['check_out'] = $finalCheckOutAt->toDateString();
            $bookingData['total_price'] = $totalPrice;
            $bookingData['payment_status'] = $this->paymentStatusFor((float) ($bookingData['deposit_amount'] ?? 0), $totalPrice);

            // Activity log (same bracket format as PATCH audits): who created the reservation and when.
            $creator = Auth::user();
            $creatorName = $creator ? (string) $creator->name : '';
            $createdAt = now()->format('Y-m-d H:i:s');
            $byCreated = $creatorName !== '' ? " by {$creatorName}" : '';
            $roomNum = (string) ($room->room_number ?? $roomId);
            $roomTypeLabel = (string) ($room->roomType?->name ?? 'Room');
            $ciStr = $finalCheckInAt->toDateString();
            $coStr = $finalCheckOutAt ? $finalCheckOutAt->toDateString() : $ciStr;
            $creationAudit = "[Reservation created: Room #{$roomNum} · {$roomTypeLabel} · {$ciStr} → {$coStr}{$byCreated} on {$createdAt}]";
            $notesIncoming = trim((string) ($bookingData['notes'] ?? ''));
            $bookingData['notes'] = $notesIncoming !== '' ? $creationAudit . "\n" . $notesIncoming : $creationAudit;

            $this->assignEarlyCheckinTimeFromEstimatedArrival($bookingData, $bookingUnit);

            // Concurrent booking safety: lock the room row and re-check overlap before insert.
                $booking = BookingRoomAvailability::withRoomLocks([(int) $roomId], function () use (
                    $roomId,
                    $bookingData,
                    $finalCheckInAt,
                    $finalCheckOutAt,
                    $status,
                ) {
                    $end = $finalCheckOutAt ?: $finalCheckInAt->copy()->addHours(12);
                    BookingRoomAvailability::assertSellable((int) $roomId, $finalCheckInAt, $end, $status);

                    $booking = Booking::create($bookingData);
                    BookingNumber::assign($booking);

                    BookingSegment::create([
                        'booking_id' => $booking->id,
                        'room_id' => $roomId,
                        'check_in' => $booking->check_in,
                        'check_out' => $booking->check_out,
                        'check_in_at' => $booking->check_in_at,
                        'check_out_at' => $booking->check_out_at,
                        'rate_plan_id' => $bookingData['rate_plan_id'],
                        'adults_count' => $bookingData['adults_count'],
                        'children_count' => $bookingData['children_count'],
                        'extra_beds_count' => $bookingData['extra_beds_count'],
                        'total_price' => $bookingData['total_price'],
                        'status' => $booking->status === 'checked_in' ? 'checked_in' : 'confirmed',
                    ]);

                    if (($status ?? '') === 'checked_in') {
                        Room::findOrFail($roomId)->update(['status' => 'occupied']);
                    }

                    return $booking;
                });

            $bookings[] = $booking->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup', 'segments']);

            // Seed payment ledger from initial deposit (group: only first room keeps deposit).
            if (BookingPaymentLedger::enabled()) {
                $seedDeposit = round((float) ($booking->deposit_amount ?? 0), 2);
                if ($seedDeposit > 0.004) {
                    // Clear scalar first so sync matches a single ledger row (create already wrote deposit_amount).
                    $booking->forceFill(['deposit_amount' => 0])->save();
                    BookingPaymentLedger::recordPayment($booking, [
                        'amount' => $seedDeposit,
                        'method' => (string) ($booking->payment_method ?: 'cash'),
                        'source' => 'booking_create',
                        'notes' => 'Initial deposit at booking',
                        'bill_total' => (float) ($booking->total_price ?? 0),
                        'received_by' => Auth::id(),
                    ]);
                    $booking->refresh();
                }
            }
        }

        DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->deleteGuestIdentityFiles($storedIdentityPaths);

            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: 'Could not create the reservation.',
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteGuestIdentityFiles($storedIdentityPaths);
            throw $e;
        }

        $doorloom = HotelApiSync::syncBookings($bookings);

        if ($isGroup) {
            return response()->json($bookings, 201);
        }

        $payload = $this->bookingJsonWithGuestIdentityMeta($bookings[0], $guestIdentityUploadMeta ?? []);
        $payload['doorloom'] = $doorloom;

        return response()->json($payload, 201);
    }

    /**
     * Parse a client datetime into the hotel (app) timezone for storage.
     * Naive strings are interpreted in APP_TIMEZONE; zoned/UTC values are converted.
     */
    private function parseHotelDateTime(string $value): Carbon
    {
        return Carbon::parse($value)->timezone(config('app.timezone'));
    }

    /**
     * Hotel calendar arrival day for check-in rules (day stays use check_in, not UTC slice of check_in_at).
     */
    private function bookingArrivalCalendarDay(Booking $booking): string
    {
        $unit = (string) ($booking->booking_unit ?? 'day');
        if ($unit === 'hour_package' && $booking->check_in_at) {
            return Carbon::parse($booking->check_in_at)->timezone(config('app.timezone'))->toDateString();
        }

        return Carbon::parse($booking->check_in)->toDateString();
    }

    /**
     * Check-in is allowed on the arrival day only; config booking.allow_early_check_in (testing,
     * never in production) also allows arrivals later than today.
     */
    private function checkInAllowedOn(string $arrivalDay): bool
    {
        $today = Carbon::today()->toDateString();
        if ($arrivalDay === $today) {
            return true;
        }

        return $arrivalDay > $today && config('booking.allow_early_check_in') && ! app()->isProduction();
    }

    /**
     * When creating a day booking, if estimated arrival is before property standard check-in time,
     * persist early_checkin_time (same rule as POST .../early-checkin) so reception sees early
     * check-in as already applied. Does not add extra_charges here — total_price from the client
     * already reflects negotiated charges.
     */
    private function assignEarlyCheckinTimeFromEstimatedArrival(array &$bookingData, string $bookingUnit): void
    {
        if ($bookingUnit === 'hour_package') {
            return;
        }
        $raw = trim((string) ($bookingData['estimated_arrival_time'] ?? ''));
        if ($raw === '') {
            return;
        }
        try {
            $actual = Carbon::parse($raw)->format('H:i');
        } catch (\Throwable $e) {
            return;
        }
        $standardTime = (string) Setting::get('standard_check_in_time', '14:00');
        try {
            $standardTime = Carbon::parse($standardTime)->format('H:i');
        } catch (\Throwable $e) {
            $standardTime = '14:00';
        }
        if ($actual < $standardTime) {
            $bookingData['early_checkin_time'] = $actual;
        }
    }

    // --- Booking Group Management ---

    /**
     * Create only the BookingGroup master record.
     */
    public function storeGroup(Request $request)
    {
        $this->allowReservationCreateGroup();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
        ]);

        $group = BookingGroup::create([
            'name' => $validated['name'],
            'contact_person' => $validated['contact_person'] ?? '',
            'phone' => $validated['phone'] ?? '',
            'email' => $validated['email'] ?? '',
            'status' => 'confirmed',
            'notes' => $validated['notes'] ?? '',
        ]);

        return response()->json($group, 201);
    }

    public function show(Booking $booking)
    {
        $this->allowReservationDetail();

        $booking->load(['room.roomType.tax', 'ratePlan', 'creator:id,name', 'bookingGroup', 'segments.room.roomType']);

        return response()->json($booking->toArray() + ['billing' => $this->bookingBillingSummary($booking)]);
    }

    /**
     * Folio totals as checkout computes them (invoice room recompute incl. GST), for the Bookings detail view.
     *
     * @return array<string, mixed>
     */
    private function bookingBillingSummary(Booking $booking): array
    {
        $isHourly = ($booking->booking_unit ?? 'day') === 'hour_package';
        $invoice = $isHourly
            ? [
                'room_inclusive_grand' => (float) ($booking->total_price ?? 0),
                'additive_extra_charges' => (float) ($booking->extra_charges ?? 0),
            ]
            : BookingInvoiceRoomStay::summarizeForInvoice($booking);
        $state = $this->checkoutPaymentState($booking, 'room');
        $paid = round((float) ($booking->deposit_amount ?? 0), 2);
        $refunded = round((float) ($booking->refund_amount ?? 0), 2);
        $received = round($paid - $refunded, 2);

        $group = null;
        if (! empty($booking->booking_group_id)) {
            $pooled = $this->checkoutPaymentState($booking, 'group');
            $credit = round(max(0.0, $pooled['paid'] - $pooled['bill']), 2);
            $creditBookingId = null;
            if ($credit > 0.009) {
                $creditBookingId = (int) Booking::query()
                    ->where('booking_group_id', '=', $booking->booking_group_id, 'and')
                    ->orderByRaw('(COALESCE(deposit_amount, 0) - COALESCE(refund_amount, 0)) DESC')
                    ->orderBy('id')
                    ->value('id');
            }
            $group = [
                'bookings' => Booking::where('booking_group_id', '=', $booking->booking_group_id, 'and')->count(),
                'bill' => $pooled['bill'],
                'paid' => $pooled['paid'],
                'balance_due' => round(max(0.0, $pooled['bill'] - $pooled['paid']), 2),
                'credit' => $credit,
                'credit_booking_id' => $creditBookingId ?: null,
            ];
        }

        $cancellationFee = round((float) ($booking->cancellation_fee_amount ?? 0), 2);
        if ($booking->status === 'cancelled') {
            $state['bill'] = $cancellationFee;
        }

        return [
            'room' => round((float) $invoice['room_inclusive_grand'], 2),
            'stored_room_total' => round((float) ($booking->total_price ?? 0), 2),
            'extras' => round((float) $invoice['additive_extra_charges'], 2),
            'discount' => round(max(0.0, (float) ($booking->checkout_discount_amount ?? 0)), 2),
            'bill' => $state['bill'],
            'paid' => $paid,
            'refunded' => $refunded,
            'received' => $received,
            'balance_due' => $booking->status === 'cancelled' ? 0.0 : round(max(0.0, $state['bill'] - $received), 2),
            'credit' => round(max(0.0, $received - $state['bill']), 2),
            'cancellation_fee' => $cancellationFee,
            'group' => $group,
        ];
    }

    public function update(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();
        $guestIdentityUploadMeta = [];

        if ($request->has('guest_gstin')) {
            $gstin = strtoupper(trim((string) $request->input('guest_gstin')));
            $request->merge(['guest_gstin' => $gstin !== '' ? $gstin : null]);
        }
        if ($request->has('bill_to_name')) {
            $billTo = trim((string) $request->input('bill_to_name'));
            $request->merge(['bill_to_name' => $billTo !== '' ? $billTo : null]);
        }

        $validated = $request->validate([
            'room_id' => 'exists:rooms,id',
            'first_name' => 'string|max:255',
            'last_name' => 'string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:32',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'bill_to_name' => 'nullable|string|max:255',
            'guest_gstin' => 'nullable|string|max:15|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            'adults_count' => 'integer|min:1',
            'children_count' => 'nullable|integer|min:0',
            'child_ages' => 'nullable|array',
            'child_ages.*' => 'nullable|integer|min:1|max:17',
            'infants_count' => 'nullable|integer|min:0',
            'extra_beds_count' => 'nullable|integer|min:0',
            'booking_unit' => 'nullable|in:day,hour_package',
            'check_in' => 'date',
            'check_out' => 'date|after:check_in',
            'check_in_at' => 'nullable|date',
            'check_out_at' => 'nullable|date|after:check_in_at',
            'total_price' => 'numeric|min:0',
            'payment_method' => 'nullable|string',
            'deposit_amount' => 'nullable|numeric|min:0',
            'refund_amount' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|string|in:cash,card,upi,bank_transfer',
            'status' => 'in:pending,confirmed,checked_in,checked_out,cancelled',
            'booking_source' => 'nullable|string',
            'notes' => 'nullable|string',
            'guest_identity_types' => 'nullable|array|max:20',
            'guest_identity_types.*' => 'nullable|string|max:255',
            'guest_identities' => 'nullable|array|max:20',
            'guest_identities.*' => 'nullable|string|max:'.$this->guestIdentityMaxChars(), // Base64 or this booking's stored paths
            'adult_breakfast_count' => 'nullable|integer|min:0',
            'child_breakfast_count' => 'nullable|integer|min:0',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
            'booking_group_id' => 'nullable|exists:booking_groups,id',
            'checkout_discount_amount' => 'nullable|numeric|min:0',
            'checkout_discount_reason' => 'nullable|string|max:500',
            /** room = settle/check out this booking only; group = pooled group balance (default). */
            'checkout_scope' => 'nullable|in:room,group',
            'force_room_change' => 'nullable|boolean',
        ]);

        if (
            array_key_exists('room_id', $validated)
            && in_array($booking->status, ['confirmed', 'checked_in'], true)
            && (int) $validated['room_id'] !== (int) $booking->room_id
            && ! $request->boolean('force_room_change')
        ) {
            return response()->json([
                'message' => 'Use Room Transfer in the reservation panel to move this guest (reason and rate options are required).',
            ], 422);
        }
        unset($validated['force_room_change']);
        $roomIdsBefore = $booking->segments()->pluck('room_id')->push($booking->room_id)->map(fn ($id) => (int) $id)->all();

        if (
            array_key_exists('status', $validated)
            && $validated['status'] === 'cancelled'
            && $booking->status !== 'cancelled'
        ) {
            return response()->json([
                'message' => 'Use POST /bookings/{id}/cancel to cancel a reservation (policy fee, deposit forfeit, and refund).',
            ], 422);
        }

        if (array_key_exists('status', $validated) && $validated['status'] === $booking->status) {
            unset($validated['status']);
        }
        if (isset($validated['status'])
            && ! in_array($validated['status'], self::STATUS_TRANSITIONS[$booking->status] ?? [], true)) {
            $from = str_replace('_', ' ', (string) $booking->status);
            $to = str_replace('_', ' ', (string) $validated['status']);

            return response()->json(['message' => "A {$from} reservation cannot be changed to {$to}."], 422);
        }

        if (in_array($booking->status, ['checked_out', 'cancelled'], true)) {
            $frozen = array_values(array_intersect(array_keys($validated), self::FIELDS_FROZEN_AFTER_CLOSE));
            if ($frozen !== []) {
                return response()->json([
                    'message' => 'This reservation is closed. Stay, room and payment details can no longer be changed.',
                    'fields' => $frozen,
                ], 422);
            }
        }

        if (array_key_exists('deposit_amount', $validated)) {
            if ($validated['deposit_amount'] === null) {
                unset($validated['deposit_amount']);
            } elseif (round((float) $validated['deposit_amount'], 2) < round((float) ($booking->deposit_amount ?? 0), 2) - 0.004) {
                return response()->json([
                    'message' => 'The amount received cannot be lowered here. Void the payment in the payments panel instead.',
                ], 422);
            }
        }

        $isCheckoutRequest = ($validated['status'] ?? null) === 'checked_out';
        $refundDelta = array_key_exists('refund_amount', $validated) && $validated['refund_amount'] !== null
            ? round((float) $validated['refund_amount'] - (float) ($booking->refund_amount ?? 0), 2)
            : 0.0;
        if ($refundDelta > 0.004 && ! $isCheckoutRequest) {
            return response()->json([
                'message' => 'Record refunds from the payments panel or at check-out.',
            ], 422);
        }

        $roomChanging = array_key_exists('room_id', $validated)
            && (int) $validated['room_id'] !== (int) $booking->room_id;
        if ($roomChanging && $booking->segments()->count() > 1) {
            return response()->json([
                'message' => 'This stay uses more than one room. Use Room Transfer to move the guest.',
            ], 422);
        }

        $checkoutScope = $validated['checkout_scope'] ?? 'group';
        unset($validated['checkout_scope']);

        if ($request->has('checkout_discount_amount') || $request->has('checkout_discount_reason')) {
            if ($booking->status !== 'checked_in') {
                return response()->json([
                    'message' => 'Checkout discount can only be set while the guest is checked in.',
                ], 422);
            }
            $newAmt = array_key_exists('checkout_discount_amount', $validated)
                ? (float) $validated['checkout_discount_amount']
                : (float) ($booking->checkout_discount_amount ?? 0);
            $reason = array_key_exists('checkout_discount_reason', $validated)
                ? trim((string) ($validated['checkout_discount_reason'] ?? ''))
                : trim((string) ($booking->checkout_discount_reason ?? ''));
            $gross = $this->bookingGrossBeforeCheckoutDiscount($booking);
            if ($newAmt > $gross + 0.009) {
                return response()->json([
                    'message' => 'Discount cannot exceed the bill before discount (₹' . number_format($gross, 2, '.', '') . ').',
                ], 422);
            }
            if ($newAmt > 0.004 && strlen($reason) < 3) {
                return response()->json([
                    'message' => 'A reason is required for checkout discounts (at least 3 characters).',
                ], 422);
            }
            $validated['checkout_discount_amount'] = round($newAmt, 2);
            $validated['checkout_discount_reason'] = $newAmt > 0.004 ? $reason : null;
        }

        // Breakfast count validation
        $totalAdults = (int) ($validated['adults_count'] ?? $booking->adults_count);
        $totalChildren = (int) ($validated['children_count'] ?? $booking->children_count);
        $adultB = (int) ($validated['adult_breakfast_count'] ?? $booking->adult_breakfast_count);
        $childB = (int) ($validated['child_breakfast_count'] ?? $booking->child_breakfast_count);

        if ($adultB > $totalAdults || $childB > $totalChildren) {
            return response()->json([
                'message' => 'Breakfast counts cannot exceed guest counts.',
                'errors' => [
                    'adult_breakfast_count' => $adultB > $totalAdults ? ['Must be <= adults count'] : [],
                    'child_breakfast_count' => $childB > $totalChildren ? ['Must be <= children count'] : [],
                ],
            ], 422);
        }

        // The room total is priced here, never taken from the client.
        unset($validated['total_price']);
        $repriced = $this->repricedTotalForGuestChange($booking, $validated);
        if ($repriced !== null) {
            $validated['total_price'] = $repriced;
        }

        // Check-in only on the guest's scheduled arrival date (today).
        if (isset($validated['status']) && $validated['status'] === 'checked_in' && $booking->status !== 'checked_in') {
            $checkInDay = $this->bookingArrivalCalendarDay($booking);
            if (! $this->checkInAllowedOn($checkInDay)) {
                return response()->json([
                    'message' => 'Check-in is only allowed on the guest\'s scheduled arrival date (today).',
                ], 422);
            }

            $roomId = (int) $booking->room_id;
            HousekeepingTurnoverCarryForward::sync($roomId);
            $today = Carbon::today()->toDateString();
            $tomorrow = Carbon::today()->addDay()->toDateString();
            $blocking = RoomStatusBlock::where('room_id', '=', $roomId, 'and')
                ->where('is_active', true)
                ->where('start_date', '<', $tomorrow)
                ->where('end_date', '>', $today)
                ->get();

            if ($blocking->contains(fn($b) => in_array($b->status, ['dirty', 'cleaning'], true))) {
                $room = Room::find($roomId, ['room_number']);

                return response()->json([
                    'message' => "Room #{$room?->room_number} is currently marked Dirty. Complete housekeeping service or assign another clean room before check-in.",
                ], 422);
            }
        }

        // Checkout validation: must be paid
        if (isset($validated['status']) && $validated['status'] === 'checked_out' && $booking->status !== 'checked_out') {
            BookingSplitStayRoomMove::sync((int) $booking->id);

            if ((float) ($validated['refund_amount'] ?? 0) > 0.0001 && empty($validated['refund_method'])) {
                return response()->json(['message' => 'Select how the refund will be issued (cash, card, UPI, or bank transfer).'], 422);
            }

            $inspection = $this->checkoutInspectionForDeparture($this->departingGroupMembers($booking, $checkoutScope));
            if ($inspection['status'] !== 'done') {
                $rooms = implode(', #', $inspection['rooms']);

                return response()->json([
                    'message' => $inspection['status'] === 'pending'
                        ? "Checkout inspection for room #{$rooms} is still with housekeeping. Check out once it is completed."
                        : "Request the checkout inspection for room #{$rooms} before check-out.",
                    'inspection_status' => $inspection['status'],
                ], 422);
            }

            $paymentState = $this->checkoutPaymentState($booking, $checkoutScope);

            if (! $paymentState['is_paid']) {
                return response()->json(['message' => 'Checkout not allowed until payment is fully paid'], 422);
            }

            $refundDue = max(0.0, round($paymentState['paid'] - $paymentState['bill'], 2));
            if ($refundDelta > $refundDue + 0.009) {
                return response()->json([
                    'message' => 'Refund cannot exceed the amount received over the bill (₹' . number_format($refundDue, 2, '.', '') . ').',
                ], 422);
            }

            // Early checkout: truncate the check_out date to free the room for other bookings
            $today = Carbon::today()->toDateString();
            $currentCheckOut = $validated['check_out'] ?? $booking->check_out;
            if ($currentCheckOut > $today) {
                $earlyCheckoutDate = $today;
                $validated['check_out'] = $today;
                // Keep segment/chart/HK on the same departure calendar day (date-only checkout).
                $validated['check_out_at'] = Carbon::parse($today)->startOfDay()->addDay();

                $user = Auth::user();
                $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
                $auditMsg = "[Early CO: on {$today}" . ($userName ? " by {$userName}" : '') . ']';
                $existingNotes = $validated['notes'] ?? $booking->notes;
                $validated['notes'] = $existingNotes ? $existingNotes . "\n" . $auditMsg : $auditMsg;
            }
        }

        // Handle Identity Images (Update/Append): incoming array is indexed by guest slot; null clears.
        $guestIdentityUploadMeta = [];
        if ($request->has('guest_identities')) {
            $incomingImages = $request->input('guest_identities');
            if (! is_array($incomingImages)) {
                $incomingImages = [];
            }
            $newPaths = [];
            $identityService = app(GuestIdentityImageService::class);
            $ownPaths = array_values(array_filter(
                (array) ($booking->guest_identities ?? []),
                fn ($p) => is_string($p) && $p !== '',
            ));
            foreach ($incomingImages as $index => $imageData) {
                $i = (int) $index;
                if ($imageData === null || $imageData === '') {
                    $newPaths[$i] = null;

                    continue;
                }

                try {
                    if (str_starts_with((string) $imageData, 'data:')) {
                        $stored = $identityService->storeDataUrl((string) $imageData, $i);
                        $guestIdentityUploadMeta[] = array_merge(['index' => $i], $stored);
                    } elseif ($request->hasFile("guest_identities.{$i}")) {
                        $stored = $identityService->storeUploadedFile($request->file("guest_identities.{$i}"), $i);
                        $guestIdentityUploadMeta[] = array_merge(['index' => $i], $stored);
                    } elseif (in_array(trim((string) $imageData), $ownPaths, true)) {
                        $stored = $identityService->storeExistingPath((string) $imageData);
                    } else {
                        throw new \InvalidArgumentException('Only this booking\'s stored ID documents or a new upload can be saved.');
                    }
                } catch (\InvalidArgumentException $e) {
                    $this->deleteGuestIdentityFiles(array_column($guestIdentityUploadMeta, 'path'));

                    return response()->json(['message' => $e->getMessage()], 422);
                }
                $newPaths[$i] = $stored['path'];
            }
            ksort($newPaths);
            $validated['guest_identities'] = array_values($newPaths);
        }

        // Keep legacy date columns aligned whenever datetime fields are sent.
        // Normalize zoned/UTC payloads into APP_TIMEZONE wall times before persist.
        if (isset($validated['check_in_at'])) {
            $validated['check_in_at'] = $this->parseHotelDateTime((string) $validated['check_in_at']);
            $validated['check_in'] = $validated['check_in_at']->toDateString();
        }
        if (isset($validated['check_out_at'])) {
            $validated['check_out_at'] = $this->parseHotelDateTime((string) $validated['check_out_at']);
            $validated['check_out'] = $validated['check_out_at']->toDateString();
        }
        if (isset($validated['check_in']) && str_contains((string) $validated['check_in'], 'T')) {
            $ci = $this->parseHotelDateTime((string) $validated['check_in']);
            $validated['check_in_at'] = $ci;
            $validated['check_in'] = $ci->toDateString();
        }
        if (isset($validated['check_out']) && str_contains((string) $validated['check_out'], 'T')) {
            $co = $this->parseHotelDateTime((string) $validated['check_out']);
            $validated['check_out_at'] = $co;
            $validated['check_out'] = $co->toDateString();
        }
        // Early checkout keeps check_out_at at next midnight but the departure date is today.
        if (isset($earlyCheckoutDate)) {
            $validated['check_out'] = $earlyCheckoutDate;
        }

        $isNewCheckout = $isCheckoutRequest;

        // Stay dates or room changing: overlap / hard-block checks (same rules as create), re-run under the room lock.
        $datesChanging = isset($validated['check_in'])
            || isset($validated['check_out'])
            || isset($validated['check_in_at'])
            || isset($validated['check_out_at']);
        $occupancyChanging = isset($validated['adults_count'])
            || isset($validated['children_count'])
            || isset($validated['extra_beds_count']);

        $availabilityCheck = null;
        if (($datesChanging || $roomChanging) && ! in_array($validated['status'] ?? $booking->status, ['cancelled', 'checked_out'], true)) {
            $nextCheckInAt = $this->parseHotelDateTime((string) (
                $validated['check_in_at']
                    ?? $validated['check_in']
                    ?? $booking->check_in_at
                    ?? $booking->check_in
            ));
            $nextCheckOutAt = $this->parseHotelDateTime((string) (
                $validated['check_out_at']
                    ?? $validated['check_out']
                    ?? $booking->check_out_at
                    ?? $booking->check_out
            ));
            $unit = $validated['booking_unit'] ?? $booking->booking_unit ?? 'day';
            if ($unit === 'day') {
                $nextCheckInAt = $nextCheckInAt->copy()->startOfDay();
                $nextCheckOutAt = $nextCheckOutAt->copy()->startOfDay();
            }
            $statusForCheck = (string) ($validated['status'] ?? $booking->status);
            // A guest already in house only needs the target room from now on; past nights were in the old room.
            if ($statusForCheck === 'checked_in') {
                $floor = $unit === 'day' ? Carbon::today() : now();
                if ($nextCheckInAt->lt($floor) && $floor->lt($nextCheckOutAt)) {
                    $nextCheckInAt = $floor->copy();
                }
            }
            $availabilityCheck = [
                (int) ($validated['room_id'] ?? $booking->room_id),
                $nextCheckInAt,
                $nextCheckOutAt,
                $statusForCheck,
            ];
        }

        if ($occupancyChanging || $datesChanging || $roomChanging) {
            $roomForCap = Room::with('roomType')->find((int) ($validated['room_id'] ?? $booking->room_id));
            if ($roomForCap) {
                try {
                    BookingRoomAvailability::assertCapacity(
                        $roomForCap,
                        (int) ($validated['adults_count'] ?? $booking->adults_count ?? 1),
                        (int) ($validated['children_count'] ?? $booking->children_count ?? 0),
                        (int) ($validated['extra_beds_count'] ?? $booking->extra_beds_count ?? 0),
                        is_array($validated['child_ages'] ?? null) ? $validated['child_ages'] : (is_array($booking->child_ages) ? $booking->child_ages : null),
                    );
                } catch (ValidationException $e) {
                    $this->deleteGuestIdentityFiles(array_column($guestIdentityUploadMeta, 'path'));

                    return response()->json([
                        'message' => collect($e->errors())->flatten()->first() ?: 'Occupancy exceeds room capacity.',
                    ], 422);
                }
            }
        }

        $groupMates = $isNewCheckout
            ? $this->departingGroupMembers($booking, $checkoutScope)
                ->filter(fn (Booking $mate) => (int) $mate->id !== (int) $booking->id)
                ->values()
            : collect();

        // Availability, ledger postings, the booking row and the checkout journal commit together or not at all.
        $apply = function () use ($booking, &$validated, $request, $checkoutScope, $availabilityCheck, $isNewCheckout, $groupMates) {
            if ($groupMates->isNotEmpty()) {
                // Lock every departing room in id order so two desks checking out one group cannot deadlock.
                $lockedStatuses = Booking::query()
                    ->whereIn('id', $groupMates->pluck('id')->push($booking->id)->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('status', 'id');
                foreach ($groupMates as $mate) {
                    if ((string) ($lockedStatuses[$mate->id] ?? '') !== (string) $mate->status) {
                        throw ValidationException::withMessages([
                            'booking' => 'This reservation was just changed by another action. Reload it and try again.',
                        ]);
                    }
                }
            }
            $this->lockBookingForChange($booking);
            if ($availabilityCheck !== null) {
                [$roomIdForCheck, $from, $to, $statusForCheck] = $availabilityCheck;
                BookingRoomAvailability::assertSellable($roomIdForCheck, $from, $to, $statusForCheck, (int) $booking->id);
            }

            $this->postPatchLedgerEntries($booking, $validated, $checkoutScope);
            $this->appendAuditNotesForBookingUpdate($booking, $validated, $request);
            $booking->update($validated);

            if (array_key_exists('total_price', $validated) && ! $isNewCheckout) {
                $booking->refresh();
                try {
                    $bill = $this->effectiveBookingGrand($booking);
                } catch (\Throwable) {
                    $bill = (float) ($booking->total_price ?? 0) + (float) ($booking->extra_charges ?? 0);
                }
                $refunded = (float) ($booking->refund_amount ?? 0);
                $net = (float) ($booking->deposit_amount ?? 0) - $refunded;
                $booking->forceFill([
                    'payment_status' => $refunded > 0.004 && $net <= 0.004 ? 'refunded' : $this->paymentStatusFor($net, $bill),
                ])->save();
            }

            if ($isNewCheckout) {
                foreach ($groupMates as $mate) {
                    $mate->update($this->checkedOutStayAttributes($mate));
                }
                $booking->refresh()->load('room.roomType.tax');
                $departing = collect([$booking])->concat(
                    $groupMates->map(fn (Booking $b) => $b->fresh(['room.roomType.tax']))
                );
                if ($checkoutScope === 'group' && $booking->booking_group_id) {
                    app(BookingCheckoutPoster::class)->postGroupDeparture((int) $booking->booking_group_id, $departing, auth()->id());
                } else {
                    app(BookingCheckoutPoster::class)->post($departing->first(), auth()->id());
                }
                foreach ($departing as $departed) {
                    BookingInvoiceNumber::issue($departed);
                }
            }
        };

        try {
            if ($availabilityCheck !== null) {
                BookingRoomAvailability::withRoomLocks([$availabilityCheck[0]], $apply);
            } else {
                DB::transaction($apply);
            }
        } catch (ValidationException $e) {
            $this->deleteGuestIdentityFiles(array_column($guestIdentityUploadMeta, 'path'));

            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: 'Could not update the reservation.',
            ], 422);
        } catch (\Throwable $e) {
            $this->deleteGuestIdentityFiles(array_column($guestIdentityUploadMeta, 'path'));

            throw $e;
        }

        // Room chart renders occupancy from `booking_segments` first (`segment.adults_count ?? booking.adults_count`).
        // Keep segments aligned whenever guest mix or segment-level pricing fields change on the booking.
        if (
            $request->has('adults_count')
            || $request->has('children_count')
            || $request->has('infants_count')
            || $request->has('extra_beds_count')
            || $request->has('rate_plan_id')
            || $request->has('total_price')
        ) {
            $booking->segments()->update([
                'adults_count' => (int) ($booking->adults_count ?? 1),
                'children_count' => (int) ($booking->children_count ?? 0),
                'extra_beds_count' => (int) ($booking->extra_beds_count ?? 0),
                'rate_plan_id' => $booking->rate_plan_id,
                'total_price' => $booking->total_price,
            ]);
        }

        // Rooms the guest already left through a mid-stay room transfer or split stay move (segment closed
        // as checked_out); housekeeping was handled at move time and the room may since have been resold.
        $vacatedRoomIds = [];
        if (($validated['status'] ?? null) === 'checked_out') {
            $segmentStates = $booking->segments()->get(['room_id', 'status']);
            $openRoomIds = $segmentStates->where('status', '!=', 'checked_out')->pluck('room_id')->map(fn($id) => (int) $id);
            if ($openRoomIds->isNotEmpty()) {
                $vacatedRoomIds = $segmentStates->where('status', 'checked_out')->pluck('room_id')
                    ->map(fn($id) => (int) $id)
                    ->diff($openRoomIds)
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        // Sync Stay Segments
        if (isset($validated['room_id']) || isset($validated['check_in']) || isset($validated['check_out']) || isset($validated['status'])) {
            $segmentCount = $booking->segments()->count();
            $newStatus = $validated['status'] ?? $booking->status;

            // If No segments exist, create a baseline one (safety for legacy data)
            if ($segmentCount === 0) {
                $booking->segments()->create([
                    'room_id' => $booking->room_id,
                    'check_in' => $booking->check_in,
                    'check_out' => $booking->check_out,
                    'check_in_at' => $booking->check_in_at ?? Carbon::parse($booking->check_in)->startOfDay(),
                    'check_out_at' => $booking->check_out_at ?? Carbon::parse($booking->check_out)->startOfDay(),
                    'total_price' => $booking->total_price,
                    'status' => $newStatus,
                ]);
            } elseif ($segmentCount === 1) {
                // Keep the single segment in perfect sync
                $booking->segments()->first()->update([
                    'room_id' => $booking->room_id,
                    'check_in' => $booking->check_in,
                    'check_out' => $booking->check_out,
                    'check_in_at' => $booking->check_in_at ?? Carbon::parse($booking->check_in)->startOfDay(),
                    'check_out_at' => $booking->check_out_at ?? Carbon::parse($booking->check_out)->startOfDay(),
                    'total_price' => $booking->total_price,
                    'status' => $newStatus,
                ]);
            } elseif (isset($validated['status'])) {
                // For multi-segment stays, when checking in, find the segment that corresponds to "now" or the first one
                if ($validated['status'] === 'checked_in') {
                    // Continuous stay semantics: once the guest is checked in, ALL segments represent the same
                    // uninterrupted stay (even if room changes later). Mark every segment as checked_in so the
                    // room chart renders the entire stay as occupied across rooms/dates.
                    $booking->segments()->update(['status' => 'checked_in']);
                } elseif ($validated['status'] === 'checked_out' || $validated['status'] === 'cancelled') {
                    // Update all segments if the whole booking is cancelled/checked_out
                    $booking->segments()->update(['status' => $validated['status']]);
                }
            }
        }

        // Sync room status — for split stays, ALL rooms across all segments must be updated.
        if (isset($validated['status'])) {
            $roomStatus = match ($validated['status']) {
                'checked_in' => 'occupied',
                'checked_out' => 'dirty',
                'cancelled' => 'available',
                default => $booking->room->status,
            };

            // Collect every distinct room touched by this booking's segments
            $allRoomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->unique()
                ->reject(fn($id) => in_array((int) $id, $vacatedRoomIds, true));

            // Split stay: only the room the guest walks into now is occupied; later rooms are
            // marked when the guest reaches them (syncSplitStayRoomOccupancy on chart load).
            if ($validated['status'] === 'checked_in' && $booking->segments()->count() > 1) {
                $allRoomIds = collect([$this->roomIdGuestIsInNow($booking)]);
            }

            Room::whereIn('id', $allRoomIds, 'and', false)->update(['status' => $roomStatus]);

            // NEW FLOW: date-based housekeeping after checkout
            // When a booking is checked out, mark the affected rooms as DIRTY for that checkout date
            // via room_status_blocks so Room Chart + availability reflect housekeeping correctly.
            if ($validated['status'] === 'checked_out') {
                // Dirty block uses each segment's checkout day (falls back to booking) so the chart shows
                // housekeeping on the departure column, not the server's "today".
                $segmentsForHk = $booking->segments()->get();
                if ($segmentsForHk->isEmpty()) {
                    $segmentsForHk = collect([
                        (object) [
                            'room_id' => $booking->room_id,
                            'check_out' => $booking->check_out,
                            'check_out_at' => $booking->check_out_at,
                        ],
                    ]);
                }

                $checkoutNotifyRoomIds = [];
                foreach ($segmentsForHk as $segment) {
                    $rid = (int) $segment->room_id;
                    if (in_array($rid, $vacatedRoomIds, true)) {
                        continue;
                    }
                    $checkoutNotifyRoomIds[] = $rid;
                    $checkoutDate = (string) ($segment->check_out ?? $booking->check_out ?? $today);
                    $checkoutDay = Carbon::parse($checkoutDate)->startOfDay();
                    $co = $checkoutDay->toDateString();
                    $coNext = $checkoutDay->copy()->addDay()->toDateString();

                    // Close housekeeping handoff on departure: an inspected/pending_inspection block may span
                    // the whole stay — trimming checkout day still leaves prior nights green on the chart with
                    // no guest segment. Deactivate handoff blocks for this room so past stay columns clear;
                    // then the dirty block marks departure day for housekeeping.
                    RoomStatusBlock::query()
                        ->where('room_id', '=', $rid, 'and')
                        ->where('is_active', '=', true, 'and')
                        ->whereIn('status', ['inspected', 'pending_inspection'], 'and', false)
                        ->update(['is_active' => false]);

                    // Close any dirty/cleaning block on the checkout night so the new departure dirty task
                    // is not suppressed by an older workflow row (otherwise Dirty Rooms stays empty).
                    RoomStatusBlock::query()
                        ->where('room_id', '=', $rid, 'and')
                        ->where('is_active', '=', true, 'and')
                        ->whereIn('status', ['cleaning', 'dirty'], 'and', false)
                        ->where('start_date', '<', $coNext)
                        ->where('end_date', '>', $co)
                        ->update(['is_active' => false]);

                    $hasBlock = RoomStatusBlock::where('room_id', '=', $rid, 'and')
                        ->where('is_active', true)
                        ->where('start_date', '<', $coNext)
                        ->where('end_date', '>', $co)
                        ->exists();

                    if (! $hasBlock) {
                        RoomStatusBlock::create([
                            'room_id' => $rid,
                            'status' => 'dirty',
                            'start_date' => $co,
                            'end_date' => $coNext,
                            'note' => 'Auto: checkout',
                            'is_active' => true,
                            'created_by' => Auth::id(),
                        ]);
                    }
                }
                HousekeepingStateUpdated::dispatchIfEnabled($checkoutNotifyRoomIds, 'booking_checkout');
                foreach ($groupMates as $mate) {
                    $this->applyCheckoutRoomEffects($mate->fresh());
                }
            } elseif ($validated['status'] === 'checked_in') {
                // Daily cleaning tasks are created when front office releases the room for cleaning.
            }
        }

        $payload = $this->bookingJsonWithGuestIdentityMeta(
            $booking->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup']),
            $guestIdentityUploadMeta,
        );
        $payload['doorloom'] = HotelApiSync::syncBooking($booking, $roomIdsBefore);

        return response()->json($payload);
    }

    /**
     * A deposit increase or check-out refund sent on PATCH becomes a ledger posting (keeps history).
     * Decreases and refunds outside check-out are rejected before this runs.
     *
     * @param  array<string, mixed>  $validated
     */
    private function postPatchLedgerEntries(Booking $booking, array &$validated, string $checkoutScope): void
    {
        if (! BookingPaymentLedger::enabled()) {
            return;
        }

        if (array_key_exists('total_price', $validated)) {
            $billHint = round((float) $validated['total_price'] + (float) ($booking->extra_charges ?? 0), 2);
        } else {
            try {
                $billHint = round($this->effectiveBookingGrand($booking), 2);
            } catch (\Throwable) {
                $billHint = round((float) ($booking->total_price ?? 0) + (float) ($booking->extra_charges ?? 0), 2);
            }
        }

        if (array_key_exists('deposit_amount', $validated)) {
            $delta = round((float) $validated['deposit_amount'] - (float) ($booking->deposit_amount ?? 0), 2);
            if ($delta > 0.004) {
                BookingPaymentLedger::recordPayment($booking, [
                    'amount' => $delta,
                    'method' => (string) ($validated['payment_method'] ?? ($booking->payment_method ?: 'cash')),
                    'source' => 'legacy_patch',
                    'notes' => 'Deposit update via booking PATCH',
                    'bill_total' => $billHint,
                ]);
            }
            // Method/status are owned by ledger sync when deposit moved.
            unset($validated['deposit_amount'], $validated['payment_method']);
            $booking->refresh();
        }

        if (array_key_exists('refund_amount', $validated) && $validated['refund_amount'] !== null) {
            $refundDelta = round((float) $validated['refund_amount'] - (float) ($booking->refund_amount ?? 0), 2);
            if ($refundDelta > 0.004) {
                $refundMethod = (string) ($validated['refund_method'] ?? ($booking->refund_method ?: 'cash'));
                if ($checkoutScope === 'group' && $booking->booking_group_id) {
                    $this->recordGroupCheckoutRefund($booking, $refundDelta, $refundMethod, $billHint);
                } else {
                    BookingPaymentLedger::recordRefund($booking, [
                        'amount' => $refundDelta,
                        'method' => $refundMethod,
                        'source' => 'checkout',
                        'notes' => 'Refund recorded via booking update',
                        'bill_total' => $billHint,
                    ]);
                }
            }
            unset($validated['refund_amount'], $validated['refund_method']);
            $booking->refresh();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $guestIdentityUploadMeta
     * @return array<string, mixed>
     */
    private function guestIdentityMaxChars(): int
    {
        $bytes = max(1, (int) config('guest_identity.max_upload_bytes', 8 * 1024 * 1024));

        return (int) (ceil($bytes / 3) * 4) + 64;
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    private function deleteGuestIdentityFiles(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($p) => is_string($p) && $p !== ''));
        if ($paths === []) {
            return;
        }
        $service = app(GuestIdentityImageService::class);
        Storage::disk($service->disk())->delete($paths);
    }

    private function bookingJsonWithGuestIdentityMeta(Booking $booking, array $guestIdentityUploadMeta): array
    {
        $payload = $booking->toArray();
        if ($guestIdentityUploadMeta !== []) {
            $payload['guest_identity_upload_meta'] = $guestIdentityUploadMeta;
        }

        return $payload;
    }

    /**
     * Checked-in guests appear on the daily cleaning board; notify HK UIs and seed today's row.
     */
    private function syncDailyCleaningOnCheckIn(Booking $booking): void
    {
        $today = Carbon::today()->toDateString();
        $bookingId = (int) $booking->id;
        $roomIds = [];

        $segments = $booking->segments;
        if ($segments->isEmpty()) {
            $rid = (int) $booking->room_id;
            if ($rid > 0) {
                $roomIds[] = $rid;
                $cleaning = DailyRoomCleaning::firstOrCreate(
                    [
                        'room_id' => $rid,
                        'service_date' => $today,
                    ],
                    [
                        'booking_id' => $bookingId,
                        'status' => 'pending_cleaning',
                    ]
                );
                if ($bookingId > 0 && (int) ($cleaning->booking_id ?? 0) !== $bookingId) {
                    $cleaning->booking_id = $bookingId;
                    $cleaning->save();
                }
            }
        } else {
            foreach ($segments as $seg) {
                $rid = (int) $seg->room_id;
                if ($rid <= 0) {
                    continue;
                }
                $roomIds[] = $rid;
                $cleaning = DailyRoomCleaning::firstOrCreate(
                    [
                        'room_id' => $rid,
                        'service_date' => $today,
                    ],
                    [
                        'booking_id' => $bookingId,
                        'status' => 'pending_cleaning',
                    ]
                );
                if ($bookingId > 0 && (int) ($cleaning->booking_id ?? 0) !== $bookingId) {
                    $cleaning->booking_id = $bookingId;
                    $cleaning->save();
                }
            }
        }

        $roomIds = array_values(array_unique(array_filter($roomIds)));
        if ($roomIds !== []) {
            HousekeepingStateUpdated::dispatchIfEnabled($roomIds, 'booking_checkin');
        }
    }

    // ── Early Check-In ────────────────────────────────────────────────────────
    public function earlyCheckin(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        if ($rejected = $this->rejectArrivalDepartureTimeChange($booking, 'early check-in')) {
            return $rejected;
        }

        $time = $request->input('time');
        $roomId = (int) ($booking->segments()->orderBy('check_in', 'asc')->value('room_id') ?: $booking->room_id);
        $checkInDay = Carbon::parse($booking->check_in)->toDateString();

        // Conflict: a prior booking for this room is still checked_in on the same day
        // AND its late_checkout_time would overlap with the requested early check-in time.
        // (A booking that is already checked_out is NOT a conflict.)
        $conflict = Booking::where('id', '!=', $booking->id)
            ->where('status', 'checked_in')           // Only block if guest is still in the room
            ->where(function ($q) use ($roomId, $checkInDay) {
                $q->whereHas('segments', function ($s) use ($roomId, $checkInDay) {
                    $s->where('room_id', $roomId)
                        ->whereDate('check_out', '=', $checkInDay, 'and')
                        ->whereNotIn('status', ['cancelled', 'checked_out']);
                })->orWhere(function ($legacy) use ($roomId, $checkInDay) {
                    $legacy->whereDoesntHave('segments')
                        ->where('room_id', $roomId)
                        ->whereDate('check_out', '=', $checkInDay, 'and');
                });
            })
            ->where(function ($q) use ($time) {
                // Blocked if the previous guest leaves at/after the requested time: their late checkout,
                // else the standard check-out time. Hourly stays without a late checkout always block.
                $standardCheckOut = $this->standardClockSetting('standard_check_out_time', '11:00');
                $q->whereTime('late_checkout_time', '>=', $time)
                    ->orWhere(function ($noLate) use ($time, $standardCheckOut) {
                        $noLate->whereNull('late_checkout_time');
                        if ($standardCheckOut < $time) {
                            $noLate->where('booking_unit', 'hour_package');
                        }
                    });
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'message' => 'Early check-in conflicts with a previous guest still occupying the room.',
            ], 422);
        }

        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $auditMsg = "[Early CI: {$time}" . ($userName ? " by {$userName}" : '') . ' on ' . now()->format('Y-m-d H:i:s') . ']';

        $firstRoom = Room::with('roomType')->find($roomId);
        $rt = $firstRoom?->roomType;
        $standardTime = Setting::get('standard_check_in_time', '14:00');
        $nightlyPreTax = BookingInvoiceRoomStay::nightlyRateForFees($booking);
        $fee = 0;
        $units = '';

        if ($rt && $time < $standardTime) {
            $policyTime = Carbon::createFromFormat('H:i', $standardTime);
            $actualTime = Carbon::createFromFormat('H:i', $time);
            $totalGapMins = (int) $actualTime->diffInMinutes($policyTime);

            $bufferMins = (int) ($rt->early_check_in_buffer_minutes ?? 0);
            $billableMins = max(0, $totalGapMins - $bufferMins);

            $fee = BookingInvoiceRoomStay::timeFeeAmount($rt->early_check_in_type, (float) $rt->early_check_in_fee, $billableMins, $nightlyPreTax);
            if ($fee > 0 && in_array($rt->early_check_in_type, ['hour', 'per_hour'], true)) {
                $units = '(' . ceil($billableMins / 60) . 'h)';
            } elseif ($fee > 0 && in_array($rt->early_check_in_type, ['minute', 'per_minute'], true)) {
                $units = "({$billableMins}m)";
            } elseif ($fee > 0 && $rt->early_check_in_type === 'percentage') {
                $units = "({$rt->early_check_in_fee}% of night)";
            }
        }

        // A day booking created with an early arrival time already carries that fee in total_price.
        $includedAtCreate = ($booking->booking_unit ?? 'day') !== 'hour_package' && $firstRoom
            ? $this->earlyCheckInFeeForCreate($firstRoom, (string) ($booking->estimated_arrival_time ?? ''), $nightlyPreTax)
            : 0.0;
        $folioFee = round(max(0.0, $fee - $includedAtCreate), 2);

        if ($fee > 0) {
            $auditMsg .= " Fee: ₹{$fee} {$units} applied.";
            if ($includedAtCreate > 0) {
                $auditMsg .= " Added to folio: ₹{$folioFee}.";
            }
        }

        // Re-setting the time replaces the folio charge of the previous "[Early CI: ...]" audit line.
        $prevFee = BookingInvoiceRoomStay::earlyCheckInFolioFeeFromNotes((string) $booking->notes);
        $fee = $folioFee;

        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        DB::transaction(function () use ($booking, $time, $standardTime, $fee, $prevFee, $notes) {
            $this->lockBookingForChange($booking);
            $booking->update([
                'early_checkin_time' => $time < $standardTime ? $time : null,
                'extra_charges' => max(0.0, (float) ($booking->extra_charges ?? 0) + $fee - $prevFee),
                'notes' => $notes,
            ]);
        });

        return response()->json($booking->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup']));
    }

    // ── Late Checkout ─────────────────────────────────────────────────────────
    public function lateCheckout(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        if ($rejected = $this->rejectArrivalDepartureTimeChange($booking, 'late checkout')) {
            return $rejected;
        }

        $time = $request->input('time');
        $roomId = (int) ($booking->segments()->orderBy('check_out', 'desc')->value('room_id') ?: $booking->room_id);
        $checkOutDay = Carbon::parse($booking->check_out)->toDateString();

        // Conflict: another booking starts on this room the same checkout day
        // and its early_checkin_time (or standard noon) is <= the requested late time
        $conflict = Booking::where('id', '!=', $booking->id)
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->where(function ($q) use ($roomId, $checkOutDay) {
                $q->whereHas('segments', function ($s) use ($roomId, $checkOutDay) {
                    $s->where('room_id', $roomId)
                        ->whereDate('check_in', '=', $checkOutDay, 'and')
                        ->whereNotIn('status', ['cancelled', 'checked_out']);
                })->orWhere(function ($legacy) use ($roomId, $checkOutDay) {
                    $legacy->whereDoesntHave('segments')
                        ->where('room_id', $roomId)
                        ->whereDate('check_in', '=', $checkOutDay, 'and');
                });
            })
            ->where(function ($q) use ($time) {
                // Blocked if the next guest arrives at/before the requested time: their early check-in,
                // else the standard check-in time. Hourly stays without an early check-in always block.
                $standardCheckIn = $this->standardClockSetting('standard_check_in_time', '14:00');
                $q->whereTime('early_checkin_time', '<=', $time)
                    ->orWhere(function ($noEarly) use ($time, $standardCheckIn) {
                        $noEarly->whereNull('early_checkin_time');
                        if ($standardCheckIn > $time) {
                            $noEarly->where('booking_unit', 'hour_package');
                        }
                    });
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'message' => 'Late checkout conflicts with the next guest\'s check-in on the same day.',
            ], 422);
        }

        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');

        $rt = Room::with('roomType')->find($roomId)?->roomType;
        $nightlyPreTax = BookingInvoiceRoomStay::nightlyRateForFees($booking);
        $standardTimeRaw = (string) Setting::get('standard_check_out_time', '11:00');
        $normalizeClockTime = static function (?string $raw, string $fallback): string {
            $s = trim((string) $raw);
            if ($s === '') {
                return $fallback;
            }
            // canonical HH:mm
            if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
                [$h, $m] = array_pad(explode(':', $s, 3), 2, '00');

                return str_pad((string) ((int) $h), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ((int) $m), 2, '0', STR_PAD_LEFT);
            }
            // tolerate formats like "02:00 PM", "2:00PM", etc.
            try {
                return Carbon::parse($s)->format('H:i');
            } catch (\Throwable) {
                return $fallback;
            }
        };
        $standardTime = $normalizeClockTime($standardTimeRaw, '11:00');
        $when = now()->format('Y-m-d H:i:s');

        $computeLateFee = static function ($rt, string $standardTime, ?string $t) use ($nightlyPreTax): float {
            if (! $rt || ! $t) {
                return 0.0;
            }
            $t = trim((string) $t);
            if ($t === '') {
                return 0.0;
            }
            // Ensure HH:mm for safe comparisons + parsing (tolerate legacy AM/PM)
            if (! preg_match('/^\d{2}:\d{2}$/', $t)) {
                try {
                    $t = Carbon::parse($t)->format('H:i');
                } catch (\Throwable) {
                    return 0.0;
                }
            }
            if ($t <= $standardTime) {
                return 0.0;
            }

            $policyTime = Carbon::createFromFormat('H:i', $standardTime);
            $actualTime = Carbon::createFromFormat('H:i', $t);
            $totalGapMins = (int) $policyTime->diffInMinutes($actualTime);

            $bufferMins = (int) ($rt->late_check_out_buffer_minutes ?? 0);
            $billableMins = max(0, $totalGapMins - $bufferMins);

            return BookingInvoiceRoomStay::timeFeeAmount($rt->late_check_out_type, (float) $rt->late_check_out_fee, $billableMins, $nightlyPreTax);
        };

        $prevTime = $booking->late_checkout_time ? (string) $booking->late_checkout_time : null;
        $prevFee = $computeLateFee($rt, (string) $standardTime, $prevTime);
        $newFee = $computeLateFee($rt, (string) $standardTime, (string) $time);
        $delta = $newFee - $prevFee;

        // Clear only when the requested time is at/before the property standard checkout time.
        // Even if the room type policy yields ₹0 (buffer covers it), we still persist the late time
        // so the UI can show the correct "departure time" and "applied" state.
        $shouldClear = $time <= $standardTime;
        $timeToSave = $shouldClear ? null : (string) $time;

        $auditMsg = $shouldClear
            ? "[Late CO cleared: {$time}" . ($userName ? " by {$userName}" : '') . " on {$when}]"
            : "[Late CO: {$time}" . ($userName ? " by {$userName}" : '') . " on {$when}]";

        $nextExtra = (float) ($booking->extra_charges ?? 0) + $delta;
        if ($nextExtra < 0) {
            $nextExtra = 0;
        }

        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        DB::transaction(function () use ($booking, $timeToSave, $nextExtra, $notes) {
            $this->lockBookingForChange($booking);
            $booking->update([
                'late_checkout_time' => $timeToSave,
                'extra_charges' => $nextExtra,
                'notes' => $notes,
            ]);
        });

        return response()->json($booking->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup']));
    }

    private function standardClockSetting(string $key, string $fallback): string
    {
        $raw = trim((string) Setting::get($key, $fallback));
        if ($raw === '') {
            return $fallback;
        }
        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function rejectArrivalDepartureTimeChange(Booking $booking, string $action): ?\Illuminate\Http\JsonResponse
    {
        if (in_array($booking->status, ['cancelled', 'checked_out'], true)) {
            return response()->json([
                'message' => 'Cannot set ' . $action . ' on a ' . str_replace('_', ' ', $booking->status) . ' reservation.',
            ], 422);
        }
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return response()->json([
                'message' => ucfirst($action) . ' does not apply to hourly bookings. Use Extend hours instead.',
            ], 422);
        }

        return null;
    }

    // ── Reservation Extension ─────────────────────────────────────────────────
    public function extendReservation(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if (in_array($booking->status, ['cancelled', 'checked_out'], true)) {
            return response()->json([
                'message' => 'Cannot extend a ' . str_replace('_', ' ', $booking->status) . ' reservation.',
            ], 422);
        }
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return response()->json(['message' => 'Use Extend hours for an hourly package stay.'], 422);
        }

        // IMPORTANT: for multi-segment (room-change) stays, extensions continue from the
        // LAST segment (latest check_out). Validate against that anchor — not only
        // bookings.check_out — or the API rejects valid dates while the UI shows the segment end.
        $lastSegment = $booking->segments()->orderBy('check_out', 'desc')->first();
        if (! $lastSegment) {
            // Safety for legacy data: if no segment exists, create one mirroring the booking
            $lastSegment = $booking->segments()->create([
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
        }

        $anchorCheckOut = $lastSegment->check_out;
        $request->validate([
            'new_check_out' => 'required|date|after:' . $anchorCheckOut,
        ]);

        $oldCheckOut = $lastSegment->check_out;
        $newCheckOut = $request->input('new_check_out');
        $roomId = $lastSegment->room_id;

        // Overlap check for the extension gap [current check_out → new check_out]
        // IMPORTANT: use segments (not bookings.room_id) so split-stays are handled correctly.
        $conflictSegment = BookingSegment::with(['booking.room.roomType'])
            ->where('room_id', $roomId)
            ->where('booking_id', '!=', $booking->id)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', Carbon::parse($newCheckOut)->startOfDay())
            ->where('check_out_at', '>', Carbon::parse($oldCheckOut)->startOfDay())
            ->orderBy('check_in', 'asc')
            ->first();
        $conflict = $conflictSegment?->booking;

        if ($conflict) {
            return response()->json([
                'message' => 'Room Conflict Detected',
                'conflict' => $conflict,
                'suggestion' => 'Move future reservation or move current guest.',
            ], 409);
        }

        // Block extension into dates where the room is on hold
        $holdBlock = RoomStatusBlock::where('room_id', '=', $roomId, 'and')
            ->where('is_active', true)
            ->where('status', 'on_hold')
            ->where('start_date', '<', $newCheckOut)
            ->where('end_date', '>', $oldCheckOut)
            ->first();

        if ($holdBlock) {
            return response()->json([
                'message' => 'Room On Hold',
                'on_hold' => true,
                'hold_reason' => $holdBlock->note,
                'hold_start' => $holdBlock->start_date,
                'hold_end' => $holdBlock->end_date,
            ], 409);
        }

        $maintenanceBlock = RoomStatusBlock::where('room_id', '=', $roomId, 'and')
            ->where('is_active', true)
            ->where('status', 'maintenance')
            ->where('start_date', '<', $newCheckOut)
            ->where('end_date', '>', $oldCheckOut)
            ->first();

        if ($maintenanceBlock) {
            $roomNumber = Room::whereKey($roomId)->value('room_number');

            return response()->json([
                'message' => "Room #{$roomNumber} is under maintenance for the extension dates.",
            ], 422);
        }

        // Recalculate total price using rate plan if available (based on the room being extended)
        $room = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons'])->find($roomId);
        $extraCost = $this->dayExtensionExtraCost(
            $booking,
            $room,
            Carbon::parse($oldCheckOut)->toDateString(),
            Carbon::parse($newCheckOut)->toDateString(),
        );
        $newTotalPrice = (float) $booking->total_price + $extraCost;

        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $auditMsg = "[Extension: {$oldCheckOut} → {$newCheckOut}" . ($userName ? " by {$userName}" : '') . ' on ' . now()->format('Y-m-d H:i:s') . ']';
        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        try {
            BookingRoomAvailability::withRoomLocks([(int) $roomId], function () use ($booking, $lastSegment, $roomId, $oldCheckOut, $newCheckOut, $newTotalPrice, $extraCost, $notes) {
                $this->lockBookingForChange($booking);
                $taken = BookingSegment::query()
                    ->where('room_id', $roomId)
                    ->where('booking_id', '!=', $booking->id)
                    ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
                    ->where('check_in_at', '<', Carbon::parse($newCheckOut)->startOfDay())
                    ->where('check_out_at', '>', Carbon::parse($oldCheckOut)->startOfDay())
                    ->exists()
                    || RoomStatusBlock::where('room_id', '=', $roomId, 'and')
                        ->where('is_active', true)
                        ->whereIn('status', ['on_hold', 'maintenance'])
                        ->where('start_date', '<', $newCheckOut)
                        ->where('end_date', '>', $oldCheckOut)
                        ->exists();
                if ($taken) {
                    throw ValidationException::withMessages([
                        'new_check_out' => 'The room was just booked or blocked for the extension dates. Reload and try again.',
                    ]);
                }

                $booking->update([
                    'check_out' => $newCheckOut,
                    'check_out_at' => Carbon::parse($newCheckOut)->startOfDay(),
                    'total_price' => $newTotalPrice,
                    'notes' => $notes,
                ]);

                // Update the LAST segment to match the extension (continue the chain)
                $lastSegment->update([
                    'check_out' => $newCheckOut,
                    'check_out_at' => Carbon::parse($newCheckOut)->startOfDay(),
                    'total_price' => (float) $lastSegment->total_price + $extraCost,
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        $doorloom = HotelApiSync::syncBooking($booking);

        return response()->json($booking->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup', 'segments.room'])->toArray() + ['doorloom' => $doorloom]);
    }

    /**
     * Preview a day-stay extension (same charge as {@see extendReservation}) without saving.
     */
    public function previewExtend(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if (in_array($booking->status, ['cancelled', 'checked_out'], true)) {
            return response()->json([
                'message' => 'Cannot extend a ' . str_replace('_', ' ', $booking->status) . ' reservation.',
            ], 422);
        }
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return response()->json([
                'message' => 'Use preview extend hours for hourly bookings.',
            ], 422);
        }

        $lastSegment = $booking->segments()->orderBy('check_out', 'desc')->first();
        $anchorCheckOut = $lastSegment?->check_out ?? $booking->check_out;
        $request->validate([
            'new_check_out' => 'required|date|after:' . $anchorCheckOut,
        ]);

        $oldCheckOut = $anchorCheckOut;
        $newCheckOut = $request->input('new_check_out');
        $roomId = (int) ($lastSegment?->room_id ?? $booking->room_id);
        $extraNights = (int) Carbon::parse($oldCheckOut)->diffInDays(Carbon::parse($newCheckOut));
        $room = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons'])->find($roomId);
        $extraCost = $this->dayExtensionExtraCost(
            $booking,
            $room,
            Carbon::parse($oldCheckOut)->toDateString(),
            Carbon::parse($newCheckOut)->toDateString(),
        );
        $oldTotal = (float) ($booking->total_price ?? 0);

        $conflictMessage = null;
        $onHold = false;
        $conflictSegment = BookingSegment::query()
            ->where('room_id', $roomId)
            ->where('booking_id', '!=', $booking->id)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', Carbon::parse($newCheckOut)->startOfDay())
            ->where('check_out_at', '>', Carbon::parse($oldCheckOut)->startOfDay())
            ->exists();
        if ($conflictSegment) {
            $conflictMessage = 'Room conflict for the extra nights — move the overlapping reservation or pick another checkout date.';
        }

        $holdBlock = RoomStatusBlock::where('room_id', '=', $roomId, 'and')
            ->where('is_active', true)
            ->where('status', 'on_hold')
            ->where('start_date', '<', $newCheckOut)
            ->where('end_date', '>', $oldCheckOut)
            ->first();
        if ($holdBlock) {
            $onHold = true;
            $conflictMessage = 'Room is on hold for the extra nights.';
        }

        $maintenanceBlock = RoomStatusBlock::where('room_id', '=', $roomId, 'and')
            ->where('is_active', true)
            ->where('status', 'maintenance')
            ->where('start_date', '<', $newCheckOut)
            ->where('end_date', '>', $oldCheckOut)
            ->exists();
        if ($maintenanceBlock && $conflictMessage === null) {
            $roomNumber = Room::whereKey($roomId)->value('room_number');
            $conflictMessage = "Room #{$roomNumber} is under maintenance for the extension dates.";
        }

        return response()->json([
            'current_total' => round($oldTotal, 2),
            'new_total' => round($oldTotal + $extraCost, 2),
            'delta' => round($extraCost, 2),
            'extra_nights' => $extraNights,
            'has_conflict' => $conflictMessage !== null,
            'on_hold' => $onHold,
            'conflict_message' => $conflictMessage,
        ]);
    }

    /**
     * Extra inclusive charge for the added nights [from → to): seasons per night, GST per `room_rates_include_gst`.
     * Same formula for extendReservation and previewExtend.
     */
    private function dayExtensionExtraCost(Booking $booking, ?Room $room, string $fromYmd, string $toYmd): float
    {
        $extraNights = (int) Carbon::parse($fromYmd)->diffInDays(Carbon::parse($toYmd));
        if ($extraNights <= 0 || ! $room?->roomType) {
            return 0.0;
        }

        // Complimentary upgrade keeps the previous category's plan id. That plan is not on
        // this room type, so grow the stored room total by its current nightly average
        // instead of switching to the new category's first plan.
        if ($booking->rate_plan_id && ! $room->roomType->ratePlans->find($booking->rate_plan_id)) {
            $checkIn = Carbon::parse($booking->check_in_at ?? $booking->check_in)->startOfDay();
            $checkOut = Carbon::parse($booking->check_out_at ?? $booking->check_out)->startOfDay();
            $nights = max(1, (int) $checkIn->diffInDays($checkOut));

            return round(((float) ($booking->total_price ?? 0) / $nights) * $extraNights, 2);
        }

        return $this->computeDayStayChargesForRange($booking, $room, $fromYmd, $toYmd)['total'];
    }

    /**
     * @return array{nights: int, room_subtotal: float, meal_subtotal: float, tax: float, total: float}
     */
    private function computeDayStayChargesForRange(
        Booking $booking,
        Room $room,
        string $checkInYmd,
        string $checkOutYmd,
        bool $includeMeals = true,
        bool $includeTax = true,
    ): array {
        $nights = (int) Carbon::parse($checkInYmd)->diffInDays(Carbon::parse($checkOutYmd));
        if ($nights <= 0) {
            return [
                'nights' => 0,
                'room_subtotal' => 0.0,
                'meal_subtotal' => 0.0,
                'tax' => 0.0,
                'total' => 0.0,
            ];
        }

        $rt = $room->roomType;
        if (! $rt) {
            return [
                'nights' => $nights,
                'room_subtotal' => 0.0,
                'meal_subtotal' => 0.0,
                'tax' => 0.0,
                'total' => 0.0,
            ];
        }

        $ratePlan = null;
        if ($booking->rate_plan_id) {
            $ratePlan = $rt->ratePlans->find($booking->rate_plan_id);
        }
        if (! $ratePlan) {
            $ratePlan = $rt->ratePlans->first();
        }

        $basePrice = $ratePlan ? (float) $ratePlan->base_price : (float) ($rt->base_price ?? 0);
        $extraPerNight = SeasonalRoomPricing::extraBedPreTax(
            $rt,
            (int) ($booking->adults_count ?? 1),
            (int) ($booking->children_count ?? 0),
            is_array($booking->child_ages) ? $booking->child_ages : null,
            (int) ($booking->extra_beds_count ?? 0),
        );
        $mealSubtotal = 0.0;
        if ($includeMeals) {
            $nightlyMeal = BookingInvoiceRoomStay::nightlyPlanMealsPreTax(
                $rt,
                $ratePlan,
                (int) ($booking->adults_count ?? 1),
                (int) ($booking->children_count ?? 0),
            );
            $mealSubtotal = round($nightlyMeal * $nights, 2);
        }

        $roomSubtotal = round(SeasonalRoomPricing::sumDayRoomRentWithSeasons(
            $basePrice,
            $extraPerNight,
            $extraPerNight > 0 ? 1 : 0,
            Carbon::parse($checkInYmd),
            Carbon::parse($checkOutYmd),
            $rt->seasons ?? [],
        ), 2);
        $taxable = $roomSubtotal + $mealSubtotal;
        $tax = 0.0;
        $roomRatesIncludeGst = filter_var(Setting::get('room_rates_include_gst', '0'), FILTER_VALIDATE_BOOLEAN);
        if ($includeTax && $rt->tax && ! $roomRatesIncludeGst) {
            $tax = round($taxable * ((float) $rt->tax->rate / 100), 2);
        }

        return [
            'nights' => $nights,
            'room_subtotal' => $roomSubtotal,
            'meal_subtotal' => $mealSubtotal,
            'tax' => $tax,
            'total' => round($taxable + $tax, 2),
        ];
    }

    private function resolveLastBookingSegment(Booking $booking): BookingSegment
    {
        $lastSegment = $booking->segments()->orderBy('check_out', 'desc')->first();
        if ($lastSegment) {
            return $lastSegment;
        }

        return $booking->segments()->create([
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
    }

    /**
     * Preview financial impact of shortening a day stay before the scheduled checkout.
     */
    public function previewEarlyCheckout(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return response()->json(['message' => 'Early checkout is not available for hourly package stays.'], 422);
        }

        if (in_array($booking->status, ['checked_out', 'cancelled'], true)) {
            return response()->json(['message' => 'Cannot modify a cancelled or checked-out reservation.'], 422);
        }

        $validated = $request->validate([
            'new_check_out' => 'required|date',
            'recalculate_room_charges' => 'nullable|boolean',
            'recalculate_meal_charges' => 'nullable|boolean',
            'recalculate_taxes' => 'nullable|boolean',
        ]);

        $lastSegment = $this->resolveLastBookingSegment($booking);
        $checkInYmd = Carbon::parse($lastSegment->check_in ?? $booking->check_in)->toDateString();
        $oldCheckOut = (string) ($lastSegment->check_out ?? $booking->check_out);
        $newCheckOut = Carbon::parse($validated['new_check_out'])->toDateString();

        if ($newCheckOut <= $checkInYmd) {
            return response()->json(['message' => 'New checkout must be after the check-in date.'], 422);
        }
        if ($newCheckOut >= $oldCheckOut) {
            return response()->json(['message' => 'New checkout must be before the scheduled checkout date.'], 422);
        }

        if ($booking->status === 'checked_in') {
            $today = Carbon::today()->toDateString();
            if ($newCheckOut < $today) {
                return response()->json(['message' => 'For in-house guests, early checkout cannot be before today.'], 422);
            }
        }

        $room = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons'])->findOrFail((int) $lastSegment->room_id);
        $recalcRoom = $request->boolean('recalculate_room_charges', true);
        $recalcMeals = $request->boolean('recalculate_meal_charges', true);
        $recalcTax = $request->boolean('recalculate_taxes', true);

        $originalBreakdown = $this->computeDayStayChargesForRange(
            $booking,
            $room,
            $checkInYmd,
            $oldCheckOut,
            $recalcMeals,
            $recalcTax,
        );
        $updatedBreakdown = $this->computeDayStayChargesForRange(
            $booking,
            $room,
            $checkInYmd,
            $newCheckOut,
            $recalcMeals,
            $recalcTax,
        );

        // Keep the booked (possibly negotiated) rate: the plan price only sets the share of the stored segment
        // total the remaining nights carry (seasons included). Without a plan price, prorate by nights.
        $storedSegmentTotal = $booking->segments()->count() > 1
            ? (float) ($lastSegment->total_price ?? 0)
            : (float) ($booking->total_price ?? 0);
        $planOriginal = (float) $originalBreakdown['total'];
        $scale = $planOriginal > 0.004 ? $storedSegmentTotal / $planOriginal : null;
        $scaleBreakdown = static function (array $b, ?float $factor, float $nightsShare, float $stored): array {
            if ($factor === null) {
                $total = round($stored * $nightsShare, 2);

                return ['nights' => $b['nights'], 'room_subtotal' => $total, 'meal_subtotal' => 0.0, 'tax' => 0.0, 'total' => $total];
            }
            foreach (['room_subtotal', 'meal_subtotal', 'tax', 'total'] as $k) {
                $b[$k] = round((float) $b[$k] * $factor, 2);
            }

            return $b;
        };
        $originalBreakdown = $scaleBreakdown($originalBreakdown, $scale, 1.0, $storedSegmentTotal);
        $updatedBreakdown = $scaleBreakdown(
            $updatedBreakdown,
            $scale,
            $updatedBreakdown['nights'] / max(1, $originalBreakdown['nights']),
            $storedSegmentTotal,
        );

        // Split / transferred stays: only the last segment is re-priced; earlier segments keep their charges.
        $otherSegmentsTotal = round((float) $booking->segments()->whereKeyNot($lastSegment->id)->sum('total_price'), 2);

        $originalRoomCharges = $recalcRoom
            ? $otherSegmentsTotal + $originalBreakdown['total']
            : (float) ($booking->total_price ?? 0);
        $updatedRoomCharges = $recalcRoom
            ? $otherSegmentsTotal + $updatedBreakdown['total']
            : (float) ($booking->total_price ?? 0);

        $taxAdjustment = round($updatedBreakdown['tax'] - $originalBreakdown['tax'], 2);
        $mealAdjustment = round($updatedBreakdown['meal_subtotal'] - $originalBreakdown['meal_subtotal'], 2);
        $chargeDelta = round($updatedRoomCharges - $originalRoomCharges, 2);

        $deposit = (float) ($booking->deposit_amount ?? 0);
        $additionalDue = max(0.0, round($updatedRoomCharges - $deposit, 2));
        $creditBalance = max(0.0, round($deposit - $updatedRoomCharges, 2));
        $refundAmount = $creditBalance;

        return response()->json([
            'check_in' => $checkInYmd,
            'original_check_out' => $oldCheckOut,
            'new_check_out' => $newCheckOut,
            'original_nights' => $originalBreakdown['nights'],
            'new_nights' => $updatedBreakdown['nights'],
            'original_room_charges' => round($originalRoomCharges, 2),
            'updated_room_charges' => round($updatedRoomCharges, 2),
            'tax_adjustment' => $taxAdjustment,
            'meal_adjustment' => $mealAdjustment,
            'charge_delta' => $chargeDelta,
            'refund_amount' => round($refundAmount, 2),
            'additional_due' => round($additionalDue, 2),
            'credit_balance' => round($creditBalance, 2),
            'deposit_amount' => round($deposit, 2),
            'other_segments_total' => $otherSegmentsTotal,
            'original_breakdown' => $originalBreakdown,
            'updated_breakdown' => $updatedBreakdown,
        ]);
    }

    /**
     * Shorten a day stay, recalculate charges, release inventory, and audit the change.
     */
    public function applyEarlyCheckout(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return response()->json(['message' => 'Early checkout is not available for hourly package stays.'], 422);
        }

        if (in_array($booking->status, ['checked_out', 'cancelled'], true)) {
            return response()->json(['message' => 'Cannot modify a cancelled or checked-out reservation.'], 422);
        }

        $validated = $request->validate([
            'new_check_out' => 'required|date',
            'reason' => 'required|string|in:guest_changed_plans,emergency_departure,dissatisfied,business_travel_change,other',
            'reason_notes' => 'nullable|string|max:500',
            'release_inventory' => 'nullable|boolean',
            'recalculate_room_charges' => 'nullable|boolean',
            'recalculate_meal_charges' => 'nullable|boolean',
            'recalculate_taxes' => 'nullable|boolean',
        ]);

        $previewRequest = Request::create('', 'POST', [
            'new_check_out' => $validated['new_check_out'],
            'recalculate_room_charges' => $validated['recalculate_room_charges'] ?? true,
            'recalculate_meal_charges' => $validated['recalculate_meal_charges'] ?? true,
            'recalculate_taxes' => $validated['recalculate_taxes'] ?? true,
        ]);
        $previewResponse = $this->previewEarlyCheckout($previewRequest, $booking);
        if ($previewResponse->getStatusCode() !== 200) {
            return $previewResponse;
        }
        $preview = $previewResponse->getData(true);

        $lastSegment = $this->resolveLastBookingSegment($booking);
        $oldCheckOut = (string) ($lastSegment->check_out ?? $booking->check_out);
        $newCheckOut = (string) $preview['new_check_out'];
        $newTotal = (float) $preview['updated_room_charges'];
        $credit = (float) ($preview['credit_balance'] ?? 0);

        $reasonLabels = [
            'guest_changed_plans' => 'Guest changed plans',
            'emergency_departure' => 'Emergency departure',
            'dissatisfied' => 'Dissatisfied with stay',
            'business_travel_change' => 'Business travel change',
            'other' => 'Other',
        ];
        $reasonLabel = $reasonLabels[$validated['reason']] ?? $validated['reason'];
        if ($validated['reason'] === 'other' && ! empty($validated['reason_notes'])) {
            $reasonLabel .= ' — ' . trim((string) $validated['reason_notes']);
        }

        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $timestamp = now()->format('Y-m-d H:i:s');
        $creditLine = $credit > 0.004 ? ' | Credit generated: ₹' . number_format($credit, 2) : '';
        $auditMsg = "[Early Checkout: {$oldCheckOut} → {$newCheckOut} | Reason: {$reasonLabel}{$creditLine}"
            . ($userName ? " by {$userName}" : '')
            . " on {$timestamp}]";
        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $newCheckOutAt = Carbon::parse($newCheckOut)->startOfDay();
        $wasCheckedIn = $booking->status === 'checked_in';

        try {
            DB::transaction(function () use ($request, $booking, $lastSegment, $preview, $newCheckOut, $newCheckOutAt, $newTotal, $notes, $oldCheckOut) {
                $this->lockBookingForChange($booking);

                $booking->update([
                    'check_out' => $newCheckOut,
                    'check_out_at' => $newCheckOutAt,
                    'total_price' => $newTotal,
                    'notes' => $notes,
                ]);

                $segmentTotal = $newTotal;
                if ($booking->segments()->count() > 1) {
                    $segmentTotal = $request->boolean('recalculate_room_charges', true)
                        ? round((float) $preview['updated_breakdown']['total'], 2)
                        : round(
                            (float) $lastSegment->total_price * ($preview['new_nights'] / max(1, $preview['original_nights'])),
                            2,
                        );
                }

                $lastSegment->update([
                    'check_out' => $newCheckOut,
                    'check_out_at' => $newCheckOutAt,
                    'total_price' => $segmentTotal,
                ]);

                if ($request->boolean('release_inventory', true)) {
                    RoomStatusBlock::where('room_id', '=', (int) $lastSegment->room_id, 'and')
                        ->where('is_active', true)
                        ->where('status', 'on_hold')
                        ->where('start_date', '>=', $newCheckOut)
                        ->where('start_date', '<', $oldCheckOut)
                        ->update(['is_active' => false]);
                }
            });
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $lastSegment->room_id], 'early_checkout');

        $fresh = $booking->fresh()->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup', 'segments.room']);

        return response()->json([
            'message' => 'Early checkout applied.',
            'doorloom' => HotelApiSync::syncBooking($fresh),
            'booking' => $fresh,
            'preview' => $preview,
            'suggest_settle_folio' => $wasCheckedIn,
        ]);
    }

    // ── Change check-in date (reserved, not yet arrived) ───────────────────────
    public function previewChangeCheckIn(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        $resolved = $this->resolveCheckInChange($request, $booking);
        if ($resolved instanceof \Illuminate\Http\JsonResponse) {
            return $resolved;
        }

        return response()->json($resolved['preview']);
    }

    public function changeCheckIn(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        $resolved = $this->resolveCheckInChange($request, $booking);
        if ($resolved instanceof \Illuminate\Http\JsonResponse) {
            return $resolved;
        }

        $preview = $resolved['preview'];
        $newCheckInAt = $resolved['new_check_in_at'];
        $newCheckOutAt = $resolved['new_check_out_at'];

        $user = Auth::user();
        $userName = $user ? $user->name : '';
        $timestamp = now()->format('Y-m-d H:i:s');
        $checkoutLine = $preview['new_check_out'] !== $preview['original_check_out']
            ? " | Check-out: {$preview['original_check_out']} → {$preview['new_check_out']}"
            : '';
        $auditMsg = "[Check-in date: {$preview['original_check_in']} → {$preview['new_check_in']}{$checkoutLine}"
            . ($userName ? " by {$userName}" : '')
            . " on {$timestamp}]";
        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        try {
            DB::transaction(function () use ($booking, $preview, $newCheckInAt, $newCheckOutAt, $notes) {
                $this->lockBookingForChange($booking);
                BookingRoomAvailability::lockAndAssertSellable(
                    [(int) $booking->room_id],
                    $newCheckInAt,
                    $newCheckOutAt,
                    (string) $booking->status,
                    (int) $booking->id,
                );

                $booking->update([
                    'check_in' => $preview['new_check_in'],
                    'check_in_at' => $newCheckInAt,
                    'check_out' => $preview['new_check_out'],
                    'check_out_at' => $newCheckOutAt,
                    'total_price' => $preview['new_total'],
                    'notes' => $notes,
                ]);

                $booking->segments()->update([
                    'check_in' => $preview['new_check_in'],
                    'check_in_at' => $newCheckInAt,
                    'check_out' => $preview['new_check_out'],
                    'check_out_at' => $newCheckOutAt,
                    'total_price' => $preview['new_total'],
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        $fresh = $booking->fresh();

        return response()->json([
            'message' => 'Check-in date changed.',
            'booking' => $fresh->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup', 'segments.room']),
            'preview' => $preview,
            'doorloom' => HotelApiSync::syncBooking($fresh),
        ]);
    }

    /**
     * Shared guards + pricing for the check-in date change preview/apply pair.
     * Keeping the number of nights shifts check-out by the same days and keeps the total;
     * otherwise the booked average nightly rate is applied to the new night count.
     *
     * @return array{preview: array<string, mixed>, new_check_in_at: Carbon, new_check_out_at: Carbon}|\Illuminate\Http\JsonResponse
     */
    private function resolveCheckInChange(Request $request, Booking $booking)
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return response()->json(['message' => 'Check-in date cannot be changed for hourly package stays.'], 422);
        }

        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json(['message' => 'Check-in date can only be changed before the guest checks in.'], 422);
        }

        if ($booking->segments()->count() > 1) {
            return response()->json(['message' => 'Check-in date cannot be changed for split stays.'], 422);
        }

        $validated = $request->validate([
            'new_check_in' => 'required|date',
            'keep_nights' => 'nullable|boolean',
        ]);

        $keepNights = (bool) ($validated['keep_nights'] ?? false);
        $oldCheckIn = Carbon::parse($booking->check_in)->startOfDay();
        $oldCheckOut = Carbon::parse($booking->check_out)->startOfDay();
        $newCheckIn = Carbon::parse($validated['new_check_in'])->startOfDay();
        $oldNights = max(1, (int) $oldCheckIn->diffInDays($oldCheckOut));

        if ($newCheckIn->lt(now()->startOfDay())) {
            return response()->json(['message' => 'Check-in date cannot be in the past.'], 422);
        }

        if ($newCheckIn->equalTo($oldCheckIn)) {
            return response()->json(['message' => 'Choose a different check-in date.'], 422);
        }

        $newCheckOut = $keepNights ? $newCheckIn->copy()->addDays($oldNights) : $oldCheckOut->copy();
        if ($newCheckIn->gte($newCheckOut)) {
            return response()->json(['message' => 'Check-in date must be before the check-out date.'], 422);
        }
        $newNights = max(1, (int) $newCheckIn->diffInDays($newCheckOut));

        $oldCheckInAt = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : $oldCheckIn->copy();
        $oldCheckOutAt = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : $oldCheckOut->copy();
        $newCheckInAt = $newCheckIn->copy()->setTimeFrom($oldCheckInAt);
        $newCheckOutAt = $keepNights ? $newCheckOut->copy()->setTimeFrom($oldCheckOutAt) : $oldCheckOutAt->copy();

        try {
            BookingRoomAvailability::assertSellable(
                (int) $booking->room_id,
                $newCheckInAt,
                $newCheckOutAt,
                (string) $booking->status,
                (int) $booking->id,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'Room is not available for the new dates.'], 422);
        }

        $oldTotal = round((float) $booking->total_price, 2);
        $nightlyRate = round($oldTotal / $oldNights, 2);
        $newTotal = $newNights === $oldNights ? $oldTotal : round($nightlyRate * $newNights, 2);
        $paid = round(max(0.0, (float) ($booking->deposit_amount ?? 0) - (float) ($booking->refund_amount ?? 0)), 2);

        return [
            'preview' => [
                'original_check_in' => $oldCheckIn->toDateString(),
                'original_check_out' => $oldCheckOut->toDateString(),
                'new_check_in' => $newCheckIn->toDateString(),
                'new_check_out' => $newCheckOut->toDateString(),
                'original_nights' => $oldNights,
                'new_nights' => $newNights,
                'keep_nights' => $keepNights,
                'nightly_rate' => $nightlyRate,
                'original_total' => $oldTotal,
                'new_total' => $newTotal,
                'difference' => round($newTotal - $oldTotal, 2),
                'amount_paid' => $paid,
                'balance_after' => round($newTotal - $paid, 2),
            ],
            'new_check_in_at' => $newCheckInAt,
            'new_check_out_at' => $newCheckOutAt,
        ];
    }

    // ── Hourly Reservation Extension (supports +1h, +2h, etc.) ─────────────────
    public function extendHourlyReservation(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        $validated = $request->validate([
            'extend_minutes' => 'required|integer|min:1',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
        ]);

        if (($booking->booking_unit ?? 'day') !== 'hour_package') {
            return response()->json([
                'message' => 'This extension endpoint is only for hourly package bookings.',
            ], 422);
        }

        if (in_array($booking->status, ['cancelled', 'checked_out'], true)) {
            return response()->json([
                'message' => 'Cannot extend a cancelled or checked-out reservation.',
            ], 422);
        }

        $checkInAt = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $currentCheckOutAt = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();
        $newCheckOutAt = $currentCheckOutAt->copy()->addMinutes((int) $validated['extend_minutes']);

        // Identify segment/room being extended (last active segment for split-stay safety).
        $lastSegment = $booking->segments()->orderBy('check_out_at', 'desc')->first();
        if (! $lastSegment) {
            $lastSegment = $booking->segments()->create([
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
        }

        $roomId = $lastSegment->room_id;

        // Overlap check only for the newly added window [old_end, new_end)
        $conflictSegment = BookingSegment::with(['booking.room.roomType'])
            ->where('room_id', $roomId)
            ->where('booking_id', '!=', $booking->id)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $newCheckOutAt)
            ->where('check_out_at', '>', $currentCheckOutAt)
            ->orderBy('check_in_at', 'asc')
            ->first();

        if ($conflictSegment?->booking) {
            return response()->json([
                'message' => 'Room Conflict Detected',
                'conflict' => $conflictSegment->booking,
                'suggestion' => 'Move future reservation or end current stay earlier.',
            ], 409);
        }

        $room = Room::with(['roomType.tax', 'roomType.ratePlans'])->findOrFail($roomId);
        $planId = (int) ($validated['rate_plan_id'] ?? $booking->rate_plan_id ?? 0);
        if ($planId <= 0) {
            return response()->json(['message' => 'rate_plan_id is required for hourly extension.'], 422);
        }

        $extraBeds = (int) ($booking->extra_beds_count ?? 0);
        $calc = $this->computeHourlyPackageTotal(
            $room,
            $planId,
            $checkInAt,
            $newCheckOutAt,
            $extraBeds,
            (int) ($booking->adults_count ?? 1),
            (int) ($booking->children_count ?? 0),
            is_array($booking->child_ages) ? $booking->child_ages : null,
        );
        if (! $calc['ok']) {
            return response()->json(['message' => $calc['message']], 422);
        }

        $newTotal = (float) $calc['total'];
        $newCheckOutDate = $this->dateEndExclusiveFromDateTime($newCheckOutAt);

        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $hoursLabel = round(((int) $validated['extend_minutes']) / 60, 2);
        $auditMsg = "[Hourly Extension: +{$hoursLabel}h to " . $newCheckOutAt->format('Y-m-d H:i:s') . ($userName ? " by {$userName}" : '') . ' on ' . now()->format('Y-m-d H:i:s') . ']';
        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        try {
            BookingRoomAvailability::withRoomLocks([(int) $roomId], function () use ($booking, $lastSegment, $roomId, $planId, $currentCheckOutAt, $newCheckOutAt, $newCheckOutDate, $newTotal, $notes) {
                $this->lockBookingForChange($booking);
                $taken = BookingSegment::query()
                    ->where('room_id', $roomId)
                    ->where('booking_id', '!=', $booking->id)
                    ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
                    ->where('check_in_at', '<', $newCheckOutAt)
                    ->where('check_out_at', '>', $currentCheckOutAt)
                    ->exists();
                if ($taken) {
                    throw ValidationException::withMessages([
                        'extend_minutes' => 'The room was just booked for the extra time. Reload and try again.',
                    ]);
                }
                $blocked = RoomStatusBlock::where('room_id', '=', $roomId, 'and')
                    ->where('is_active', true)
                    ->whereIn('status', ['on_hold', 'maintenance'])
                    ->where('start_date', '<', $newCheckOutDate)
                    ->where('end_date', '>', $currentCheckOutAt->toDateString())
                    ->exists();
                if ($blocked) {
                    throw ValidationException::withMessages([
                        'extend_minutes' => 'The room is on hold or under maintenance during the extra time.',
                    ]);
                }

                $booking->update([
                    'rate_plan_id' => $planId,
                    'check_out_at' => $newCheckOutAt,
                    'check_out' => $newCheckOutDate,
                    'total_price' => $newTotal,
                    'notes' => $notes,
                ]);

                $lastSegment->update([
                    'rate_plan_id' => $planId,
                    'check_out_at' => $newCheckOutAt,
                    'check_out' => $newCheckOutDate,
                    'total_price' => $newTotal,
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json($booking->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup', 'segments.room']));
    }

    /**
     * Preview hourly extension totals (same pricing as {@see extendHourlyReservation}) without persisting.
     */
    public function previewHourlyExtension(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        $validated = $request->validate([
            'extend_minutes' => 'required|integer|min:1',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
        ]);

        if (($booking->booking_unit ?? 'day') !== 'hour_package') {
            return response()->json([
                'message' => 'This preview is only for hourly package bookings.',
            ], 422);
        }

        $checkInAt = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $currentCheckOutAt = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();
        $newCheckOutAt = $currentCheckOutAt->copy()->addMinutes((int) $validated['extend_minutes']);

        $lastSegment = $booking->segments()->orderBy('check_out_at', 'desc')->first();
        $roomId = (int) ($lastSegment?->room_id ?? $booking->room_id ?? 0);
        if ($roomId <= 0) {
            return response()->json(['message' => 'No room assigned for this booking.'], 422);
        }

        $hasConflict = false;
        $conflictMessage = null;
        $conflictSegment = BookingSegment::with(['booking.room.roomType'])
            ->where('room_id', $roomId)
            ->where('booking_id', '!=', $booking->id)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $newCheckOutAt)
            ->where('check_out_at', '>', $currentCheckOutAt)
            ->orderBy('check_in_at', 'asc')
            ->first();

        if ($conflictSegment?->booking) {
            $hasConflict = true;
            $conflictMessage = 'Room conflict for the extended window — move the overlapping reservation or shorten this stay.';
        }

        $room = Room::with(['roomType.tax', 'roomType.ratePlans'])->findOrFail($roomId);
        $planId = (int) ($validated['rate_plan_id'] ?? $booking->rate_plan_id ?? 0);
        if ($planId <= 0) {
            return response()->json(['message' => 'rate_plan_id is required for hourly extension preview.'], 422);
        }

        $extraBeds = (int) ($booking->extra_beds_count ?? 0);
        $hourlyAges = is_array($booking->child_ages) ? $booking->child_ages : null;
        $calcCurrent = $this->computeHourlyPackageTotal(
            $room,
            $planId,
            $checkInAt,
            $currentCheckOutAt,
            $extraBeds,
            (int) ($booking->adults_count ?? 1),
            (int) ($booking->children_count ?? 0),
            $hourlyAges,
        );
        if (! $calcCurrent['ok']) {
            return response()->json(['message' => $calcCurrent['message']], 422);
        }

        $calcNew = $this->computeHourlyPackageTotal(
            $room,
            $planId,
            $checkInAt,
            $newCheckOutAt,
            $extraBeds,
            (int) ($booking->adults_count ?? 1),
            (int) ($booking->children_count ?? 0),
            $hourlyAges,
        );
        if (! $calcNew['ok']) {
            return response()->json(['message' => $calcNew['message']], 422);
        }

        $currentTotal = (float) $calcCurrent['total'];
        $newTotal = (float) $calcNew['total'];
        $delta = round($newTotal - $currentTotal, 2);

        return response()->json([
            'current_total' => $currentTotal,
            'new_total' => $newTotal,
            'delta' => $delta,
            'new_check_out_at' => $newCheckOutAt->toIso8601String(),
            'has_conflict' => $hasConflict,
            'conflict_message' => $conflictMessage,
        ]);
    }

    /**
     * Handle Split Stay: Add a new segment to an existing booking.
     */
    public function splitStay(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if (in_array($booking->status, ['cancelled', 'checked_out'], true)) {
            return response()->json([
                'message' => 'Cannot split a ' . str_replace('_', ' ', $booking->status) . ' reservation.',
            ], 422);
        }

        $validated = $request->validate([
            'new_room_id' => 'required|exists:rooms,id',
            'new_check_out' => 'required|date|after:' . $booking->check_out,
            'complimentary_upgrade' => 'nullable|boolean',
        ]);

        $oldCheckOut = $booking->check_out;
        $newCheckOut = $validated['new_check_out'];
        $newRoomId = $validated['new_room_id'];
        $complimentaryUpgrade = ! empty($validated['complimentary_upgrade']);

        // Price the new segment night by night (seasons, extra beds, meals, GST setting) like create and extend.
        $newRoom = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons'])->findOrFail($newRoomId);

        $rt = $newRoom->roomType;
        // Try to match existing rate plan if possible
        $ratePlan = $booking->rate_plan_id ? $rt->ratePlans->find($booking->rate_plan_id) : $rt->ratePlans->first();

        $segmentTotal = $complimentaryUpgrade
            ? 0.0
            : $this->computeDayStayChargesForRange(
                $booking,
                $newRoom,
                Carbon::parse($oldCheckOut)->toDateString(),
                Carbon::parse($newCheckOut)->toDateString(),
            )['total'];

        $oldCheckOutCarbon = Carbon::parse($oldCheckOut)->startOfDay();
        $currentRoomId = (int) ($booking->segments()->orderByDesc('check_out')->orderByDesc('check_out_at')->value('room_id') ?: $booking->room_id);
        if ((int) $newRoomId === $currentRoomId) {
            return response()->json([
                'message' => 'Select a different room for the extended nights than the room the guest is in now.',
            ], 422);
        }

        $sellStatus = $booking->status === 'checked_in' ? 'checked_in' : 'confirmed';

        try {
            DB::transaction(function () use ($booking, $newRoom, $newRoomId, $newCheckOut, $oldCheckOut, $oldCheckOutCarbon, $ratePlan, $segmentTotal, $complimentaryUpgrade, $rt, $sellStatus) {
                BookingRoomAvailability::lockAndAssertSellable(
                    [(int) $newRoomId],
                    $oldCheckOutCarbon,
                    Carbon::parse($newCheckOut)->startOfDay(),
                    $sellStatus,
                    (int) $booking->id,
                );

                // End the stay in the current room at $oldCheckOut (same as booking.check_out) before adding
                // the new room segment. Otherwise the previous segment can still extend into the extension
                // window and overlap the new segment — same guest on two rooms for the same dates / OVERLAP.
                $lastSegment = $booking->segments()->orderByDesc('check_out')->orderByDesc('check_out_at')->first();
                if (! $lastSegment) {
                    $lastSegment = BookingSegment::create([
                        'booking_id' => $booking->id,
                        'room_id' => $booking->room_id,
                        'check_in' => $booking->check_in,
                        'check_out' => $oldCheckOutCarbon->toDateString(),
                        'check_in_at' => $booking->check_in_at ?? Carbon::parse($booking->check_in)->startOfDay(),
                        'check_out_at' => $oldCheckOutCarbon,
                        'rate_plan_id' => $booking->rate_plan_id,
                        'adults_count' => $booking->adults_count,
                        'children_count' => $booking->children_count,
                        'extra_beds_count' => $booking->extra_beds_count,
                        'total_price' => $booking->total_price,
                        'status' => $booking->status === 'checked_in' ? 'checked_in' : 'confirmed',
                    ]);
                } else {
                    $lastSegment->update([
                        'check_out' => $oldCheckOutCarbon->toDateString(),
                        'check_out_at' => $oldCheckOutCarbon,
                    ]);
                }

                // Add segment — inherit the parent booking's status so a checked_in guest
                // shows as "occupied" (not "reserved") on the room chart for the new room.
                $segmentStatus = $booking->status === 'checked_in' ? 'checked_in' : 'confirmed';

                BookingSegment::create([
                    'booking_id' => $booking->id,
                    'room_id' => $newRoomId,
                    'check_in' => $oldCheckOutCarbon->toDateString(),
                    'check_out' => $newCheckOut,
                    'check_in_at' => $oldCheckOutCarbon,
                    'check_out_at' => Carbon::parse($newCheckOut)->startOfDay(),
                    'rate_plan_id' => $ratePlan ? $ratePlan->id : null,
                    'adults_count' => $booking->adults_count,
                    'children_count' => $booking->children_count,
                    'extra_beds_count' => $booking->extra_beds_count,
                    'total_price' => $segmentTotal,
                    'status' => $segmentStatus,
                ]);

                $user = Auth::user();
                $userName = $user ? $user->name : '';
                $auditMsg = $complimentaryUpgrade
                    ? "[Split Stay: Complimentary upgrade to Room #{$newRoom->room_number} ({$rt->name}) from {$oldCheckOut} to {$newCheckOut}" . ($userName ? " by {$userName}" : '') . ' on ' . now()->format('Y-m-d H:i:s') . ']'
                    : "[Split Stay: Room #{$newRoom->room_number} from {$oldCheckOut} to {$newCheckOut}" . ($userName ? " by {$userName}" : '') . ' on ' . now()->format('Y-m-d H:i:s') . ']';
                $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

                $booking->update([
                    'check_out' => $newCheckOut,
                    'check_out_at' => Carbon::parse($newCheckOut)->startOfDay(),
                    'total_price' => (float) $booking->total_price + $segmentTotal,
                    'notes' => $notes,
                ]);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: 'Room is not available for the selected dates.',
            ], 422);
        }

        $fresh = $booking->fresh()->load(['segments.room.roomType', 'creator:id,name']);

        return response()->json($fresh->toArray() + ['doorloom' => HotelApiSync::syncBooking($fresh)]);
    }

    public function reservationVoucher(Request $request, Booking $booking)
    {
        $this->allowReservationBillingExport();

        $booking->load(['room.roomType.tax', 'creator:id,name', 'bookingGroup']);

        $guestName = trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''));
        $guestName = $guestName !== '' ? $guestName : 'Guest';

        $ci = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $co = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();
        $createdAt = $booking->created_at ? Carbon::parse($booking->created_at) : now();

        $grand = (float) ($booking->total_price ?? 0);
        $paid = (float) ($booking->deposit_amount ?? 0);
        $balance = max(0, $grand - $paid);

        $roomNo = (string) ($booking->room?->room_number ?? '-');
        $roomType = (string) ($booking->room?->roomType?->name ?? '-');
        $roomLabel = $roomType . ' / ' . $roomNo;

        $adults = (int) ($booking->adults_count ?? 1);
        $children = (int) ($booking->children_count ?? 0);
        $personsLabel = $adults . ' (A) / ' . $children . ' (C)';
        $nights = max(1, (int) $ci->copy()->startOfDay()->diffInDays($co->copy()->startOfDay()));

        $contact = trim(((string) ($booking->phone ?? '')) . (($booking->email ?? null) ? ' · ' . (string) $booking->email : ''));
        $contact = $contact !== '' ? $contact : '—';

        $defaults = Setting::getReceiptDefaults();
        $hotelName = Setting::get('invoice_company_name', 'Hotel');
        if ($hotelName === 'Hotel' && ! empty($defaults['address'])) {
            $first = explode("\n", (string) $defaults['address'])[0];
            $hotelName = trim($first) !== '' ? trim($first) : 'Hotel';
        }
        $hotelAddress = (string) Setting::get('invoice_address', (string) ($defaults['address'] ?? ''));
        $hotelGstin = (string) Setting::get('invoice_gstin', '');

        $bankCompanyName = (string) Setting::get('invoice_bank_legal_name', $hotelName);
        $bankLines = [];
        $pairs = [
            ['Bank name', (string) Setting::get('invoice_bank_name', '')],
            ['Account no.', (string) Setting::get('invoice_bank_account_no', '')],
            ['IFSC', (string) Setting::get('invoice_bank_ifsc', '')],
            ['Branch', (string) Setting::get('invoice_bank_branch', '')],
            ['SWIFT / BIC', (string) Setting::get('invoice_bank_swift', '')],
        ];
        foreach ($pairs as [$label, $value]) {
            $v = trim((string) $value);
            if ($v !== '') {
                $bankLines[] = $label . ': ' . $v;
            }
        }
        $bankDetails = implode("\n", $bankLines);

        $notes = trim((string) preg_replace('/\[[^\]]+\]/', '', (string) ($booking->notes ?? '')));
        $notes = $notes !== '' ? $notes : '—';

        $extraCharges = (float) ($booking->extra_charges ?? 0);
        $roomAmount = max(0.0, $grand - $extraCharges);

        $fmt = static fn(float $n): string => number_format(round($n, 2), 2, '.', '');

        $data = [
            'hotelName' => $hotelName,
            'hotelAddress' => $hotelAddress,
            'hotelGstin' => $hotelGstin,
            'resNo' => BookingNumber::for($booking),
            'bookedOn' => $createdAt->format('d/m/Y'),
            'guestName' => $guestName,
            'contact' => $contact,
            'roomLabel' => $roomLabel,
            'personsLabel' => $personsLabel,
            'arrivalStr' => $ci->format('d/m/Y h:i A'),
            'departureStr' => $co->format('d/m/Y h:i A'),
            'nights' => (string) $nights,
            'currency' => 'INR',
            'roomAmount' => $roomAmount,
            'extraCharges' => $extraCharges,
            'grand' => $grand,
            'paid' => $paid,
            'balance' => $balance,
            'notes' => $notes,
            'receptionName' => $booking->creator?->name ?? '—',
            'footerDate' => Carbon::now()->format('d/m/Y h:i:s A'),
            'bankCompanyName' => $bankCompanyName,
            'bankDetails' => $bankDetails,
            'fmt' => $fmt,
        ];

        $pdf = Pdf::loadView('bookings.reservation_voucher', $data)->setPaper('a4', 'portrait');

        return $pdf->download('Reservation_Voucher_' . $booking->id . '.pdf');
    }

    public function reservationBilling(Request $request, Booking $booking)
    {
        $this->allowReservationBillingExport();

        $groupScope = $request->query('scope') === 'group';
        if ($groupScope && ! $booking->booking_group_id) {
            return response()->json(['message' => 'This booking is not part of a group.'], 422);
        }

        $data = $groupScope ? ReservationInvoiceViewData::buildGroup($booking) : ReservationInvoiceViewData::build($booking);
        $pdf = Pdf::loadView('bookings.reservation_invoice', $data)->setPaper('a4', 'portrait');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - 80,
            $canvas->get_height() - 18,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'),
            6.5
        );
        if ($groupScope) {
            $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($booking->bookingGroup?->name ?: $booking->booking_group_id));

            return $pdf->download(($data['isIssued'] ? 'Group_Invoice_' : 'Group_Proforma_') . $safeName . '.pdf');
        }
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($data['isIssued'] ? $data['invoiceNo'] : $data['resNo']));

        return $pdf->download(($data['isIssued'] ? 'Invoice_' : 'Proforma_') . $safeName . '.pdf');
    }

    /**
     * POS orders with room charge posted to this booking (for reception checkout breakdown).
     */
    public function folioPostings(Booking $booking)
    {
        $this->allowReservationRead();

        $orders = PosOrder::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'paid')
            ->with([
                'restaurant:id,name',
                'payments',
            ])
            ->orderByDesc('closed_at')
            ->get();

        $items = [];
        $folioCgst = 0.0;
        $folioSgst = 0.0;
        $folioIgst = 0.0;
        $folioVat = 0.0;

        foreach ($orders as $order) {
            $roomCharge = (float) $order->payments->where('method', 'room_charge')->sum('amount');
            if ($roomCharge <= 0) {
                continue;
            }
            $tot = max((float) $order->total_amount, 0.0001);
            $ratio = min(1.0, $roomCharge / $tot);
            $cgst = (float) ($order->cgst_amount ?? 0) * $ratio;
            $sgst = (float) ($order->sgst_amount ?? 0) * $ratio;
            $igst = (float) ($order->igst_amount ?? 0) * $ratio;
            $vat = (float) ($order->vat_tax_amount ?? 0) * $ratio;
            $folioCgst += $cgst;
            $folioSgst += $sgst;
            $folioIgst += $igst;
            $folioVat += $vat;

            $items[] = [
                'booking_id' => (int) $booking->id,
                'pos_order_id' => $order->id,
                'outlet' => $order->restaurant?->name ?? 'Outlet',
                'amount' => round($roomCharge, 2),
                'posted_at' => $order->closed_at?->toIso8601String(),
                'order_type' => $order->order_type,
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
                'vat' => round($vat, 2),
            ];
        }

        $penalties = BookingInspectionChargeLines::penaltiesMap();

        $ledgerRows = BookingExtraCharge::query()
            ->where('booking_id', '=', $booking->id, 'and')
            ->orderBy('id')
            ->get($this->bookingExtraChargeSelectColumns());

        $ledgerHasDescription = Schema::hasColumn('booking_extra_charges', 'description');

        $ledgerItems = $ledgerRows->map(function (BookingExtraCharge $line) use ($penalties, $ledgerHasDescription) {
            $unit = round((float) ($line->unit_amount ?? 0), 2);
            $total = round((float) ($line->total_amount ?? 0), 2);
            $meta = is_array($line->meta) ? $line->meta : [];
            if ($line->source === 'inspection' && $total < 0.0001) {
                $invItemId = (int) ($meta['inventory_item_id'] ?? 0);
                $penKey = trim((string) ($meta['penalty_key'] ?? ''));
                if (isset($meta['unit_damage_charge']) && is_numeric($meta['unit_damage_charge'])) {
                    $unit = round(max(0.0, (float) $meta['unit_damage_charge']), 2);
                } else {
                    [$unit] = CheckoutInspectionPenaltyAmount::resolveForAsset($invItemId, $penKey, $penalties);
                }
                $qty = max(1.0, (float) ($line->qty ?? 1));
                $total = round($unit * $qty, 2);
            }

            $description = null;
            if ($ledgerHasDescription && $line->description !== null && trim((string) $line->description) !== '') {
                $description = (string) $line->description;
            } elseif (isset($meta['asset_status'])) {
                $description = 'Status: ' . (string) $meta['asset_status'];
            }

            return [
                'id' => (int) $line->id,
                'source' => (string) $line->source,
                'kind' => (string) $line->kind,
                'label' => (string) $line->label,
                'description' => $description,
                'qty' => round((float) ($line->qty ?? 1), 2),
                'unit_amount' => $unit,
                'amount' => $total,
                'posted_at' => $line->created_at?->toIso8601String(),
                'meta' => $line->meta,
            ];
        })->values()->all();

        $hasInspectionLedger = collect($ledgerItems)->contains(
            fn(array $row) => ($row['source'] ?? '') === 'inspection' && (float) ($row['amount'] ?? 0) > 0.0001,
        );
        if (! $hasInspectionLedger) {
            foreach (BookingInspectionChargeLines::snapshotFallbackLines($booking, $penalties) as $line) {
                $ledgerItems[] = $this->ledgerItemFromInspectionChargeLine($line);
            }
        }

        return response()->json([
            'extra_charges_total' => (float) ($booking->extra_charges ?? 0),
            'folio_tax' => [
                'cgst' => round($folioCgst, 2),
                'sgst' => round($folioSgst, 2),
                'igst' => round($folioIgst, 2),
                'vat' => round($folioVat, 2),
            ],
            'items' => $items,
            'ledger_items' => $ledgerItems,
        ]);
    }

    /**
     * Guest payment / refund ledger for mid-stay cashiering and history.
     */
    public function listPayments(Booking $booking)
    {
        $this->allowReservationRead();

        if (! BookingPaymentLedger::enabled()) {
            return response()->json([
                'items' => [],
                'totals' => BookingPaymentLedger::totals($booking),
                'booking' => [
                    'id' => $booking->id,
                    'deposit_amount' => (float) ($booking->deposit_amount ?? 0),
                    'refund_amount' => (float) ($booking->refund_amount ?? 0),
                    'payment_status' => $booking->payment_status,
                    'payment_method' => $booking->payment_method,
                ],
            ]);
        }

        $rows = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->with(['receiver:id,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $items = $rows->map(function (BookingPayment $p) {
            return [
                'id' => $p->id,
                'booking_id' => (int) $p->booking_id,
                'type' => $p->type,
                'amount' => round((float) $p->amount, 2),
                'signed_amount' => round($p->signedAmount(), 2),
                'method' => $p->method,
                'reference_no' => $p->reference_no,
                'notes' => $p->notes,
                'source' => $p->source,
                'meta' => $p->meta,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'received_by' => $p->received_by,
                'received_by_name' => $p->receiver?->name,
                'voided_at' => $p->voided_at?->toIso8601String(),
                'void_reason' => $p->void_reason,
                'is_voided' => $p->voided_at !== null,
            ];
        })->values();

        return response()->json([
            'items' => $items,
            'totals' => BookingPaymentLedger::totals($booking),
            'by_method' => BookingPaymentLedger::netByMethod($booking),
            'booking' => [
                'id' => $booking->id,
                'deposit_amount' => (float) ($booking->deposit_amount ?? 0),
                'refund_amount' => (float) ($booking->refund_amount ?? 0),
                'payment_status' => $booking->payment_status,
                'payment_method' => $booking->payment_method,
                'refund_method' => $booking->refund_method,
            ],
        ]);
    }

    /**
     * Record one payment/refund or a split multi-tender payment.
     */
    public function storePayment(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();

        if (! BookingPaymentLedger::enabled()) {
            return response()->json(['message' => 'Payment ledger is not available.'], 503);
        }

        if (in_array($booking->status, ['cancelled'], true)) {
            return response()->json(['message' => 'Cannot post payments on a cancelled reservation.'], 422);
        }

        $validated = $request->validate([
            'type' => 'nullable|string|in:payment,refund',
            'amount' => 'nullable|numeric|min:0.01',
            'method' => 'nullable|string|in:cash,card,upi,bank_transfer',
            'reference_no' => 'nullable|string|max:128',
            'notes' => 'nullable|string|max:500',
            'source' => 'nullable|string|in:deposit,checkout,manual',
            'bill_total' => 'nullable|numeric|min:0',
            'tenders' => 'nullable|array|min:1',
            'tenders.*.amount' => 'required_with:tenders|numeric|min:0.01',
            'tenders.*.method' => 'required_with:tenders|string|in:cash,card,upi,bank_transfer',
            'tenders.*.reference_no' => 'nullable|string|max:128',
            'tenders.*.notes' => 'nullable|string|max:500',
        ]);

        // A client bill_total is accepted for compatibility but not trusted.
        $billTotal = round($this->effectiveBookingGrand($booking), 2);

        $source = (string) ($validated['source'] ?? 'deposit');
        if ($booking->status === 'checked_out' && ($validated['type'] ?? 'payment') === 'payment') {
            return response()->json(['message' => 'Cannot collect payments after check-out.'], 422);
        }

        if (! empty($validated['tenders'])) {
            if (($validated['type'] ?? 'payment') === 'refund') {
                return response()->json(['message' => 'Split tenders are only supported for payments.'], 422);
            }
            $rows = BookingPaymentLedger::recordSplitPayments(
                $booking,
                $validated['tenders'],
                $source === 'checkout' ? 'checkout' : 'deposit',
                $billTotal,
            );
            $booking->refresh();

            return response()->json([
                'message' => 'Payments recorded.',
                'payments' => $rows,
                'booking' => $booking->fresh(['room.roomType', 'segments', 'bookingGroup']),
                'totals' => BookingPaymentLedger::totals($booking),
            ], 201);
        }

        $type = (string) ($validated['type'] ?? BookingPayment::TYPE_PAYMENT);
        $amount = round((float) ($validated['amount'] ?? 0), 2);
        if ($amount <= 0.004) {
            return response()->json(['message' => 'Amount is required.'], 422);
        }
        $method = (string) ($validated['method'] ?? '');
        if ($method === '') {
            return response()->json(['message' => 'Payment method is required.'], 422);
        }

        $attrs = [
            'amount' => $amount,
            'method' => $method,
            'reference_no' => $validated['reference_no'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'source' => $source,
            'bill_total' => $billTotal,
            'allow_closed' => $type === BookingPayment::TYPE_REFUND,
        ];

        $row = $type === BookingPayment::TYPE_REFUND
            ? DB::transaction(function () use ($booking, $attrs) {
                $row = BookingPaymentLedger::recordRefund($booking, $attrs);
                if ($booking->status === 'checked_out') {
                    app(BookingRefundPoster::class)->post($booking->fresh(['room.roomType.tax']), $row, auth()->id());
                }

                return $row;
            })
            : BookingPaymentLedger::recordPayment($booking, $attrs);

        $booking->refresh();

        return response()->json([
            'message' => $type === BookingPayment::TYPE_REFUND ? 'Refund recorded.' : 'Payment recorded.',
            'payment' => $row,
            'booking' => $booking->fresh(['room.roomType', 'segments', 'bookingGroup']),
            'totals' => BookingPaymentLedger::totals($booking),
        ], 201);
    }

    public function voidPayment(Request $request, Booking $booking, BookingPayment $payment)
    {
        $this->allowReservationEdit();

        if ((int) $payment->booking_id !== (int) $booking->id) {
            return response()->json(['message' => 'Payment does not belong to this booking.'], 404);
        }

        if (in_array($booking->status, ['checked_out', 'cancelled'], true)) {
            return response()->json(['message' => 'Cannot void payments after check-out or cancellation.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'bill_total' => 'nullable|numeric|min:0',
        ]);

        $billTotal = round($this->effectiveBookingGrand($booking), 2);

        $row = BookingPaymentLedger::voidPayment($payment, $validated['reason'] ?? null, $billTotal);
        $booking->refresh();

        return response()->json([
            'message' => 'Payment voided.',
            'payment' => $row,
            'booking' => $booking->fresh(['room.roomType', 'segments', 'bookingGroup']),
            'totals' => BookingPaymentLedger::totals($booking),
        ]);
    }

    /**
     * Checkout inspection charges posted to the booking (minibar consumption + asset penalties).
     */
    public function inspectionCharges(Booking $booking)
    {
        $this->allowReservationRead();

        $penalties = BookingInspectionChargeLines::penaltiesMap();

        $lines = BookingExtraCharge::query()
            ->where('booking_id', $booking->id)
            ->where('source', 'inspection')
            ->orderBy('id')
            ->get(['id', 'kind', 'label', 'qty', 'unit_amount', 'total_amount', 'meta']);

        if ($lines->isEmpty()) {
            $lines = collect(BookingInspectionChargeLines::snapshotFallbackLines($booking, $penalties));
        }

        $linesOut = $lines->map(function ($line) use ($penalties) {
            return BookingInspectionChargeLines::enrichLineForDisplay($line, $penalties);
        })->values()->all();

        $inspector = $this->checkoutInspectionInspectorForBooking($booking);

        return response()->json([
            'booking_id' => (int) $booking->id,
            'lines' => $linesOut,
            'inspector' => $inspector,
        ]);
    }

    /**
     * @return array{user_id: int|null, name: string|null}
     */
    private function checkoutInspectionInspectorForBooking(Booking $booking): array
    {
        $roomIds = array_values(array_unique(array_filter(array_merge(
            [(int) $booking->room_id],
            $booking->segments()->pluck('room_id')->map(fn($id) => (int) $id)->all()
        ), static fn(int $id): bool => $id > 0)));

        if ($roomIds === []) {
            return ['user_id' => null, 'name' => null];
        }

        $blocks = RoomStatusBlock::query()
            ->whereIn('room_id', $roomIds, 'and', false)
            ->where('status', '=', 'inspected')
            ->whereNotNull('inspection_snapshot')
            ->orderByDesc('id')
            ->get();

        foreach ($blocks as $block) {
            $snap = $block->inspection_snapshot;
            if (! is_array($snap) || ! empty($snap['cleared'])) {
                continue;
            }
            $snapBookingId = isset($snap['booking_id']) ? (int) $snap['booking_id'] : null;
            if ($snapBookingId !== null && $snapBookingId === (int) $booking->id) {
                $snap = CheckoutInspectionInspector::enrichSnapshot($snap) ?? $snap;
                $uid = isset($snap['inspector_user_id']) ? (int) $snap['inspector_user_id'] : 0;
                $name = CheckoutInspectionInspector::displayNameForUserId(
                    $uid > 0 ? $uid : null,
                    isset($snap['inspector_name']) ? (string) $snap['inspector_name'] : null,
                );

                return [
                    'user_id' => $uid > 0 ? $uid : null,
                    'name' => $name,
                ];
            }
        }

        if ($booking->status === 'checked_in') {
            foreach ($blocks as $block) {
                if (! $block->is_active || $block->status !== 'inspected') {
                    continue;
                }
                $snap = $block->inspection_snapshot;
                if (! is_array($snap) || ! empty($snap['cleared'])) {
                    continue;
                }
                if (! in_array((int) $block->room_id, $roomIds, true)) {
                    continue;
                }
                $snap = CheckoutInspectionInspector::enrichSnapshot($snap) ?? $snap;
                $uid = isset($snap['inspector_user_id']) ? (int) $snap['inspector_user_id'] : 0;
                $name = CheckoutInspectionInspector::displayNameForUserId(
                    $uid > 0 ? $uid : null,
                    isset($snap['inspector_name']) ? (string) $snap['inspector_name'] : null,
                );

                return [
                    'user_id' => $uid > 0 ? $uid : null,
                    'name' => $name,
                ];
            }
        }

        return ['user_id' => null, 'name' => null];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function ledgerItemFromInspectionChargeLine(array $line): array
    {
        $qty = max(1.0, (float) ($line['qty'] ?? 1));
        $unit = round((float) ($line['resolved_unit_amount'] ?? $line['unit_amount'] ?? 0), 2);
        $total = round((float) ($line['resolved_total_amount'] ?? $line['total_amount'] ?? ($unit * $qty)), 2);
        $meta = is_array($line['meta'] ?? null) ? $line['meta'] : [];

        return [
            'id' => (int) ($line['id'] ?? 0),
            'source' => 'inspection',
            'kind' => (string) ($line['kind'] ?? 'asset_penalty'),
            'label' => (string) ($line['label'] ?? 'Inspection charge'),
            'description' => isset($meta['asset_status']) ? 'Status: ' . (string) $meta['asset_status'] : null,
            'qty' => $qty,
            'unit_amount' => $unit,
            'amount' => $total,
            'posted_at' => null,
            'meta' => $meta,
        ];
    }

    /**
     * Line-item detail for a single POS order on this booking’s folio (reception drill-down).
     */
    public function folioOrderDetail(Booking $booking, PosOrder $order)
    {
        $this->allowReservationRead();

        if ((int) $order->booking_id !== (int) $booking->id) {
            abort(404, 'Order is not linked to this booking.');
        }

        $order->load([
            'restaurant:id,name',
            'items.menuItem.category',
            'items.combo',
            'items.variant',
            'payments' => function ($q) {
                $q->where('method', 'room_charge');
            },
        ]);

        $roomCharge = (float) $order->payments->where('method', 'room_charge')->sum('amount');

        $lines = $order->items->where('status', 'active')->values()->map(function ($i) {
            $name = $i->combo_id
                ? ($i->combo?->name ?? 'Combo')
                : ($i->menu_item_variant_id
                    ? trim(($i->menuItem?->name ?? 'Item') . ' — ' . ($i->variant?->size_label ?? ''))
                    : ($i->menuItem?->name ?? 'Item'));

            return [
                'name' => $name,
                'category' => $i->menuItem?->category?->name ?? ($i->combo_id ? 'Combo' : null),
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'line_total' => (float) $i->line_total,
                'notes' => $i->notes ? (string) $i->notes : null,
            ];
        });

        return response()->json([
            'order_id' => $order->id,
            'outlet' => $order->restaurant?->name ?? 'Outlet',
            'order_type' => $order->order_type,
            'status' => $order->status,
            'total_amount' => (float) $order->total_amount,
            'room_charge_amount' => round($roomCharge, 2),
            'opened_at' => $order->opened_at?->toIso8601String(),
            'closed_at' => $order->closed_at?->toIso8601String(),
            'order_notes' => $order->notes ? (string) $order->notes : null,
            'lines' => $lines,
        ]);
    }

    /**
     * @return list<string>
     */
    private function bookingExtraChargeSelectColumns(): array
    {
        $cols = ['id', 'source', 'kind', 'label', 'qty', 'unit_amount', 'total_amount', 'meta', 'created_at'];
        if (Schema::hasColumn('booking_extra_charges', 'description')) {
            $cols[] = 'description';
        }

        return $cols;
    }

    /**
     * Preview cancellation fee + deposit forfeit / refund settlement (pre-arrival only).
     */
    public function previewCancellation(Request $request, Booking $booking)
    {
        $this->allowReservationDelete();

        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json([
                'message' => 'Only pending or confirmed reservations can be cancelled from the room chart. In-house stays must be checked out.',
            ], 422);
        }

        $validated = $request->validate([
            'waive_fee' => 'nullable|boolean',
            'fee_override' => 'nullable|numeric|min:0',
            'additional_collected' => 'nullable|numeric|min:0',
        ]);

        $feeOverride = array_key_exists('fee_override', $validated) && $validated['fee_override'] !== null
            ? (float) $validated['fee_override']
            : null;

        $preview = BookingCancellationPolicy::preview(
            $booking,
            $feeOverride,
            $request->boolean('waive_fee', false),
            (float) ($validated['additional_collected'] ?? 0),
        );

        return response()->json($preview);
    }

    /**
     * Cancel / void a pre-arrival reservation with policy fee, deposit forfeit, and optional refund.
     */
    public function cancelReservation(Request $request, Booking $booking)
    {
        $this->allowReservationDelete();

        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json([
                'message' => 'Only pending or confirmed reservations can be cancelled. In-house stays must be checked out.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|in:'.implode(',', array_keys(BookingCancellationPolicy::REASONS)),
            'reason_notes' => 'nullable|string|max:500',
            'waive_fee' => 'nullable|boolean',
            'fee_override' => 'nullable|numeric|min:0',
            'additional_collected' => 'nullable|numeric|min:0',
            'additional_payment_method' => 'nullable|string|in:cash,card,upi,bank_transfer',
            'refund_method' => 'nullable|string|in:cash,card,upi,bank_transfer',
            'confirm_balance_waived' => 'nullable|boolean',
        ]);

        if ($validated['reason'] === 'other' && trim((string) ($validated['reason_notes'] ?? '')) === '') {
            return response()->json(['message' => 'Please add a short note when reason is Other.'], 422);
        }

        if ($validated['reason'] === 'no_show' && $this->bookingArrivalCalendarDay($booking) > Carbon::today()->toDateString()) {
            return response()->json([
                'message' => 'A reservation can only be marked as a no-show on or after its arrival date.',
            ], 422);
        }

        $feeOverride = array_key_exists('fee_override', $validated) && $validated['fee_override'] !== null
            ? (float) $validated['fee_override']
            : null;
        $waiveFee = $request->boolean('waive_fee', false);
        $additionalCollected = (float) ($validated['additional_collected'] ?? 0);

        $preview = BookingCancellationPolicy::preview(
            $booking,
            $feeOverride,
            $waiveFee,
            $additionalCollected,
        );

        if ((float) $preview['balance_due'] > 0.004 && ! $request->boolean('confirm_balance_waived', false)) {
            return response()->json([
                'message' => 'Cancellation fee exceeds deposit. Collect the balance due, or confirm waiving the remaining balance.',
                'preview' => $preview,
            ], 422);
        }

        if ((float) $preview['refund_due'] > 0.004 && empty($validated['refund_method'])) {
            return response()->json([
                'message' => 'Select how the deposit refund will be issued (cash, card, UPI, or bank transfer).',
                'preview' => $preview,
            ], 422);
        }

        if ($additionalCollected > 0.004 && empty($validated['additional_payment_method'])) {
            return response()->json([
                'message' => 'Select the payment method used to collect the remaining cancellation fee.',
                'preview' => $preview,
            ], 422);
        }

        $reasonLabel = BookingCancellationPolicy::REASONS[$validated['reason']] ?? $validated['reason'];
        if (in_array($validated['reason'], ['other', 'no_show'], true) && ! empty($validated['reason_notes'])) {
            $reasonLabel .= ' — '.trim((string) $validated['reason_notes']);
        }

        $user = Auth::user();
        $userName = $user ? (string) $user->name : '';
        $timestamp = now()->format('Y-m-d H:i:s');
        $byPart = $userName !== '' ? " by {$userName}" : '';

        $effectiveFee = (float) $preview['effective_fee'];
        $refundDue = (float) $preview['refund_due'];
        $forfeited = (float) $preview['forfeited_from_deposit'];
        $balanceDue = (float) $preview['balance_due'];
        $balanceWaived = $balanceDue > 0.004 && $request->boolean('confirm_balance_waived', false);

        $depositForSettle = round((float) $preview['existing_deposit'] + $additionalCollected, 2);

        // Waiving unpaid balance: retain only what deposit (+ collected) covers.
        if ($balanceWaived) {
            $effectiveFee = min($effectiveFee, $depositForSettle);
            $settled = BookingCancellationPolicy::settle($depositForSettle, $effectiveFee);
            $refundDue = $settled['refund_due'];
            $forfeited = $settled['forfeited_from_deposit'];
            $paymentStatus = $settled['payment_status_after'];
        } else {
            $paymentStatus = (string) $preview['payment_status_after'];
        }

        $feeNote = $waiveFee
            ? 'Fee waived'
            : sprintf('Fee ₹%s (forfeit ₹%s)', number_format($effectiveFee, 2, '.', ''), number_format($forfeited, 2, '.', ''));
        if ($refundDue > 0.004) {
            $feeNote .= sprintf(
                ' | Refund ₹%s via %s',
                number_format($refundDue, 2, '.', ''),
                (string) $validated['refund_method']
            );
        }
        if ($additionalCollected > 0.004) {
            $feeNote .= sprintf(
                ' | Collected ₹%s via %s',
                number_format($additionalCollected, 2, '.', ''),
                (string) ($validated['additional_payment_method'] ?? '')
            );
        }
        if ($balanceWaived) {
            $feeNote .= sprintf(' | Unpaid balance ₹%s waived', number_format($balanceDue, 2, '.', ''));
        }

        $auditMsg = "[Cancellation: {$reasonLabel} | {$feeNote}{$byPart} on {$timestamp}]";
        $notes = $booking->notes ? $booking->notes."\n".$auditMsg : $auditMsg;

        DB::transaction(function () use (
            $booking,
            $validated,
            $notes,
            $effectiveFee,
            $refundDue,
            $additionalCollected,
            $paymentStatus,
            $forfeited,
            $balanceWaived,
        ) {
            $this->lockBookingForChange($booking);
            if (BookingPaymentLedger::enabled()) {
                if ($additionalCollected > 0.004) {
                    BookingPaymentLedger::recordPayment($booking, [
                        'amount' => $additionalCollected,
                        'method' => (string) ($validated['additional_payment_method'] ?? 'cash'),
                        'source' => 'cancellation',
                        'notes' => 'Collected toward cancellation fee',
                        'meta' => ['cancellation_fee' => $effectiveFee],
                    ]);
                    $booking->refresh();
                }
                if ($refundDue > 0.004) {
                    BookingPaymentLedger::recordRefund($booking, [
                        'amount' => $refundDue,
                        'method' => (string) ($validated['refund_method'] ?? 'cash'),
                        'source' => 'cancellation',
                        'notes' => 'Deposit refund on cancellation',
                        'meta' => [
                            'cancellation_fee' => $effectiveFee,
                            'forfeited' => $forfeited,
                            'balance_waived' => $balanceWaived,
                        ],
                    ]);
                    $booking->refresh();
                } elseif ($forfeited > 0.004) {
                    // Deposit retained as fee — no cash movement; annotate via adjustment meta = 0 signed? Skip.
                    // Keep deposit on file; payment_status forced below.
                }
            }

            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['reason'],
                'cancellation_notes' => isset($validated['reason_notes'])
                    ? (trim((string) $validated['reason_notes']) !== '' ? trim((string) $validated['reason_notes']) : null)
                    : null,
                'cancellation_fee_amount' => $effectiveFee,
                'payment_status' => $paymentStatus,
                'notes' => $notes,
            ]);

            // When ledger did not run (or no cash movement), still persist scalars for cancel settlement.
            if (! BookingPaymentLedger::enabled()) {
                $newDeposit = round((float) ($booking->deposit_amount ?? 0) + $additionalCollected, 2);
                $booking->update([
                    'deposit_amount' => $newDeposit,
                    'refund_amount' => round((float) ($booking->refund_amount ?? 0) + max(0.0, $refundDue), 2),
                    'refund_method' => $refundDue > 0.004 ? ($validated['refund_method'] ?? null) : $booking->refund_method,
                    'payment_method' => $additionalCollected > 0.004
                        ? ($validated['additional_payment_method'] ?? $booking->payment_method)
                        : $booking->payment_method,
                ]);
            }

            $booking->segments()->update(['status' => 'cancelled']);

            $allRoomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->unique();

            // Release any active holds tied to this booking window so inventory is sellable.
            RoomStatusBlock::query()
                ->whereIn('room_id', $allRoomIds->all())
                ->where('is_active', true)
                ->where('status', 'on_hold')
                ->where('start_date', '<', Carbon::parse($booking->check_out)->toDateString())
                ->where('end_date', '>', Carbon::parse($booking->check_in)->toDateString())
                ->update(['is_active' => false]);

            // Leave rooms alone that another in-house guest or an active block (today) still owns.
            $today = Carbon::today()->toDateString();
            $roomsStillInUse = BookingSegment::query()
                ->whereIn('room_id', $allRoomIds->all())
                ->where('booking_id', '!=', $booking->id)
                ->where('status', 'checked_in')
                ->pluck('room_id')
                ->merge(
                    RoomStatusBlock::query()
                        ->whereIn('room_id', $allRoomIds->all())
                        ->where('is_active', true)
                        ->where('start_date', '<=', $today)
                        ->where('end_date', '>', $today)
                        ->pluck('room_id')
                );
            Room::whereIn('id', $allRoomIds->diff($roomsStillInUse)->all(), 'and', false)->update(['status' => 'available']);
        });

        $booking->refresh()->load(['room.roomType', 'segments', 'bookingGroup']);

        HousekeepingStateUpdated::dispatchIfEnabled(
            $booking->segments()->pluck('room_id')->push($booking->room_id)->unique()->values()->all(),
            'booking_cancelled',
        );

        return response()->json([
            'message' => 'Reservation cancelled.',
            'doorloom' => HotelApiSync::syncBooking($booking),
            'booking' => $booking,
            'settlement' => [
                'cancellation_fee' => $effectiveFee,
                'forfeited_from_deposit' => $forfeited,
                'refund_amount' => $refundDue,
                'refund_method' => $refundDue > 0.004 ? ($validated['refund_method'] ?? null) : null,
                'additional_collected' => $additionalCollected,
                'balance_waived' => $balanceWaived,
                'payment_status' => $paymentStatus,
            ],
        ]);
    }

    public function destroy(Booking $booking)
    {
        $this->allowReservationDelete();

        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json([
                'message' => 'Only pending or confirmed reservations can be deleted. Use cancel or check-out instead.',
            ], 422);
        }
        $hasMoney = (float) ($booking->deposit_amount ?? 0) > 0.004
            || (BookingPaymentLedger::enabled() && BookingPayment::where('booking_id', $booking->id)->exists());
        if ($hasMoney) {
            return response()->json([
                'message' => 'This reservation has payments. Cancel it so the money is settled through the cancellation policy.',
            ], 422);
        }

        // For split stays, booking->room may not reflect all rooms used. Fall back safely.
        $allRoomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->unique();
        Room::whereIn('id', $allRoomIds, 'and', false)->update(['status' => 'available']);
        Booking::destroy($booking->id);
        HotelApiSync::afterRoomsFreed($allRoomIds->map(fn ($id) => (int) $id)->values()->all());

        return response()->json(null, 204);
    }

    public function getAvailableRooms(Request $request)
    {
        $this->allowAvailableRoomsLookup();

        $request->validate([
            'check_in' => 'required|date',
            // Do not use after:check_in — hourly bookings often share the same calendar date for
            // check_in / check_out while actual times live in ISO datetimes (or check_in_at / check_out_at).
            'check_out' => 'required|date',
            'room_type_id' => 'nullable|exists:room_types,id',
            'exclude_booking_id' => 'nullable|integer',
            'exclude_room_id' => 'nullable|integer',
        ]);

        $checkInAt = $this->parseHotelDateTime((string) $request->check_in);
        $checkOutAt = $this->parseHotelDateTime((string) $request->check_out);

        if ($checkOutAt->lessThanOrEqualTo($checkInAt)) {
            $inStr = (string) $request->check_in;
            $outStr = (string) $request->check_out;
            // Hourly / same calendar day: legacy rows may only store yyyy-MM-dd for both fields.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inStr) && $inStr === $outStr) {
                $checkOutAt = $checkInAt->copy()->endOfDay();
            } else {
                return response()->json([
                    'message' => 'check_out must be after check_in.',
                ], 422);
            }
        }
        $checkInDate = $checkInAt->toDateString();
        $checkOutDateExclusive = $this->dateEndExclusiveFromDateTime($checkOutAt);
        $typeId = $request->room_type_id;
        $excludeId = $request->exclude_booking_id;
        $excludeRoomId = $request->exclude_room_id;

        $rooms = Room::with('roomType')
            ->where('is_active', '=', true)
            ->when($excludeRoomId, function ($q) use ($excludeRoomId) {
                $q->where('id', '!=', $excludeRoomId);
            })
            ->when($typeId, function ($q) use ($typeId) {
                $q->where('room_type_id', '=', $typeId);
            })
            // Room type active is optional; keeping as extra safety
            ->whereHas('roomType', function ($q) {
                $q->where('is_active', true);
            })
            // IMPORTANT: use segments so split-stays are respected
            ->whereDoesntHave('segments', function ($q) use ($checkInAt, $checkOutAt, $excludeId) {
                $q->whereNotIn('status', BookingRoomAvailability::INACTIVE_SEGMENT_STATUSES)
                    ->where('check_in_at', '<', $checkOutAt)
                    ->where('check_out_at', '>', $checkInAt)
                    ->when($excludeId, function ($sq) use ($excludeId) {
                        $sq->where('booking_id', '!=', $excludeId);
                    });
            })
            // Maintenance / hold always block; dirty & cleaning remain sellable for confirmed stays
            // (aligned with store()). Immediate check-in is gated separately at check-in time.
            ->whereDoesntHave('statusBlocks', function ($q) use ($checkInDate, $checkOutDateExclusive) {
                $q->where('is_active', true)
                    ->whereIn('status', BookingRoomAvailability::HARD_BLOCK_STATUSES)
                    ->where('start_date', '<', $checkOutDateExclusive)
                    ->where('end_date', '>', $checkInDate);
            })
            ->get()
            // Same overlap rule as create, including the departure-morning rule for hourly stays.
            ->reject(fn (Room $room) => BookingRoomAvailability::hasSegmentOverlap(
                (int) $room->id,
                $checkInAt,
                $checkOutAt,
                $excludeId ? (int) $excludeId : null,
            ))
            ->values();

        return response()->json($rooms);
    }

    public function listRoomTransfers(Booking $booking)
    {
        $this->allowReservationEdit();

        return response()->json([
            'items' => BookingRoomTransferService::historyPayload($booking),
            'reasons' => array_map(
                fn(string $code) => ['code' => $code, 'label' => \App\Models\BookingRoomTransfer::reasonLabel($code)],
                \App\Models\BookingRoomTransfer::REASONS
            ),
        ]);
    }

    public function previewRoomTransfer(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();
        $request->validate([
            'new_room_id' => 'required|exists:rooms,id',
            'transfer_reason' => 'required|string|max:64',
            'internal_notes' => 'nullable|string|max:2000',
            'rate_mode' => 'required|in:keep_existing,apply_new_category',
            'from_room_id' => 'nullable|integer',
        ]);

        $result = BookingRoomTransferService::preview($booking, $request->all());
        if (! ($result['ok'] ?? false)) {
            return response()->json(['message' => $result['message'] ?? 'Preview failed.'], 422);
        }

        return response()->json($result['preview']);
    }

    public function roomTransfer(Request $request, Booking $booking)
    {
        $this->allowReservationEdit();
        $request->validate([
            'new_room_id' => 'required|exists:rooms,id',
            'transfer_reason' => 'required|string|max:64',
            'internal_notes' => 'nullable|string|max:2000',
            'rate_mode' => 'required|in:keep_existing,apply_new_category',
            'from_room_id' => 'nullable|integer',
        ]);

        $roomIdsBefore = $booking->segments()->pluck('room_id')->push($booking->room_id)->map(fn ($id) => (int) $id)->all();
        $result = BookingRoomTransferService::execute($booking, $request->all());
        if (! ($result['ok'] ?? false)) {
            return response()->json(['message' => $result['message'] ?? 'Room transfer failed.'], 422);
        }

        return response()->json([
            'booking' => $result['booking'],
            'doorloom' => HotelApiSync::syncBooking($result['booking'], $roomIdsBefore),
            'transfer' => $result['transfer'],
            'transfers' => BookingRoomTransferService::historyPayload($result['booking']),
        ]);
    }
}
