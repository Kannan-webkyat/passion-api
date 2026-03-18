<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Models\BookingSegment;
use App\Models\BookingGroup;
use App\Models\RatePlan;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::with(['room.roomType', 'creator', 'bookingGroup'])
            ->when($request->booking_group_id, function($q) use ($request) {
                $q->where('booking_group_id', $request->booking_group_id);
            })
            ->orderBy('check_in')
            ->get();
    }

    public function chart(Request $request)
    {
        $start = Carbon::parse($request->query('start', Carbon::today()));
        // Show 14 days by default for better visibility
        $end   = Carbon::parse($request->query('end', Carbon::today()->addDays(13)));

        $rooms = Room::with(['roomType.tax', 'roomType.ratePlans', 'segments' => function ($q) use ($start, $end) {
            $q->where('check_out', '>=', $start)
              ->where('check_in',  '<=', $end)
              ->whereNotIn('status', ['cancelled'])
              ->with(['booking', 'ratePlan']);
        }])->get();

        return response()->json([
            'rooms'       => $rooms,
            'start'       => $start->toDateString(),
            'end'         => $end->toDateString(),
        ]);
    }

    public function summary(Request $request)
    {
        $date = Carbon::parse($request->query('date', Carbon::today()));
        $today = Carbon::today();

        $rooms = Room::with(['segments' => function ($q) use ($date) {
            $q->where('status', '!=', 'cancelled')
              ->where('check_in', '<=', $date)
              ->where('check_out', '>', $date);
        }])->get();

        $counts = [
            'total'           => $rooms->count(),
            'occupied'        => 0,
            'maintenance'     => 0,
            'dirty'           => 0,
            'cleaning'        => 0,
            'available'       => 0,
            'checkins_today'  => Booking::whereDate('check_in',  $today)->whereIn('status', ['confirmed','checked_in'])->count(),
            'checkouts_today' => Booking::whereDate('check_out', $today)->whereIn('status', ['checked_in','checked_out'])->count(),
        ];

        foreach ($rooms as $room) {
            if ($room->segments->isNotEmpty()) {
                $counts['occupied']++;
            } elseif ($room->status === 'maintenance') {
                $counts['maintenance']++;
            } elseif ($room->status === 'dirty') {
                $counts['dirty']++;
            } elseif ($room->status === 'cleaning') {
                $counts['cleaning']++;
            } else {
                $counts['available']++;
            }
        }

        return response()->json($counts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_ids'         => 'nullable|array',
            'room_ids.*'       => 'exists:rooms,id',
            'room_id'          => 'required_without:room_ids|exists:rooms,id',
            'first_name'       => 'required|string|max:255',
            'last_name'        => 'required|string|max:255',
            'email'            => 'nullable|email',
            'phone'            => 'nullable|string',
            'guest_identity_types' => 'nullable|array',
            'guest_identity_types.*' => 'nullable|string|max:255',
            'guest_identities' => 'nullable|array',
            'guest_identities.*' => 'nullable|string', // Base64 or paths
            'city'             => 'nullable|string|max:255',
            'country'          => 'nullable|string|max:255',
            'adults_count'     => 'required|integer|min:1',
            'children_count'   => 'nullable|integer|min:0',
            'infants_count'    => 'nullable|integer|min:0',
            'extra_beds_count' => 'nullable|integer|min:0',
            'check_in'         => 'required|date',
            'check_out'        => 'required|date|after:check_in',
            'estimated_arrival_time' => 'nullable|string',
            'total_price'      => 'required|numeric|min:0',
            'payment_status'   => 'nullable|in:pending,partial,paid,refunded',
            'payment_method'   => 'nullable|string',
            'deposit_amount'   => 'nullable|numeric|min:0',
            'status'           => 'nullable|in:pending,confirmed,checked_in,checked_out,cancelled',
            'notes'            => 'nullable|string',
            'group_name'       => 'nullable|string|max:255', // For group master
            'adult_breakfast_count' => 'nullable|integer|min:0',
            'child_breakfast_count' => 'nullable|integer|min:0',
            'rate_plan_id'     => 'nullable|exists:rate_plans,id',
        ]);

        $creatorId = $request->user()?->id;
        $roomIds = $request->input('room_ids', [$request->input('room_id')]);
        $checkIn = $validated['check_in'];
        $checkOut = $validated['check_out'];
        $status = $validated['status'] ?? 'confirmed';

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
                ]
            ], 422);
        }

        // 1. Availability Check (Overlap) - Using BookingSegment
        foreach ($roomIds as $roomId) {
            $overlap = BookingSegment::where('room_id', $roomId)
                ->where('status', '!=', 'cancelled')
                ->where(function ($query) use ($checkIn, $checkOut) {
                    $query->where('check_in', '<', $checkOut)
                          ->where('check_out', '>', $checkIn);
                })->exists();

            if ($overlap) {
                $room = Room::find($roomId);
                return response()->json([
                    'message' => "Room #{$room->room_number} is already reserved for the selected dates."
                ], 422);
            }

            // 2. Room Status Check (Maintenance/Dirty)
            $room = Room::findOrFail($roomId);
            if ($room->status === 'maintenance') {
                return response()->json(['message' => "Room #{$room->room_number} is under maintenance."], 422);
            }
            if ($room->status === 'dirty' && $status === 'checked_in') {
                return response()->json(['message' => "Room #{$room->room_number} requires cleaning before check-in."], 422);
            }
        }

        $isGroup = count($roomIds) > 1 || $request->filled('group_name');
        
        $bookingGroupId = null;
        if ($isGroup) {
            $group = BookingGroup::create([
                'name'           => $request->input('group_name') ?: ("Group - " . $validated['first_name'] . ' ' . $validated['last_name']),
                'contact_person' => $validated['first_name'] . ' ' . $validated['last_name'],
                'phone'          => $validated['phone'],
                'email'          => $validated['email'],
                'status'         => 'confirmed',
                'notes'          => $validated['notes'],
            ]);
            $bookingGroupId = $group->id;
        }

        // Handle Identity Images
        $imagePaths = [];
        if ($request->has('guest_identities')) {
            $images = $request->input('guest_identities') ?: [];
            foreach ($images as $index => $imageData) {
                if (!$imageData) continue;
                
                if (str_starts_with($imageData, 'data:image')) {
                    // Base64 from Camera or Upload
                    $format = str_contains($imageData, 'png') ? 'png' : 'jpg';
                    $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
                    $fileName = 'guest_id_' . time() . '_' . $index . '.' . $format;
                    \Illuminate\Support\Facades\Storage::disk('public')->put('identities/' . $fileName, $data);
                    $imagePaths[] = 'identities/' . $fileName;
                } else if ($request->hasFile("guest_identities.{$index}")) {
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

            // Apply individual room occupancy if provided
            if (isset($roomOccupancy[$roomId])) {
                $occ = $roomOccupancy[$roomId];
                $bookingData['adults_count'] = $occ['adults'] ?? $bookingData['adults_count'];
                $bookingData['children_count'] = $occ['children'] ?? ($bookingData['children_count'] ?? 0);
                $bookingData['infants_count'] = $occ['infants'] ?? ($bookingData['infants_count'] ?? 0);
                $bookingData['extra_beds_count'] = $occ['extra_beds'] ?? ($bookingData['extra_beds_count'] ?? 0);
                $bookingData['adult_breakfast_count'] = $occ['adult_breakfast'] ?? $bookingData['adult_breakfast_count'];
                $bookingData['child_breakfast_count'] = $occ['child_breakfast'] ?? $bookingData['child_breakfast_count'];
                if (!empty($occ['rate_plan_id'])) {
                    $bookingData['rate_plan_id'] = $occ['rate_plan_id'];
                }
            }

            $booking = Booking::create($bookingData);
            
            // Create initial Stay Segment
            BookingSegment::create([
                'booking_id'    => $booking->id,
                'room_id'       => $roomId,
                'check_in'      => $booking->check_in,
                'check_out'     => $booking->check_out,
                'rate_plan_id'  => $bookingData['rate_plan_id'],
                'adults_count'  => $bookingData['adults_count'],
                'children_count'=> $bookingData['children_count'],
                'extra_beds_count' => $bookingData['extra_beds_count'],
                'total_price'   => $bookingData['total_price'],
                'status'        => $booking->status === 'checked_in' ? 'checked_in' : 'confirmed',
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
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'notes'          => 'nullable|string',
        ]);

        $group = BookingGroup::create([
            'name'           => $validated['name'],
            'contact_person' => $validated['contact_person'] ?? '',
            'phone'          => $validated['phone'] ?? '',
            'email'          => $validated['email'] ?? '',
            'status'         => 'confirmed',
            'notes'          => $validated['notes'] ?? '',
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
            'room_id'          => 'exists:rooms,id',
            'first_name'       => 'string|max:255',
            'last_name'        => 'string|max:255',
            'email'            => 'nullable|email',
            'phone'            => 'nullable|string',
            'adults_count'     => 'integer|min:1',
            'children_count'   => 'nullable|integer|min:0',
            'infants_count'    => 'nullable|integer|min:0',
            'extra_beds_count' => 'nullable|integer|min:0',
            'check_in'         => 'date',
            'check_out'        => 'date|after:check_in',
            'total_price'      => 'numeric|min:0',
            'payment_status'   => 'in:pending,partial,paid,refunded',
            'payment_method'   => 'nullable|string',
            'deposit_amount'   => 'nullable|numeric|min:0',
            'status'           => 'in:pending,confirmed,checked_in,checked_out,cancelled',
            'booking_source'   => 'nullable|string',
            'notes'            => 'nullable|string',
            'guest_identity_types' => 'nullable|array',
            'guest_identity_types.*' => 'nullable|string|max:255',
            'guest_identities' => 'nullable|array',
            'guest_identities.*' => 'nullable|string', // Base64 or paths
            'adult_breakfast_count' => 'nullable|integer|min:0',
            'child_breakfast_count' => 'nullable|integer|min:0',
            'rate_plan_id'     => 'nullable|exists:rate_plans,id',
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
                ]
            ], 422);
        }

        // Checkout validation: must be paid
        if (isset($validated['status']) && $validated['status'] === 'checked_out' && $booking->status !== 'checked_out') {
            $currentPaymentStatus = $validated['payment_status'] ?? $booking->payment_status;
            if ($currentPaymentStatus !== 'paid') {
                return response()->json(['message' => 'Checkout not allowed until payment is fully paid'], 422);
            }

            // Early checkout: truncate the check_out date to free the room for other bookings
            $today = Carbon::today()->toDateString();
            $currentCheckOut = $validated['check_out'] ?? $booking->check_out;
            if ($currentCheckOut > $today) {
                $validated['check_out'] = $today;
                
                $user = $request->user();
                $userName = $user ? $user->name : ($user ? "User #{$user->id}" : "");
                $auditMsg = "[Early CO: on {$today}" . ($userName ? " by {$userName}" : "") . "]";
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
                if (!$imageData) continue;
                
                if (str_starts_with($imageData, 'data:image')) {
                    // New Base64 from Camera or Upload
                    $format = str_contains($imageData, 'png') ? 'png' : 'jpg';
                    $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
                    $fileName = 'guest_id_' . time() . '_' . $index . '.' . $format;
                    \Illuminate\Support\Facades\Storage::disk('public')->put('identities/' . $fileName, $data);
                    $newPaths[] = 'identities/' . $fileName;
                } else if ($request->hasFile("guest_identities.{$index}")) {
                    // New Direct File Upload
                    $newPaths[] = $request->file("guest_identities.{$index}")->store('identities', 'public');
                } else {
                    // Retain existing image path
                    $newPaths[] = $imageData;
                }
            }
            $validated['guest_identities'] = $newPaths;
        }

        $booking->update($validated);

        // Sync Stay Segments
        if (isset($validated['room_id']) || isset($validated['check_in']) || isset($validated['check_out']) || isset($validated['status'])) {
            $segmentCount = $booking->segments()->count();
            $newStatus = $validated['status'] ?? $booking->status;
            
            // If No segments exist, create a baseline one (safety for legacy data)
            if ($segmentCount === 0) {
                $booking->segments()->create([
                    'room_id'   => $booking->room_id,
                    'check_in'  => $booking->check_in,
                    'check_out' => $booking->check_out,
                    'total_price' => $booking->total_price,
                    'status'    => $newStatus,
                ]);
            } else if ($segmentCount === 1) {
                // Keep the single segment in perfect sync
                $booking->segments()->first()->update([
                    'room_id'   => $booking->room_id,
                    'check_in'  => $booking->check_in,
                    'check_out' => $booking->check_out,
                    'total_price' => $booking->total_price,
                    'status'    => $newStatus,
                ]);
            } else if (isset($validated['status'])) {
                // For multi-segment stays, when checking in, find the segment that corresponds to "now" or the first one
                if ($validated['status'] === 'checked_in') {
                    $firstSegment = $booking->segments()->orderBy('check_in', 'asc')->first();
                    if ($firstSegment) {
                        $firstSegment->update(['status' => 'checked_in']);
                    }
                } elseif ($validated['status'] === 'checked_out' || $validated['status'] === 'cancelled') {
                    // Update all segments if the whole booking is cancelled/checked_out
                    $booking->segments()->update(['status' => $validated['status']]);
                }
            }
        }

        // Sync room status — for split stays, ALL rooms across all segments must be updated.
        if (isset($validated['status'])) {
            $roomStatus = match($validated['status']) {
                'checked_in'  => 'occupied',
                'checked_out' => 'dirty',
                'cancelled'   => 'available',
                default       => $booking->room->status,
            };

            // Collect every distinct room touched by this booking's segments
            $allRoomIds = $booking->segments()->pluck('room_id')->push($booking->room_id)->unique();

            Room::whereIn('id', $allRoomIds)->update(['status' => $roomStatus]);
        }

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Early Check-In ────────────────────────────────────────────────────────
    public function earlyCheckin(Request $request, Booking $booking)
    {
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        $time       = $request->input('time');
        $roomId     = $booking->room_id;
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

        $user     = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : "");
        $auditMsg = "[Early CI: {$time}" . ($userName ? " by {$userName}" : "") . " on " . now()->format('Y-m-d H:i') . "]";
        $notes    = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $booking->update([
            'early_checkin_time' => $time,
            'notes'              => $notes,
        ]);

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Late Checkout ─────────────────────────────────────────────────────────
    public function lateCheckout(Request $request, Booking $booking)
    {
        $request->validate([
            'time' => 'required|date_format:H:i',
        ]);

        $time         = $request->input('time');
        $roomId       = $booking->room_id;
        $checkOutDay  = Carbon::parse($booking->check_out)->toDateString();

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

        $user     = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : "");
        $auditMsg = "[Late CO: {$time}" . ($userName ? " by {$userName}" : "") . " on " . now()->format('Y-m-d H:i') . "]";
        $notes    = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $booking->update([
            'late_checkout_time' => $time,
            'notes'              => $notes,
        ]);

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    // ── Reservation Extension ─────────────────────────────────────────────────
    public function extendReservation(Request $request, Booking $booking)
    {
        $request->validate([
            'new_check_out' => 'required|date|after:' . $booking->check_out,
        ]);

        $oldCheckOut = $booking->check_out;
        $newCheckOut = $request->input('new_check_out');
        $roomId      = $booking->room_id;

        // Overlap check for the extension gap [current check_out → new check_out]
        $conflict = Booking::with(['room.roomType'])
            ->where('room_id', $roomId)
            ->where('id', '!=', $booking->id)
            ->where('status', '!=', 'cancelled')
            ->where('check_in', '<', $newCheckOut)
            ->where('check_out', '>', $oldCheckOut)
            ->first();

        if ($conflict) {
            return response()->json([
                'message' => 'Room Conflict Detected',
                'conflict' => $conflict,
                'suggestion' => 'Move incoming guest or split stay.'
            ], 409);
        }

        // Recalculate total price using rate plan if available
        $room = $booking->room()->with(['roomType.tax', 'roomType.ratePlans'])->first();
        $extraNights = Carbon::parse($oldCheckOut)->diffInDays(Carbon::parse($newCheckOut));
        $extraCost   = 0;

        if ($room?->roomType) {
            $rt = $room->roomType;
            $ratePlan = null;
            if ($booking->rate_plan_id) {
                $ratePlan = $rt->ratePlans->find($booking->rate_plan_id);
            }
            if (!$ratePlan) {
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

        $user     = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : "");
        $auditMsg = "[Extension: {$oldCheckOut} → {$newCheckOut}" . ($userName ? " by {$userName}" : "") . " on " . now()->format('Y-m-d H:i') . "]";
        $notes    = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $booking->update([
            'check_out'   => $newCheckOut,
            'total_price' => $newTotalPrice,
            'notes'       => $notes,
        ]);

        // Update the segment to match the extension
        $lastSegment = $booking->segments()->orderBy('check_out', 'desc')->first();
        if ($lastSegment && $lastSegment->room_id == $roomId && $lastSegment->check_out == $oldCheckOut) {
            $lastSegment->update([
                'check_out' => $newCheckOut,
                'total_price' => (float)$lastSegment->total_price + $extraCost,
            ]);
        }

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup', 'segments.room']));
    }

    /**
     * Handle Split Stay: Add a new segment to an existing booking.
     */
    public function splitStay(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'new_room_id'   => 'required|exists:rooms,id',
            'new_check_out' => 'required|date|after:' . $booking->check_out,
        ]);

        $oldCheckOut = $booking->check_out;
        $newCheckOut = $validated['new_check_out'];
        $newRoomId   = $validated['new_room_id'];

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
            'booking_id'    => $booking->id,
            'room_id'       => $newRoomId,
            'check_in'      => $oldCheckOut,
            'check_out'     => $newCheckOut,
            'rate_plan_id'  => $ratePlan ? $ratePlan->id : null,
            'adults_count'  => $booking->adults_count,
            'children_count'=> $booking->children_count,
            'extra_beds_count' => $booking->extra_beds_count,
            'total_price'   => $segmentTotal,
            'status'        => $segmentStatus,
        ]);

        // Update main booking
        $user     = $request->user();
        $userName = $user ? $user->name : ($user ? "User #{$user->id}" : "");
        $auditMsg = "[Split Stay: Room #{$newRoom->room_number} from {$oldCheckOut} to {$newCheckOut}" . ($userName ? " by {$userName}" : "") . " on " . now()->format('Y-m-d H:i') . "]";
        $notes    = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $booking->update([
            'check_out'   => $newCheckOut,
            'total_price' => (float)$booking->total_price + $segmentTotal,
            'notes'       => $notes,
        ]);

        return response()->json($booking->load(['segments.room', 'creator']));
    }

    public function destroy(Booking $booking)
    {
        $booking->room->update(['status' => 'available']);
        $booking->delete();
        return response()->json(null, 204);
    }

    public function getAvailableRooms(Request $request)
    {
        $request->validate([
            'check_in'  => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'room_type_id' => 'nullable|exists:room_types,id',
            'exclude_booking_id' => 'nullable|integer',
            'exclude_room_id'    => 'nullable|integer',
        ]);

        $checkIn  = $request->check_in;
        $checkOut = $request->check_out;
        $typeId   = $request->room_type_id;
        $excludeId = $request->exclude_booking_id;
        $excludeRoomId = $request->exclude_room_id;

        $rooms = Room::with('roomType')
            ->where('status', '!=', 'maintenance')
            ->when($excludeRoomId, function($q) use ($excludeRoomId) {
                $q->where('id', '!=', $excludeRoomId);
            })
            ->when($typeId, function($q) use ($typeId) {
                $q->where('room_type_id', $typeId);
            })
            ->whereDoesntHave('bookings', function($q) use ($checkIn, $checkOut, $excludeId) {
                $q->where('status', '!=', 'cancelled')
                  ->where('check_in', '<', $checkOut)
                  ->where('check_out', '>', $checkIn)
                  ->when($excludeId, function($sq) use ($excludeId) {
                      $sq->where('id', '!=', $excludeId);
                  });
            })
            ->get();

        return response()->json($rooms);
    }
}
