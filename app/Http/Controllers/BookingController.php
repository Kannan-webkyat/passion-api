<?php

namespace App\Http\Controllers;

use App\Support\BookingInvoiceRoomStay;
use App\Support\ReservationInvoiceViewData;
use App\Support\SeasonalRoomPricing;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\BookingSegment;
use App\Models\PosOrder;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookingController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
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
        foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'phone' => 'Phone', 'city' => 'City', 'country' => 'Country'] as $field => $label) {
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

    private function computeHourlyPackageTotal(Room $room, int $ratePlanId, Carbon $checkInAt, Carbon $checkOutAt, int $extraBeds = 0): array
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
        $extraBedCost = (float) ($rt->extra_bed_cost ?? 0);
        if ($extraBeds > 0 && $extraBedCost > 0) {
            $total += $extraBeds * $extraBedCost;
        }

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

    /** Payable total after checkout (commercial) discount. */
    private function effectiveBookingGrand(Booking $booking): float
    {
        $gross = $this->bookingGrossBeforeCheckoutDiscount($booking);
        $disc = max(0.0, (float) ($booking->checkout_discount_amount ?? 0));

        return max(0.0, round($gross - min($disc, $gross), 2));
    }

    public function index(Request $request)
    {
        $this->checkPermission('view-rooms');
        return Booking::with(['room.roomType', 'creator', 'bookingGroup'])
            ->when($request->booking_group_id, function ($q) use ($request) {
                $q->where('booking_group_id', $request->booking_group_id);
            })
            ->orderBy('check_in')
            ->get();
    }

    public function guestSearch(Request $request)
    {
        $phone = $request->query('phone');
        if (!$phone || strlen($phone) < 4) {
            return response()->json(['message' => 'Provide at least 4 digits to search.'], 422);
        }

        /** @var \App\Models\Booking|null $booking */
        $booking = Booking::query()->where('phone', 'like', "%{$phone}%")
            ->orderByDesc('created_at')
            ->first();

        if (!$booking instanceof Booking) {
            return response()->json(['message' => 'No guest found with this phone number.'], 404);
        }

        return response()->json([
            'first_name' => $booking->first_name,
            'last_name' => $booking->last_name,
            'email' => $booking->email,
            'phone' => $booking->phone,
            'city' => $booking->city,
            'country' => $booking->country,
            'guest_identity_types' => $booking->guest_identity_types,
            'guest_identities' => $booking->guest_identities,
        ]);
    }

    public function chart(Request $request)
    {
        $this->checkPermission('view-rooms');
        $start = Carbon::parse($request->query('start', Carbon::today()));
        // Show 14 days by default for better visibility
        $end = Carbon::parse($request->query('end', Carbon::today()->addDays(13)));
        $rangeStartAt = $start->copy()->startOfDay();
        // end is a date on the grid; include the whole end day by making end-exclusive = next day start
        $rangeEndAt = $end->copy()->addDay()->startOfDay();

        $rooms = Room::with(['roomType.tax', 'roomType.ratePlans', 'roomType.seasons', 'statusBlocks' => function ($q) use ($start, $end) {
            $q->where('is_active', true)
                ->where('start_date', '<', $end->toDateString())
                ->where('end_date', '>', $start->toDateString());
        }, 'segments' => function ($q) use ($rangeStartAt, $rangeEndAt) {
            $q->where('check_in_at', '<', $rangeEndAt)
                ->where('check_out_at', '>', $rangeStartAt)
                ->whereNotIn('status', ['cancelled'])
                ->with(['booking', 'ratePlan']);
        }])->get();

        return response()->json([
            'rooms' => $rooms,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ]);
    }

    public function summary(Request $request)
    {
        $this->checkPermission('view-rooms');
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

    public function store(Request $request)
    {
        $this->checkPermission('reservation');
        $validated = $request->validate([
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'exists:rooms,id',
            'room_id' => 'required_without:room_ids|exists:rooms,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'guest_identity_types' => 'nullable|array',
            'guest_identity_types.*' => 'nullable|string|max:255',
            'guest_identities' => 'nullable|array',
            'guest_identities.*' => 'nullable|string', // Base64 or paths
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
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
            // For hour_package, total_price is computed server-side.
            'total_price' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|in:pending,partial,paid,refunded',
            'payment_method' => 'nullable|string',
            'deposit_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:pending,confirmed,checked_in,checked_out,cancelled',
            'notes' => 'nullable|string',
            'group_name' => 'nullable|string|max:255', // For group master
            'adult_breakfast_count' => 'nullable|integer|min:0',
            'child_breakfast_count' => 'nullable|integer|min:0',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
        ]);

        $creatorId = Auth::id();
        $roomIds = $request->input('room_ids', [$request->input('room_id')]);
        $bookingUnit = $validated['booking_unit'] ?? 'day';
        $checkInAt = Carbon::parse($validated['check_in']);
        $checkOutAt = isset($validated['check_out']) ? Carbon::parse($validated['check_out']) : null;
        $status = $validated['status'] ?? 'confirmed';

        // New reservations only from today onward (hotel calendar day in app timezone).
        if ($checkInAt->copy()->startOfDay()->lt(Carbon::today())) {
            return response()->json([
                'message' => 'Check-in cannot be in the past. New reservations must start on or after today.',
            ], 422);
        }

        if ($bookingUnit === 'day') {
            if (! $checkOutAt) {
                return response()->json(['message' => 'check_out is required for day bookings.'], 422);
            }
            // normalize to midnight so old semantics stay consistent
            $checkInAt = $checkInAt->copy()->startOfDay();
            $checkOutAt = $checkOutAt->copy()->startOfDay();
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

        // 1. Availability Check (Overlap) - Using BookingSegment (datetime-safe)
        foreach ($roomIds as $roomId) {
            $overlap = BookingSegment::where('room_id', '=', $roomId, 'and')
                // Checked-out/completed segments must not block fresh reservations.
                ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
                ->where(function ($query) use ($checkInAt, $checkOutAt) {
                    // For hour_package, checkOutAt can be computed later per-room after plan selection.
                    // Here we still require a checkOutAt for overlap. If not provided, do a conservative check
                    // by treating it as +12h (max package) to avoid false availability.
                    $end = $checkOutAt ?: $checkInAt->copy()->addHours(12);
                    $query->where('check_in_at', '<', $end)
                        ->where('check_out_at', '>', $checkInAt);
                })->exists();

            if ($overlap) {
                $room = Room::find($roomId, ['room_number']);

                return response()->json([
                    'message' => 'Room #' . ($room?->room_number ?? (string) $roomId) . ' is already reserved for the selected dates.',
                ], 422);
            }

            // 2. Room status block check (source of truth, overlap-aware)
            $room = Room::findOrFail($roomId);
            $startAt = $checkInAt->copy();
            $endAt = ($checkOutAt ?: $checkInAt->copy()->addHours(12))->copy();
            $startDate = $startAt->toDateString();
            $endDateExclusive = $this->dateEndExclusiveFromDateTime($endAt);

            $blocking = RoomStatusBlock::where('room_id', '=', $roomId, 'and')
                ->where('is_active', true)
                ->where('start_date', '<', $endDateExclusive)
                ->where('end_date', '>', $startDate)
                ->get();

            if ($blocking->contains(fn($b) => $b->status === 'maintenance')) {
                return response()->json(['message' => "Room #{$room->room_number} is under maintenance."], 422);
            }

            // Dirty/Cleaning blocks should only prevent immediate check-in.
            if ($status === 'checked_in' && $blocking->contains(fn($b) => in_array($b->status, ['dirty', 'cleaning'], true))) {
                return response()->json(['message' => "Room #{$room->room_number} requires cleaning before check-in."], 422);
            }
        }

        $isGroup = count($roomIds) > 1 || $request->filled('group_name');

        $bookingGroupId = null;
        if ($isGroup) {
            $group = BookingGroup::create([
                'name' => $request->input('group_name') ?: ('Group - ' . $validated['first_name'] . ' ' . $validated['last_name']),
                'contact_person' => $validated['first_name'] . ' ' . $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'status' => 'confirmed',
                'notes' => $validated['notes'],
            ]);
            $bookingGroupId = $group->id;
        }

        // Handle Identity Images
        $imagePaths = [];
        if ($request->has('guest_identities')) {
            $images = $request->input('guest_identities') ?: [];
            foreach ($images as $index => $imageData) {
                if (! $imageData) {
                    continue;
                }

                if (str_starts_with($imageData, 'data:image')) {
                    // Base64 from Camera or Upload
                    $format = str_contains($imageData, 'png') ? 'png' : 'jpg';
                    $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
                    $fileName = 'guest_id_' . time() . '_' . $index . '.' . $format;
                    \Illuminate\Support\Facades\Storage::disk('public')->put('identities/' . $fileName, $data);
                    $imagePaths[] = 'identities/' . $fileName;
                } elseif ($request->hasFile("guest_identities.{$index}")) {
                    // Direct File Upload (if sent as multipart/form-data)
                    $imagePaths[] = $request->file("guest_identities.{$index}")->store('identities', 'public');
                } else {
                    // Already uploaded path
                    $imagePaths[] = $imageData;
                }
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

            // Compute/check datetime and totals for hourly packages
            $finalCheckInAt = $checkInAt->copy();
            $finalCheckOutAt = $checkOutAt ? $checkOutAt->copy() : null;
            $totalPrice = isset($bookingData['total_price']) ? (float) $bookingData['total_price'] : 0.0;

            if ($bookingUnit === 'hour_package') {
                $planId = (int) ($bookingData['rate_plan_id'] ?? 0);
                if ($planId <= 0) {
                    return response()->json(['message' => 'rate_plan_id is required for hourly package bookings.'], 422);
                }

                // If checkout not provided, compute it as package end
                if (! $finalCheckOutAt) {
                    // Package end depends on package_hours
                    $plan = $room->roomType?->ratePlans?->firstWhere('id', $planId);
                    $pkgHours = (int) ($plan?->package_hours ?? 0);
                    if (($plan?->billing_unit ?? 'day') !== 'hour_package' || $pkgHours <= 0) {
                        return response()->json(['message' => 'Invalid hourly package plan for this room.'], 422);
                    }
                    $finalCheckOutAt = $finalCheckInAt->copy()->addHours($pkgHours);
                }

                $extraBedsForRoom = (int) ($bookingData['extra_beds_count'] ?? 0);
                $calc = $this->computeHourlyPackageTotal($room, $planId, $finalCheckInAt, $finalCheckOutAt, $extraBedsForRoom);
                if (! $calc['ok']) {
                    return response()->json(['message' => $calc['message']], 422);
                }
                $totalPrice = (float) $calc['total'];
            } else {
                // For multi-room bookings, compute per-room day total server-side so
                // each booking holds only its own room price (prevents grouped over/under totals).
                if (count($roomIds) > 1) {
                    $planId = (int) ($bookingData['rate_plan_id'] ?? 0);
                    $plan = $room->roomType?->ratePlans?->firstWhere('id', $planId);
                    if ($plan) {
                        $effectiveCheckOutAt = $finalCheckOutAt ? $finalCheckOutAt->copy() : $finalCheckInAt->copy()->addDay();
                        $basePerNight = (float) ($plan->base_price ?? 0);
                        $extraBeds = (int) ($bookingData['extra_beds_count'] ?? 0);
                        $extraBedCost = (float) ($room->roomType?->extra_bed_cost ?? 0);
                        $beforeTax = SeasonalRoomPricing::sumDayRoomRentWithSeasons(
                            $basePerNight,
                            $extraBedCost,
                            $extraBeds,
                            $finalCheckInAt->copy()->startOfDay(),
                            $effectiveCheckOutAt->copy()->startOfDay(),
                            $room->roomType?->seasons ?? []
                        );
                        $taxRate = (float) ($room->roomType?->tax?->rate ?? 0);
                        $roomRatesIncludeGst = filter_var(Setting::get('room_rates_include_gst', '0'), FILTER_VALIDATE_BOOLEAN);
                        $totalPrice = $roomRatesIncludeGst
                            ? round($beforeTax, 2)
                            : round($beforeTax * (1 + ($taxRate / 100)), 2);
                    }
                }
            }

            // Sync legacy date columns for compatibility
            $bookingData['check_in_at'] = $finalCheckInAt;
            $bookingData['check_out_at'] = $finalCheckOutAt;
            $bookingData['check_in'] = $finalCheckInAt->toDateString();
            $bookingData['check_out'] = $finalCheckOutAt->toDateString();
            $bookingData['total_price'] = $totalPrice;

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

            $booking = Booking::create($bookingData);

            // Create initial Stay Segment
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

            if (($validated['status'] ?? '') === 'checked_in') {
                Room::findOrFail($roomId)->update(['status' => 'occupied']);
            }

            $bookings[] = $booking->load(['room.roomType.tax', 'creator', 'bookingGroup', 'segments']);
        }

        return response()->json($isGroup ? $bookings : $bookings[0], 201);
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
        $this->checkPermission('reservation');
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
        $this->checkPermission('reservation');
        return $booking->load(['room.roomType.tax', 'creator', 'bookingGroup']);
    }

    public function update(Request $request, Booking $booking)
    {
        $this->checkPermission('reservation');
        $validated = $request->validate([
            'room_id' => 'exists:rooms,id',
            'first_name' => 'string|max:255',
            'last_name' => 'string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
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
            'payment_status' => 'in:pending,partial,paid,refunded',
            'payment_method' => 'nullable|string',
            'deposit_amount' => 'nullable|numeric|min:0',
            'refund_amount' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|string|in:cash,card,upi,bank_transfer',
            'status' => 'in:pending,confirmed,checked_in,checked_out,cancelled',
            'booking_source' => 'nullable|string',
            'notes' => 'nullable|string',
            'guest_identity_types' => 'nullable|array',
            'guest_identity_types.*' => 'nullable|string|max:255',
            'guest_identities' => 'nullable|array',
            'guest_identities.*' => 'nullable|string', // Base64 or paths
            'adult_breakfast_count' => 'nullable|integer|min:0',
            'child_breakfast_count' => 'nullable|integer|min:0',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
            'booking_group_id' => 'nullable|exists:booking_groups,id',
            'checkout_discount_amount' => 'nullable|numeric|min:0',
            'checkout_discount_reason' => 'nullable|string|max:500',
        ]);

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

        // Checkout validation: must be paid
        if (isset($validated['status']) && $validated['status'] === 'checked_out' && $booking->status !== 'checked_out') {
            if ((float) ($validated['refund_amount'] ?? 0) > 0.0001 && empty($validated['refund_method'])) {
                return response()->json(['message' => 'Select how the refund will be issued (cash, card, UPI, or bank transfer).'], 422);
            }

            $currentPaymentStatus = $validated['payment_status'] ?? $booking->payment_status;
            $isPaid = ($currentPaymentStatus === 'paid');

            // Group-aware checkout rule:
            // if this booking belongs to a group, allow checkout when the group is fully paid
            // even if this single room booking still has pending/partial status.
            if (! $isPaid && ! empty($booking->booking_group_id)) {
                $groupBookings = Booking::where('booking_group_id', '=', $booking->booking_group_id, 'and')
                    ->with(['room.roomType.tax', 'room.roomType.ratePlans'])
                    ->get();
                $groupGrand = (float) $groupBookings->sum(fn($b) => $this->effectiveBookingGrand($b));
                $groupPaid = (float) $groupBookings->sum(fn($b) => (float) ($b->deposit_amount ?? 0));
                $isPaid = $groupPaid + 0.009 >= $groupGrand;
            }

            // Single booking: allow checkout when advance/deposit covers the bill, even if
            // payment_status was never flipped to "paid" (common after deposits or when totals were adjusted).
            if (! $isPaid && empty($booking->booking_group_id)) {
                $paid = (float) ($booking->deposit_amount ?? 0);
                $grand = $this->effectiveBookingGrand($booking);
                $storedTotal = (float) ($booking->total_price ?? 0);
                $bill = max($grand, $storedTotal);
                $isPaid = $paid + 0.009 >= $bill;
            }

            if (! $isPaid) {
                return response()->json(['message' => 'Checkout not allowed until payment is fully paid'], 422);
            }

            // Early checkout: truncate the check_out date to free the room for other bookings
            $today = Carbon::today()->toDateString();
            $currentCheckOut = $validated['check_out'] ?? $booking->check_out;
            if ($currentCheckOut > $today) {
                $validated['check_out'] = $today;

                $user = Auth::user();
                $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
                $auditMsg = "[Early CO: on {$today}" . ($userName ? " by {$userName}" : '') . ']';
                $existingNotes = $validated['notes'] ?? $booking->notes;
                $validated['notes'] = $existingNotes ? $existingNotes . "\n" . $auditMsg : $auditMsg;
            }
        }

        // Handle Identity Images (Update/Append)
        if ($request->has('guest_identities')) {
            $existingIdentities = $booking->guest_identities ?: [];
            $incomingImages = $request->input('guest_identities') ?: [];
            $newPaths = [];

            foreach ($incomingImages as $index => $imageData) {
                if (! $imageData) {
                    continue;
                }

                if (str_starts_with($imageData, 'data:image')) {
                    // New Base64 from Camera or Upload
                    $format = str_contains($imageData, 'png') ? 'png' : 'jpg';
                    $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
                    $fileName = 'guest_id_' . time() . '_' . $index . '.' . $format;
                    \Illuminate\Support\Facades\Storage::disk('public')->put('identities/' . $fileName, $data);
                    $newPaths[] = 'identities/' . $fileName;
                } elseif ($request->hasFile("guest_identities.{$index}")) {
                    // New Direct File Upload
                    $newPaths[] = $request->file("guest_identities.{$index}")->store('identities', 'public');
                } else {
                    // Retain existing image path
                    $newPaths[] = $imageData;
                }
            }
            $validated['guest_identities'] = $newPaths;
        }

        // Keep legacy date columns aligned whenever datetime fields are sent.
        if (isset($validated['check_in_at'])) {
            $validated['check_in'] = Carbon::parse($validated['check_in_at'])->toDateString();
        }
        if (isset($validated['check_out_at'])) {
            $validated['check_out'] = Carbon::parse($validated['check_out_at'])->toDateString();
        }

        $this->appendAuditNotesForBookingUpdate($booking, $validated, $request);

        $booking->update($validated);

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
            $allRoomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->unique();

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

                foreach ($segmentsForHk as $segment) {
                    $rid = (int) $segment->room_id;
                    $checkoutDay = Carbon::parse(
                        $segment->check_out_at ?? $segment->check_out ?? $booking->check_out_at ?? $booking->check_out
                    )->startOfDay();
                    $co = $checkoutDay->toDateString();
                    $coNext = $checkoutDay->copy()->addDay()->toDateString();

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
            }
        }

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Early Check-In ────────────────────────────────────────────────────────
    public function earlyCheckin(Request $request, Booking $booking)
    {
        $this->checkPermission('reservation');
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        $time = $request->input('time');
        $roomId = $booking->room_id;
        $checkInDay = Carbon::parse($booking->check_in)->toDateString();

        // Conflict: a prior booking for this room is still checked_in on the same day
        // AND its late_checkout_time would overlap with the requested early check-in time.
        // (A booking that is already checked_out is NOT a conflict.)
        $conflict = Booking::where('room_id', '=', $roomId, 'and')
            ->where('id', '!=', $booking->id)
            ->where('status', 'checked_in')           // Only block if guest is still in the room
            ->whereDate('check_out', '=', $checkInDay, 'and')
            ->where(function ($q) use ($time) {
                // Blocked if: no explicit late_checkout (assume standard noon) OR late_checkout >= requested early CI
                $q->whereNull('late_checkout_time')
                    ->orWhereTime('late_checkout_time', '>=', $time);
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

        $rt = $booking->room?->roomType;
        $standardTime = Setting::get('standard_check_in_time', '14:00');
        $fee = 0;
        $units = "";

        if ($rt && $time < $standardTime) {
            $policyTime = Carbon::createFromFormat('H:i', $standardTime);
            $actualTime = Carbon::createFromFormat('H:i', $time);
            $totalGapMins = $policyTime->diffInMinutes($actualTime);

            $bufferMins = (int) ($rt->early_check_in_buffer_minutes ?? 0);
            $billableMins = max(0, $totalGapMins - $bufferMins);

            if ($billableMins > 0) {
                if ($rt->early_check_in_type === 'per_hour') {
                    $billableHours = ceil($billableMins / 60);
                    $fee = $billableHours * (float) $rt->early_check_in_fee;
                    $units = "({$billableHours}h)";
                } elseif ($rt->early_check_in_type === 'per_minute') {
                    $fee = $billableMins * (float) $rt->early_check_in_fee;
                    $units = "({$billableMins}m)";
                } else { // flat_fee or other
                    $fee = (float) $rt->early_check_in_fee;
                }
            }
        }

        if ($fee > 0) {
            $auditMsg .= " Fee: ₹{$fee} {$units} applied.";
        }

        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $booking->update([
            'early_checkin_time' => $time,
            'extra_charges' => (float) ($booking->extra_charges ?? 0) + $fee,
            'notes' => $notes,
        ]);

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Late Checkout ─────────────────────────────────────────────────────────
    public function lateCheckout(Request $request, Booking $booking)
    {
        $this->checkPermission('reservation');
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        $time = $request->input('time');
        $roomId = $booking->room_id;
        $checkOutDay = Carbon::parse($booking->check_out)->toDateString();

        // Conflict: another booking starts on this room the same checkout day
        // and its early_checkin_time (or standard noon) is <= the requested late time
        $conflict = Booking::where('room_id', '=', $roomId, 'and')
            ->where('id', '!=', $booking->id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('check_in', '=', $checkOutDay, 'and')
            ->where(function ($q) use ($time) {
                $q->whereNull('early_checkin_time')
                    ->orWhereTime('early_checkin_time', '<=', $time);
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'message' => 'Late checkout conflicts with the next guest\'s check-in on the same day.',
            ], 422);
        }

        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');

        $rt = $booking->room?->roomType;
        $standardTimeRaw = (string) Setting::get('standard_check_out_time', '11:00');
        $normalizeClockTime = static function (?string $raw, string $fallback): string {
            $s = trim((string) $raw);
            if ($s === '') return $fallback;
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

        $computeLateFee = static function ($rt, string $standardTime, ?string $t): float {
            if (! $rt || ! $t) return 0.0;
            $t = trim((string) $t);
            if ($t === '') return 0.0;
            // Ensure HH:mm for safe comparisons + parsing (tolerate legacy AM/PM)
            if (! preg_match('/^\d{2}:\d{2}$/', $t)) {
                try {
                    $t = Carbon::parse($t)->format('H:i');
                } catch (\Throwable) {
                    return 0.0;
                }
            }
            if ($t <= $standardTime) return 0.0;

            $policyTime = Carbon::createFromFormat('H:i', $standardTime);
            $actualTime = Carbon::createFromFormat('H:i', $t);
            $totalGapMins = $actualTime->diffInMinutes($policyTime);

            $bufferMins = (int) ($rt->late_check_out_buffer_minutes ?? 0);
            $billableMins = max(0, $totalGapMins - $bufferMins);
            if ($billableMins <= 0) return 0.0;

            if ($rt->late_check_out_type === 'per_hour') {
                $billableHours = ceil($billableMins / 60);
                return $billableHours * (float) $rt->late_check_out_fee;
            }
            if ($rt->late_check_out_type === 'per_minute') {
                return $billableMins * (float) $rt->late_check_out_fee;
            }
            return (float) $rt->late_check_out_fee;
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
        if ($nextExtra < 0) $nextExtra = 0;

        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $booking->update([
            'late_checkout_time' => $timeToSave,
            'extra_charges' => $nextExtra,
            'notes' => $notes,
        ]);

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Reservation Extension ─────────────────────────────────────────────────
    public function extendReservation(Request $request, Booking $booking)
    {
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

        // Recalculate total price using rate plan if available (based on the room being extended)
        $room = Room::with(['roomType.tax', 'roomType.ratePlans'])->find($roomId);
        $extraNights = Carbon::parse($oldCheckOut)->diffInDays(Carbon::parse($newCheckOut));
        $extraCost = 0;

        if ($room?->roomType) {
            $rt = $room->roomType;
            $ratePlan = null;
            if ($booking->rate_plan_id) {
                $ratePlan = $rt->ratePlans->find($booking->rate_plan_id);
            }
            if (! $ratePlan) {
                $ratePlan = $rt->ratePlans->first(); // Fallback
            }

            $basePrice = $ratePlan ? $ratePlan->base_price : $rt->base_price;
            $extraBedCost = $rt->extra_bed_cost ?? 0;
            $extraBeds = $booking->extra_beds_count ?? 0;

            $nightlyRoomCost = $basePrice + ($extraBedCost * $extraBeds);

            // Breakfast inclusion
            if ($ratePlan && $ratePlan->includes_breakfast) {
                $adults = $booking->adults_count ?? 1;
                $children = $booking->children_count ?? 0;
                $nightlyRoomCost += ($rt->breakfast_price * $adults) + ($rt->child_breakfast_price * $children);
            }

            $subtotalExtension = $nightlyRoomCost * $extraNights;
            $extraCost = $subtotalExtension;

            if ($rt->tax) {
                $extraCost += $subtotalExtension * ($rt->tax->rate / 100);
            }
        }
        $newTotalPrice = (float) $booking->total_price + $extraCost;

        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $auditMsg = "[Extension: {$oldCheckOut} → {$newCheckOut}" . ($userName ? " by {$userName}" : '') . ' on ' . now()->format('Y-m-d H:i:s') . ']';
        $notes = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

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

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup', 'segments.room']));
    }

    // ── Hourly Reservation Extension (supports +1h, +2h, etc.) ─────────────────
    public function extendHourlyReservation(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'extend_minutes' => 'required|integer|min:1',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
        ]);

        if (($booking->booking_unit ?? 'day') !== 'hour_package') {
            return response()->json([
                'message' => 'This extension endpoint is only for hourly package bookings.',
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
        $calc = $this->computeHourlyPackageTotal($room, $planId, $checkInAt, $newCheckOutAt, $extraBeds);
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

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup', 'segments.room']));
    }

    /**
     * Handle Split Stay: Add a new segment to an existing booking.
     */
    public function splitStay(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'new_room_id' => 'required|exists:rooms,id',
            'new_check_out' => 'required|date|after:' . $booking->check_out,
            'complimentary_upgrade' => 'nullable|boolean',
        ]);

        $oldCheckOut = $booking->check_out;
        $newCheckOut = $validated['new_check_out'];
        $newRoomId = $validated['new_room_id'];
        $complimentaryUpgrade = ! empty($validated['complimentary_upgrade']);

        // Calculate price for the new segment
        $newRoom = Room::with(['roomType.tax', 'roomType.ratePlans'])->findOrFail($newRoomId);
        $nights = Carbon::parse($oldCheckOut)->diffInDays(Carbon::parse($newCheckOut));

        $rt = $newRoom->roomType;
        // Try to match existing rate plan if possible
        $ratePlan = $booking->rate_plan_id ? $rt->ratePlans->find($booking->rate_plan_id) : $rt->ratePlans->first();

        $segmentTotal = 0.0;
        if (! $complimentaryUpgrade) {
            $basePrice = $ratePlan ? $ratePlan->base_price : ($rt->base_price ?? 0);
            $extraBedCost = $rt->extra_bed_cost ?? 0;
            $extraBeds = $booking->extra_beds_count ?? 0;

            $nightlyRoomCost = $basePrice + ($extraBedCost * $extraBeds);

            // Breakfast inclusion
            if ($ratePlan && $ratePlan->includes_breakfast) {
                $adults = $booking->adults_count ?? 1;
                $children = $booking->children_count ?? 0;
                $nightlyRoomCost += (($rt->breakfast_price ?? 0) * $adults) + (($rt->child_breakfast_price ?? 0) * $children);
            }

            $segmentSubtotal = $nightlyRoomCost * $nights;
            $segmentTotal = $segmentSubtotal;

            if ($rt->tax) {
                $segmentTotal += $segmentSubtotal * ($rt->tax->rate / 100);
            }
        }

        // End the stay in the current room at $oldCheckOut (same as booking.check_out) before adding
        // the new room segment. Otherwise the previous segment can still extend into the extension
        // window and overlap the new segment — same guest on two rooms for the same dates / OVERLAP.
        $lastSegment = $booking->segments()->orderByDesc('check_out')->orderByDesc('check_out_at')->first();
        $oldCheckOutCarbon = Carbon::parse($oldCheckOut)->startOfDay();
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

        if ((int) $newRoomId === (int) $lastSegment->room_id) {
            return response()->json([
                'message' => 'Select a different room for the extended nights than the room the guest is in now.',
            ], 422);
        }

        // Add segment — inherit the parent booking's status so a checked_in guest
        // shows as "occupied" (not "reserved") on the room chart for the new room.
        $segmentStatus = $booking->status === 'checked_in' ? 'checked_in' : 'confirmed';

        $newSegment = BookingSegment::create([
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

        // Update main booking
        $user = Auth::user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
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

        return response()->json($booking->load(['segments.room.roomType', 'creator']));
    }

    public function reservationVoucher(Request $request, Booking $booking)
    {
        $booking->load(['room.roomType.tax', 'creator', 'bookingGroup']);

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
            'resNo' => (string) $booking->id,
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
        $this->checkPermission('reservation');

        $data = ReservationInvoiceViewData::build($booking);
        $pdf = Pdf::loadView('bookings.reservation_invoice', $data)->setPaper('a4', 'portrait');
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $data['invoiceNo']);

        return $pdf->download('Invoice_' . $safeName . '.pdf');
    }

    /**
     * POS orders with room charge posted to this booking (for reception checkout breakdown).
     */
    public function folioPostings(Booking $booking)
    {
        $this->checkPermission('view-rooms');

        $orders = PosOrder::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'paid')
            ->with([
                'restaurant:id,name',
                'payments' => function ($q) {
                    $q->where('method', 'room_charge');
                },
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

        return response()->json([
            'extra_charges_total' => (float) ($booking->extra_charges ?? 0),
            'folio_tax' => [
                'cgst' => round($folioCgst, 2),
                'sgst' => round($folioSgst, 2),
                'igst' => round($folioIgst, 2),
                'vat' => round($folioVat, 2),
            ],
            'items' => $items,
        ]);
    }

    /**
     * Line-item detail for a single POS order on this booking’s folio (reception drill-down).
     */
    public function folioOrderDetail(Booking $booking, PosOrder $order)
    {
        $this->checkPermission('view-rooms');

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

    public function destroy(Booking $booking)
    {
        // For split stays, booking->room may not reflect all rooms used. Fall back safely.
        $allRoomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->unique();
        Room::whereIn('id', $allRoomIds, 'and', false)->update(['status' => 'available']);
        Booking::destroy($booking->id);

        return response()->json(null, 204);
    }

    public function getAvailableRooms(Request $request)
    {
        $request->validate([
            'check_in' => 'required|date',
            // Do not use after:check_in — hourly bookings often share the same calendar date for
            // check_in / check_out while actual times live in ISO datetimes (or check_in_at / check_out_at).
            'check_out' => 'required|date',
            'room_type_id' => 'nullable|exists:room_types,id',
            'exclude_booking_id' => 'nullable|integer',
            'exclude_room_id' => 'nullable|integer',
        ]);

        $checkInAt = Carbon::parse($request->check_in);
        $checkOutAt = Carbon::parse($request->check_out);

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
                $q->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
                    ->where('check_in_at', '<', $checkOutAt)
                    ->where('check_out_at', '>', $checkInAt)
                    ->when($excludeId, function ($sq) use ($excludeId) {
                        $sq->where('booking_id', '!=', $excludeId);
                    });
            })
            // Exclude rooms blocked by maintenance/dirty/cleaning ranges
            ->whereDoesntHave('statusBlocks', function ($q) use ($checkInDate, $checkOutDateExclusive) {
                $q->where('is_active', true)
                    ->where('start_date', '<', $checkOutDateExclusive)
                    ->where('end_date', '>', $checkInDate);
            })
            ->get();

        return response()->json($rooms);
    }
}
