<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BookingController extends Controller
{
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

    private function effectiveBookingGrand(Booking $booking): float
    {
        $booking->loadMissing(['room.roomType.tax', 'room.roomType.ratePlans']);

        // Keep hourly as stored value (includes overtime logic already computed server-side).
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return (float) ($booking->total_price ?? 0);
        }

        $roomType = $booking->room?->roomType;
        $plan = $roomType?->ratePlans?->firstWhere('id', $booking->rate_plan_id);
        if (! $plan) {
            return (float) ($booking->total_price ?? 0);
        }

        $checkInAt = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $checkOutAt = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();
        $nights = max(1, $checkInAt->copy()->startOfDay()->diffInDays($checkOutAt->copy()->startOfDay()));

        $basePerNight = (float) ($plan->base_price ?? 0);
        $extraBeds = (int) ($booking->extra_beds_count ?? 0);
        $extraBedCost = (float) ($roomType?->extra_bed_cost ?? 0);
        $beforeTax = ($basePerNight + ($extraBeds > 0 ? $extraBeds * $extraBedCost : 0)) * $nights;
        $taxRate = (float) ($roomType?->tax?->rate ?? 0);

        return round($beforeTax * (1 + ($taxRate / 100)), 2);
    }

    public function index(Request $request)
    {
        return Booking::with(['room.roomType', 'creator', 'bookingGroup'])
            ->when($request->booking_group_id, function ($q) use ($request) {
                $q->where('booking_group_id', $request->booking_group_id);
            })
            ->orderBy('check_in')
            ->get();
    }

    public function chart(Request $request)
    {
        $start = Carbon::parse($request->query('start', Carbon::today()));
        // Show 14 days by default for better visibility
        $end = Carbon::parse($request->query('end', Carbon::today()->addDays(13)));
        $rangeStartAt = $start->copy()->startOfDay();
        // end is a date on the grid; include the whole end day by making end-exclusive = next day start
        $rangeEndAt = $end->copy()->addDay()->startOfDay();

        $rooms = Room::with(['roomType.tax', 'roomType.ratePlans', 'statusBlocks' => function ($q) use ($start, $end) {
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
            'checkins_today' => Booking::whereDate('check_in', $today)->whereIn('status', ['confirmed', 'checked_in'])->count(),
            'checkouts_today' => Booking::whereDate('check_out', $today)->whereIn('status', ['checked_in', 'checked_out'])->count(),
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

        $creatorId = $request->user()?->id;
        $roomIds = $request->input('room_ids', [$request->input('room_id')]);
        $bookingUnit = $validated['booking_unit'] ?? 'day';
        $checkInAt = Carbon::parse($validated['check_in']);
        $checkOutAt = isset($validated['check_out']) ? Carbon::parse($validated['check_out']) : null;
        $status = $validated['status'] ?? 'confirmed';

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
            $overlap = BookingSegment::where('room_id', $roomId)
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
                $room = Room::find($roomId);

                return response()->json([
                    'message' => "Room #{$room->room_number} is already reserved for the selected dates.",
                ], 422);
            }

            // 2. Room status block check (source of truth, overlap-aware)
            $room = Room::findOrFail($roomId);
            $startAt = $checkInAt->copy();
            $endAt = ($checkOutAt ?: $checkInAt->copy()->addHours(12))->copy();
            $startDate = $startAt->toDateString();
            $endDateExclusive = $this->dateEndExclusiveFromDateTime($endAt);

            $blocking = RoomStatusBlock::where('room_id', $roomId)
                ->where('is_active', true)
                ->where('start_date', '<', $endDateExclusive)
                ->where('end_date', '>', $startDate)
                ->get();

            if ($blocking->contains(fn ($b) => $b->status === 'maintenance')) {
                return response()->json(['message' => "Room #{$room->room_number} is under maintenance."], 422);
            }

            // Dirty/Cleaning blocks should only prevent immediate check-in.
            if ($status === 'checked_in' && $blocking->contains(fn ($b) => in_array($b->status, ['dirty', 'cleaning'], true))) {
                return response()->json(['message' => "Room #{$room->room_number} requires cleaning before check-in."], 422);
            }
        }

        $isGroup = count($roomIds) > 1 || $request->filled('group_name');

        $bookingGroupId = null;
        if ($isGroup) {
            $group = BookingGroup::create([
                'name' => $request->input('group_name') ?: ('Group - '.$validated['first_name'].' '.$validated['last_name']),
                'contact_person' => $validated['first_name'].' '.$validated['last_name'],
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
                    $fileName = 'guest_id_'.time().'_'.$index.'.'.$format;
                    \Illuminate\Support\Facades\Storage::disk('public')->put('identities/'.$fileName, $data);
                    $imagePaths[] = 'identities/'.$fileName;
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

            // Apply individual room occupancy if provided
            if (isset($roomOccupancy[$roomId])) {
                $occ = $roomOccupancy[$roomId];
                $bookingData['adults_count'] = $occ['adults'] ?? $bookingData['adults_count'];
                $bookingData['children_count'] = $occ['children'] ?? ($bookingData['children_count'] ?? 0);
                $bookingData['infants_count'] = $occ['infants'] ?? ($bookingData['infants_count'] ?? 0);
                $bookingData['extra_beds_count'] = $occ['extra_beds'] ?? ($bookingData['extra_beds_count'] ?? 0);
                $bookingData['adult_breakfast_count'] = $occ['adult_breakfast'] ?? $bookingData['adult_breakfast_count'];
                $bookingData['child_breakfast_count'] = $occ['child_breakfast'] ?? $bookingData['child_breakfast_count'];
                if (! empty($occ['rate_plan_id'])) {
                    $bookingData['rate_plan_id'] = $occ['rate_plan_id'];
                }
            }

            $room = Room::with(['roomType.tax', 'roomType.ratePlans'])->findOrFail($roomId);

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
                        $nights = max(1, $finalCheckInAt->copy()->startOfDay()->diffInDays($effectiveCheckOutAt->startOfDay()));
                        $basePerNight = (float) ($plan->base_price ?? 0);
                        $extraBeds = (int) ($bookingData['extra_beds_count'] ?? 0);
                        $extraBedCost = (float) ($room->roomType?->extra_bed_cost ?? 0);
                        $beforeTax = ($basePerNight + ($extraBeds > 0 ? $extraBeds * $extraBedCost : 0)) * $nights;
                        $taxRate = (float) ($room->roomType?->tax?->rate ?? 0);
                        $totalPrice = round($beforeTax * (1 + ($taxRate / 100)), 2);
                    }
                }
            }

            // Sync legacy date columns for compatibility
            $bookingData['check_in_at'] = $finalCheckInAt;
            $bookingData['check_out_at'] = $finalCheckOutAt;
            $bookingData['check_in'] = $finalCheckInAt->toDateString();
            $bookingData['check_out'] = $finalCheckOutAt->toDateString();
            $bookingData['total_price'] = $totalPrice;

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

    // --- Booking Group Management ---

    /**
     * Create only the BookingGroup master record.
     */
    public function storeGroup(Request $request)
    {
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
        return $booking->load(['room.roomType.tax', 'creator', 'bookingGroup']);
    }

    public function update(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'room_id' => 'exists:rooms,id',
            'first_name' => 'string|max:255',
            'last_name' => 'string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'adults_count' => 'integer|min:1',
            'children_count' => 'nullable|integer|min:0',
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
        ]);

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
            $currentPaymentStatus = $validated['payment_status'] ?? $booking->payment_status;
            $isPaid = ($currentPaymentStatus === 'paid');

            // Group-aware checkout rule:
            // if this booking belongs to a group, allow checkout when the group is fully paid
            // even if this single room booking still has pending/partial status.
            if (! $isPaid && ! empty($booking->booking_group_id)) {
                $groupBookings = Booking::where('booking_group_id', $booking->booking_group_id)
                    ->with(['room.roomType.tax', 'room.roomType.ratePlans'])
                    ->get();
                $groupGrand = (float) $groupBookings->sum(fn ($b) => $this->effectiveBookingGrand($b));
                $groupPaid = (float) $groupBookings->sum(fn ($b) => (float) ($b->deposit_amount ?? 0));
                $isPaid = $groupPaid >= $groupGrand;
            }

            if (! $isPaid) {
                return response()->json(['message' => 'Checkout not allowed until payment is fully paid'], 422);
            }

            // Early checkout: truncate the check_out date to free the room for other bookings
            $today = Carbon::today()->toDateString();
            $currentCheckOut = $validated['check_out'] ?? $booking->check_out;
            if ($currentCheckOut > $today) {
                $validated['check_out'] = $today;

                $user = $request->user();
                $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
                $auditMsg = "[Early CO: on {$today}".($userName ? " by {$userName}" : '').']';
                $existingNotes = $validated['notes'] ?? $booking->notes;
                $validated['notes'] = $existingNotes ? $existingNotes."\n".$auditMsg : $auditMsg;
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
                    $fileName = 'guest_id_'.time().'_'.$index.'.'.$format;
                    \Illuminate\Support\Facades\Storage::disk('public')->put('identities/'.$fileName, $data);
                    $newPaths[] = 'identities/'.$fileName;
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

            Room::whereIn('id', $allRoomIds)->update(['status' => $roomStatus]);

            // NEW FLOW: date-based housekeeping after checkout
            // When a booking is checked out, mark the affected rooms as DIRTY for that checkout date
            // via room_status_blocks so Room Chart + availability reflect housekeeping correctly.
            if ($validated['status'] === 'checked_out') {
                // Use actual checkout action day to ensure the room is immediately shown as dirty on chart.
                $co = Carbon::today()->toDateString();
                $coNext = Carbon::today()->addDay()->toDateString(); // [today, today+1)

                foreach ($allRoomIds as $rid) {
                    $hasBlock = RoomStatusBlock::where('room_id', $rid)
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
                            'created_by' => $request->user()?->id,
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
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        $time = $request->input('time');
        $roomId = $booking->room_id;
        $checkInDay = Carbon::parse($booking->check_in)->toDateString();

        // Conflict: a prior booking for this room is still checked_in on the same day
        // AND its late_checkout_time would overlap with the requested early check-in time.
        // (A booking that is already checked_out is NOT a conflict.)
        $conflict = Booking::where('room_id', $roomId)
            ->where('id', '!=', $booking->id)
            ->where('status', 'checked_in')           // Only block if guest is still in the room
            ->whereDate('check_out', $checkInDay)
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

        $user = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $auditMsg = "[Early CI: {$time}".($userName ? " by {$userName}" : '').' on '.now()->format('Y-m-d H:i').']';
        $notes = $booking->notes ? $booking->notes."\n".$auditMsg : $auditMsg;

        $booking->update([
            'early_checkin_time' => $time,
            'notes' => $notes,
        ]);

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Late Checkout ─────────────────────────────────────────────────────────
    public function lateCheckout(Request $request, Booking $booking)
    {
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        $time = $request->input('time');
        $roomId = $booking->room_id;
        $checkOutDay = Carbon::parse($booking->check_out)->toDateString();

        // Conflict: another booking starts on this room the same checkout day
        // and its early_checkin_time (or standard noon) is <= the requested late time
        $conflict = Booking::where('room_id', $roomId)
            ->where('id', '!=', $booking->id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('check_in', $checkOutDay)
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

        $user = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $auditMsg = "[Late CO: {$time}".($userName ? " by {$userName}" : '').' on '.now()->format('Y-m-d H:i').']';
        $notes = $booking->notes ? $booking->notes."\n".$auditMsg : $auditMsg;

        $booking->update([
            'late_checkout_time' => $time,
            'notes' => $notes,
        ]);

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Reservation Extension ─────────────────────────────────────────────────
    public function extendReservation(Request $request, Booking $booking)
    {
        $request->validate([
            'new_check_out' => 'required|date|after:'.$booking->check_out,
        ]);

        // IMPORTANT: for multi-segment (room-change) stays, extensions must continue
        // from the LAST segment (latest check_out), not from booking.room_id.
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

        $oldCheckOut = $lastSegment->check_out;
        $newCheckOut = $request->input('new_check_out');
        $roomId = $lastSegment->room_id;

        // Overlap check for the extension gap [current check_out → new check_out]
        // IMPORTANT: use segments (not bookings.room_id) so split-stays are handled correctly.
        $conflictSegment = BookingSegment::with(['booking.room.roomType'])
            ->where('room_id', $roomId)
            ->where('booking_id', '!=', $booking->id)
            ->where('status', '!=', 'cancelled')
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

        $user = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $auditMsg = "[Extension: {$oldCheckOut} → {$newCheckOut}".($userName ? " by {$userName}" : '').' on '.now()->format('Y-m-d H:i').']';
        $notes = $booking->notes ? $booking->notes."\n".$auditMsg : $auditMsg;

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

        $user = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $hoursLabel = round(((int) $validated['extend_minutes']) / 60, 2);
        $auditMsg = "[Hourly Extension: +{$hoursLabel}h to ".$newCheckOutAt->format('Y-m-d H:i').($userName ? " by {$userName}" : '').' on '.now()->format('Y-m-d H:i').']';
        $notes = $booking->notes ? $booking->notes."\n".$auditMsg : $auditMsg;

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
            'new_check_out' => 'required|date|after:'.$booking->check_out,
        ]);

        $oldCheckOut = $booking->check_out;
        $newCheckOut = $validated['new_check_out'];
        $newRoomId = $validated['new_room_id'];

        // Calculate price for the new segment
        $newRoom = Room::with(['roomType.tax', 'roomType.ratePlans'])->findOrFail($newRoomId);
        $nights = Carbon::parse($oldCheckOut)->diffInDays(Carbon::parse($newCheckOut));

        $rt = $newRoom->roomType;
        // Try to match existing rate plan if possible
        $ratePlan = $booking->rate_plan_id ? $rt->ratePlans->find($booking->rate_plan_id) : $rt->ratePlans->first();

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

        // Add segment — inherit the parent booking's status so a checked_in guest
        // shows as "occupied" (not "reserved") on the room chart for the new room.
        $segmentStatus = $booking->status === 'checked_in' ? 'checked_in' : 'confirmed';

        $newSegment = BookingSegment::create([
            'booking_id' => $booking->id,
            'room_id' => $newRoomId,
            'check_in' => $oldCheckOut,
            'check_out' => $newCheckOut,
            'check_in_at' => Carbon::parse($oldCheckOut)->startOfDay(),
            'check_out_at' => Carbon::parse($newCheckOut)->startOfDay(),
            'rate_plan_id' => $ratePlan ? $ratePlan->id : null,
            'adults_count' => $booking->adults_count,
            'children_count' => $booking->children_count,
            'extra_beds_count' => $booking->extra_beds_count,
            'total_price' => $segmentTotal,
            'status' => $segmentStatus,
        ]);

        // Update main booking
        $user = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : '');
        $auditMsg = "[Split Stay: Room #{$newRoom->room_number} from {$oldCheckOut} to {$newCheckOut}".($userName ? " by {$userName}" : '').' on '.now()->format('Y-m-d H:i').']';
        $notes = $booking->notes ? $booking->notes."\n".$auditMsg : $auditMsg;

        $booking->update([
            'check_out' => $newCheckOut,
            'check_out_at' => Carbon::parse($newCheckOut)->startOfDay(),
            'total_price' => (float) $booking->total_price + $segmentTotal,
            'notes' => $notes,
        ]);

        return response()->json($booking->load(['segments.room', 'creator']));
    }

    public function reservationVoucher(Request $request, Booking $booking)
    {
        $booking->load(['room.roomType.tax', 'creator', 'bookingGroup']);

        $guestName = trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''));
        $guestName = $guestName !== '' ? $guestName : 'Guest';
        $roomNo = $booking->room?->room_number ?? '-';
        $roomType = $booking->room?->roomType?->name ?? '-';
        $bookingType = ($booking->booking_unit ?? 'day') === 'hour_package' ? 'Hourly Package' : 'Day Stay';
        $ci = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $co = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();
        $createdAt = $booking->created_at ? Carbon::parse($booking->created_at) : now();

        $grand = (float) ($booking->total_price ?? 0);
        $paid = (float) ($booking->deposit_amount ?? 0);
        $balance = max(0, $grand - $paid);
        $currency = 'INR';

        $rt = $booking->room?->roomType;
        $taxRate = (float) ($rt?->tax?->rate ?? 0);
        $divisor = 1 + ($taxRate > 0 ? $taxRate / 100 : 0);
        $beforeTax = $divisor > 0 ? ($grand / $divisor) : $grand;
        $gst = max(0, $grand - $beforeTax);

        $ratePlan = $rt?->ratePlans?->firstWhere('id', $booking->rate_plan_id);
        $extraBeds = (int) ($booking->extra_beds_count ?? 0);
        $extraBedCost = (float) ($rt?->extra_bed_cost ?? 0);
        $baseRoomTotal = $beforeTax;
        $extraHourTotal = 0.0;

        if (($booking->booking_unit ?? 'day') === 'hour_package' && $ratePlan) {
            $pkg = (float) ($ratePlan->package_price ?? $ratePlan->base_price ?? 0);
            $baseRoomTotal = $pkg + ($extraBeds > 0 ? ($extraBeds * $extraBedCost) : 0);
            $extraHourTotal = max(0, $beforeTax - $baseRoomTotal);
        }

        $title = "Reservation Voucher #{$booking->id}";
        $voucherNights = (string) max(1, $ci->diffInDays($co));
        $reservationNo = e((string) $booking->id);
        $bookingDate = e($createdAt->format('d M Y'));
        $checkInText = e($ci->format('d M Y, h:i A'));
        $checkOutText = e($co->format('d M Y, h:i A'));
        $guestNameEsc = e($guestName);
        $roomTypeEsc = e((string) $roomType);
        $hotelName = 'Grand Palace Hotel';
        $hotelAddress = '123 Royal Avenue, Downtown, City - 560001';
        $hotelPhone = '+91 98765 43210';
        $hotelEmail = 'reservations@grandpalace.com';
        $hotelWebsite = 'www.grandpalace.com';
        $adultCount = (int) ($booking->adults_count ?? 1);
        $childCount = (int) ($booking->children_count ?? 0);
        $contact = trim(((string) ($booking->phone ?? '')).(($booking->email ?? null) ? ' · '.(string) $booking->email : ''));
        $contact = $contact !== '' ? $contact : '-';
        $roomCount = max(1, $booking->segments()->distinct('room_id')->count('room_id'));
        $bedType = (string) ($rt?->bed_config ?? '-');
        $paymentStatus = strtoupper((string) ($booking->payment_status ?? 'pending'));
        $paymentMethod = strtoupper((string) ($booking->payment_method ?? 'N/A'));
        $guestCountLabel = $childCount > 0 ? 'Adults / Children' : 'Adults';
        $guestCountValue = $childCount > 0 ? ($adultCount.' / '.$childCount) : (string) $adultCount;
        $paymentMethodRow = $paid > 0
            ? "<div class='row'><span class='k'>Payment Method</span><span class='v'>".e($paymentMethod).'</span></div>'
            : '';
        $specialRequestsRaw = trim((string) preg_replace('/\[[^\]]+\]/', '', (string) ($booking->notes ?? '')));
        $specialRequestsShort = $specialRequestsRaw !== '' ? substr($specialRequestsRaw, 0, 80).(strlen($specialRequestsRaw) > 80 ? '...' : '') : '-';

        $html = "<!doctype html>
<html>
<head>
  <meta charset='utf-8'>
  <title>{$title}</title>
  <style>
    @page { size: A4 portrait; margin: 10mm; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #020617; margin: 0; font-size: 11px; line-height: 1.5; background: #fff; }
    .wrap { max-width: 720px; margin: 0 auto; }
    .head { margin-bottom: 14px; text-align: center; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
    .hotel-name { font-size: 18px; font-weight: 800; margin: 0; color: #020617; }
    .hotel-address { font-size: 10.5px; margin-top: 2px; color: #374151; }
    .head-title { font-size: 11px; font-weight: 700; margin: 8px 0 0 0; letter-spacing: .16em; text-transform: uppercase; color: #6b7280; }
    .head-sub { font-size: 9.5px; margin-top: 2px; color: #4b5563; }
    .info-row { width: 100%; border-collapse: collapse; margin: 12px 0 14px 0; }
    .info-row td { vertical-align: top; padding: 0; font-size: 10.5px; color: #111827; }
    .info-block { padding-right: 24px; }
    .info-label { display: block; font-size: 9px; text-transform: uppercase; letter-spacing: .08em; color: #4b5563; margin-bottom: 2px; font-weight: 600; }
    .info-value { display: block; font-weight: 700; color: #020617; }
    .info-spacer { height: 5px; }
    .section-title { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #4b5563; margin: 12px 0 6px 0; font-weight: 700; }
    .simple-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; border: 1px solid #d4d4d8; }
    .simple-table th,
    .simple-table td { padding: 5px 8px; font-size: 10.5px; border-top: 1px solid #e5e7eb; }
    .simple-table tr:first-child th { border-top: none; }
    .simple-table th { background: #f4f4f5; text-align: left; font-weight: 700; color: #111827; }
    .label { color: #111827; }
    .value { text-align: right; font-weight: 700; color: #020617; }
    .muted { color: #4b5563; font-size: 9.5px; margin-top: 10px; }
  </style>
</head>
<body>
  <div class='wrap'>
    <div class='head'>
      <p class='hotel-name'>".e($hotelName)."</p>
      <p class='hotel-address'>".e($hotelAddress)."</p>
      <p class='head-title'>Reservation Voucher</p>
      <p class='head-sub'>Reservation #{$reservationNo} · Booked on {$bookingDate}</p>
    </div>

    <table class='info-row'>
      <tr>
        <td class='info-block'>
          <span class='info-label'>Guest</span>
          <span class='info-value'>{$guestNameEsc}</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Guests</span>
          <span class='info-value'>{$guestCountValue}</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Contact</span>
          <span class='info-value'>".e($contact)."</span>
        </td>
        <td class='info-block'>
          <span class='info-label'>Stay</span>
          <span class='info-value'>{$checkInText} → {$checkOutText}</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Nights</span>
          <span class='info-value'>{$voucherNights}</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Status</span>
          <span class='info-value'>".e($paymentStatus)."</span>
        </td>
      </tr>
    </table>

    <div>
      <p class='section-title'>Room</p>
      <table class='simple-table'>
        <tr>
          <th>Field</th>
          <th style='text-align:right'>Value</th>
        </tr>
        <tr>
          <td class='label'>Room Type</td>
          <td class='value'>{$roomTypeEsc}</td>
        </tr>
        <tr>
          <td class='label'>Number of Rooms</td>
          <td class='value'>{$roomCount}</td>
        </tr>
        <tr>
          <td class='label'>Bed Type</td>
          <td class='value'>".e($bedType)."</td>
        </tr>
      </table>
    </div>

    <div>
      <p class='section-title'>Payment ({$currency})</p>
      <table class='simple-table'>
        <tr>
          <th>Description</th>
          <th style='text-align:right'>Amount</th>
        </tr>
        <tr>
          <td class='label'>Total Amount</td>
          <td class='value'>".number_format($grand, 2).'</td>
        </tr>';

        if ($extraHourTotal > 0) {
            $html .= "
        <tr>
          <td class='label'>Extra Hour(s) Included</td>
          <td class='value'>".number_format($extraHourTotal, 2).'</td>
        </tr>';
        }

        $html .= "
        <tr>
          <td class='label'>GST</td>
          <td class='value'>".number_format($gst, 2)."</td>
        </tr>
        <tr>
          <td class='label'>Total Before Tax</td>
          <td class='value'>".number_format($beforeTax, 2)."</td>
        </tr>
        <tr>
          <td class='label'>Amount Paid</td>
          <td class='value'>".number_format($paid, 2)."</td>
        </tr>
        <tr>
          <td class='label'>Balance</td>
          <td class='value'>".number_format($balance, 2).'</td>
        </tr>';

        if ($paid > 0) {
            $html .= "
        <tr>
          <td class='label'>Payment Method</td>
          <td class='value'>".e($paymentMethod).'</td>
        </tr>';
        }

        $html .= "
      </table>
    </div>

    <p class='section-title'>Notes</p>
    <p class='muted'>Special Requests: ".e($specialRequestsShort)."</p>
    <p class='muted'>Please carry a valid government photo ID for all adult guests. Check-in and check-out timings are subject to hotel policy and availability.</p>
  </div>
</body>
</html>";

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->download('Reservation_Voucher_'.$booking->id.'.pdf');
    }

    public function reservationBilling(Request $request, Booking $booking)
    {
        $booking->load(['room.roomType.tax']);

        $guestName = trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''));
        $guestName = $guestName !== '' ? $guestName : 'Guest';
        $roomNo = (string) ($booking->room?->room_number ?? '-');
        $roomType = (string) ($booking->room?->roomType?->name ?? '-');
        $checkIn = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $checkOut = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();
        $createdAt = $booking->created_at ? Carbon::parse($booking->created_at) : now();

        $grand = (float) ($booking->total_price ?? 0);
        $paid = (float) ($booking->deposit_amount ?? 0);
        $balance = max(0, $grand - $paid);
        $taxRate = (float) ($booking->room?->roomType?->tax?->rate ?? 0);
        $divisor = 1 + ($taxRate > 0 ? $taxRate / 100 : 0);
        $subTotal = $divisor > 0 ? ($grand / $divisor) : $grand;
        $taxAmount = max(0, $grand - $subTotal);

        $invoiceNo = 'BILL-'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT);
        $paymentStatus = strtoupper((string) ($booking->payment_status ?? 'pending'));
        $paymentMethod = strtoupper((string) ($booking->payment_method ?? 'N/A'));

        $html = "<!doctype html>
<html>
<head>
  <meta charset='utf-8'>
  <title>Billing Statement #{$booking->id}</title>
  <style>
    @page { size: A4 portrait; margin: 12mm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; margin: 0; font-size: 11px; }
    .wrap { max-width: 700px; margin: 0 auto; }
    .head { text-align: left; margin-bottom: 10px; }
    .head-title { font-size: 18px; font-weight: 800; margin: 0; }
    .head-sub { font-size: 10px; margin-top: 2px; color: #6b7280; }
    .info-row { width: 100%; border-collapse: collapse; margin: 10px 0 14px 0; }
    .info-row td { vertical-align: top; padding: 0; font-size: 10px; color: #374151; }
    .info-block { padding-right: 20px; }
    .info-label { display: block; font-size: 9px; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; margin-bottom: 2px; }
    .info-value { display: block; font-weight: 600; color: #111827; }
    .info-spacer { height: 6px; }
    .totals-table { width: 100%; border-collapse: collapse; }
    .totals-table th,
    .totals-table td { padding: 6px 0; font-size: 11px; }
    .totals-table tr + tr td { border-top: 1px solid #e5e7eb; }
    .label { text-align: left; color: #374151; }
    .amount { text-align: right; font-weight: 600; color: #111827; }
    .grand { font-size: 13px; font-weight: 800; }
    .balance { color: #b91c1c; }
    .muted { color: #6b7280; font-size: 9px; margin-top: 10px; }
  </style>
</head>
<body>
  <div class='wrap'>
    <div class='head'>
      <p class='head-title'>Billing Statement</p>
      <p class='head-sub'>Generated {$createdAt->format('d M Y, h:i A')}</p>
    </div>

    <table class='info-row'>
      <tr>
        <td class='info-block'>
          <span class='info-label'>Invoice No</span>
          <span class='info-value'>{$invoiceNo}</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Booking No</span>
          <span class='info-value'>#".e((string) $booking->id)."</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Guest</span>
          <span class='info-value'>".e($guestName)."</span>
        </td>
        <td class='info-block'>
          <span class='info-label'>Room</span>
          <span class='info-value'>#".e($roomNo).' · '.e($roomType)."</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Stay</span>
          <span class='info-value'>".e($checkIn->format('d M Y, h:i A')).' → '.e($checkOut->format('d M Y, h:i A'))."</span>
          <div class='info-spacer'></div>
          <span class='info-label'>Payment Status</span>
          <span class='info-value'>".e($paymentStatus)."</span>
        </td>
      </tr>
    </table>

    <table class='totals-table'>
      <tr>
        <td class='label'>Total (Before Tax)</td>
        <td class='amount'>INR ".number_format($subTotal, 2)."</td>
      </tr>
      <tr>
        <td class='label'>GST</td>
        <td class='amount'>INR ".number_format($taxAmount, 2)."</td>
      </tr>
      <tr>
        <td class='label grand'>Grand Total</td>
        <td class='amount grand'>INR ".number_format($grand, 2)."</td>
      </tr>
      <tr>
        <td class='label'>Total Paid</td>
        <td class='amount'>INR ".number_format($paid, 2)."</td>
      </tr>
      <tr>
        <td class='label balance'>Balance Pending</td>
        <td class='amount balance'>INR ".number_format($balance, 2)."</td>
      </tr>
      <tr>
        <td class='label'>Payment Method</td>
        <td class='amount'>".e($paymentMethod)."</td>
      </tr>
    </table>

    <p class='muted'>Thank you for staying with us. This is a system-generated bill.</p>
  </div>
</body>
</html>";

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->download('Billing_Statement_'.$booking->id.'.pdf');
    }

    public function destroy(Booking $booking)
    {
        // For split stays, booking->room may not reflect all rooms used. Fall back safely.
        $allRoomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->unique();
        Room::whereIn('id', $allRoomIds)->update(['status' => 'available']);
        $booking->delete();

        return response()->json(null, 204);
    }

    public function getAvailableRooms(Request $request)
    {
        $request->validate([
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'room_type_id' => 'nullable|exists:room_types,id',
            'exclude_booking_id' => 'nullable|integer',
            'exclude_room_id' => 'nullable|integer',
        ]);

        $checkInAt = Carbon::parse($request->check_in);
        $checkOutAt = Carbon::parse($request->check_out);
        $checkInDate = $checkInAt->toDateString();
        $checkOutDateExclusive = $this->dateEndExclusiveFromDateTime($checkOutAt);
        $typeId = $request->room_type_id;
        $excludeId = $request->exclude_booking_id;
        $excludeRoomId = $request->exclude_room_id;

        $rooms = Room::with('roomType')
            ->where('is_active', true)
            ->when($excludeRoomId, function ($q) use ($excludeRoomId) {
                $q->where('id', '!=', $excludeRoomId);
            })
            ->when($typeId, function ($q) use ($typeId) {
                $q->where('room_type_id', $typeId);
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
