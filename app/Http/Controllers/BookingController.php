<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Models\BookingGroup;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function index()
    {
        return Booking::with(['room.roomType', 'creator', 'bookingGroup'])->orderBy('check_in')->get();
    }

    public function chart(Request $request)
    {
        $start = Carbon::parse($request->query('start', Carbon::today()));
        $end   = Carbon::parse($request->query('end', Carbon::today()->addDays(6)));

        $rooms = Room::with(['roomType.tax', 'bookings' => function ($q) use ($start, $end) {
            $q->where('check_out', '>=', $start)
              ->where('check_in',  '<=', $end)
              ->whereNotIn('status', ['cancelled']);
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

        // Mutually Exclusive Logic: Priority Precedence
        // 1. Occupied/Reserved: Has an active booking for this date
        // 2. Maintenance: Physical room status (checked if not occupied)
        // 3. Housekeeping (Dirty/Cleaning): Physical status (checked if not occupied/maintenance)
        // 4. Available: Everything else

        $rooms = Room::with(['bookings' => function ($q) use ($date) {
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
            if ($room->bookings->isNotEmpty()) {
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

        // 1. Availability Check (Overlap)
        foreach ($roomIds as $roomId) {
            $overlap = Booking::where('room_id', $roomId)
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
            
            $bookingData['room_id'] = $roomId;
            $bookingData['created_by'] = $creatorId;
            $bookingData['booking_group_id'] = $bookingGroupId;
            $bookingData['guest_identities'] = $imagePaths;
            $bookingData['adult_breakfast_count'] = $validated['adult_breakfast_count'] ?? 0;
            $bookingData['child_breakfast_count'] = $validated['child_breakfast_count'] ?? 0;

            // Apply individual room occupancy if provided
            if (isset($roomOccupancy[$roomId])) {
                $occ = $roomOccupancy[$roomId];
                $bookingData['adults_count'] = $occ['adults'] ?? $bookingData['adults_count'];
                $bookingData['children_count'] = $occ['children'] ?? ($bookingData['children_count'] ?? 0);
                $bookingData['infants_count'] = $occ['infants'] ?? ($bookingData['infants_count'] ?? 0);
                $bookingData['extra_beds_count'] = $occ['extra_beds'] ?? ($bookingData['extra_beds_count'] ?? 0);
                $bookingData['adult_breakfast_count'] = $occ['adult_breakfast'] ?? $bookingData['adult_breakfast_count'];
                $bookingData['child_breakfast_count'] = $occ['child_breakfast'] ?? $bookingData['child_breakfast_count'];
            }

            $booking = Booking::create($bookingData);
            
            if (($validated['status'] ?? '') === 'checked_in') {
                Room::findOrFail($roomId)->update(['status' => 'occupied']);
            }
            
            $bookings[] = $booking->load(['room.roomType.tax', 'creator', 'bookingGroup']);
        }

        return response()->json($isGroup ? $bookings : $bookings[0], 201);
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
            $today = \Carbon\Carbon::today()->toDateString();
            $currentCheckOut = $validated['check_out'] ?? $booking->check_out;
            if ($currentCheckOut > $today) {
                $validated['check_out'] = $today;
                
                $userId = $request->user()?->id;
                $auditMsg = "[Early CO: on {$today}" . ($userId ? " by User #{$userId}" : "") . "]";
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

        // Sync room status
        if (isset($validated['status'])) {
            $roomStatus = match($validated['status']) {
                'checked_in'   => 'occupied',
                'checked_out'  => 'dirty',
                'cancelled'    => 'available',
                default        => $booking->room->status,
            };
            $booking->room->update(['status' => $roomStatus]);
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

        $userId   = $request->user()?->id;
        $auditMsg = "[Early CI: {$time} by User #{$userId} on " . now()->format('Y-m-d H:i') . "]";
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

        $userId   = $request->user()?->id;
        $auditMsg = "[Late CO: {$time} by User #{$userId} on " . now()->format('Y-m-d H:i') . "]";
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
        $overlap = Booking::where('room_id', $roomId)
            ->where('id', '!=', $booking->id)
            ->where('status', '!=', 'cancelled')
            ->where('check_in', '<', $newCheckOut)
            ->where('check_out', '>', $oldCheckOut)
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'Room is already reserved for the selected extension period.',
            ], 422);
        }

        // Recalculate total price: add nightly rate × additional nights
        $room = $booking->room()->with('roomType.tax')->first();
        $extraNights = Carbon::parse($oldCheckOut)->diffInDays(Carbon::parse($newCheckOut));
        $extraCost   = 0;
        if ($room?->roomType) {
            $rt        = $room->roomType;
            $extraBeds = $booking->extra_beds_count ?? 0;
            $adults    = $booking->adults_count ?? 1;
            $children  = $booking->children_count ?? 0;
            
            $baseNightCost = ($rt->base_price * $extraNights) + ($rt->extra_bed_cost * $extraBeds * $extraNights);
            
            $adultB   = $booking->adult_breakfast_count ?? 0;
            $childB   = $booking->child_breakfast_count ?? 0;
            $breakfastCost = (($rt->breakfast_price * $adultB) + ($rt->child_breakfast_price * $childB)) * $extraNights;
            $baseNightCost += $breakfastCost;
            
            $extraCost = $baseNightCost;
            if ($rt->tax) {
                $extraCost += $extraCost * ($rt->tax->rate / 100);
            }
        }
        $newTotalPrice = (float) $booking->total_price + $extraCost;

        $userId   = $request->user()?->id;
        $auditMsg = "[Extension: {$oldCheckOut} → {$newCheckOut} by User #{$userId} on " . now()->format('Y-m-d H:i') . "]";
        $notes    = $booking->notes ? $booking->notes . "\n" . $auditMsg : $auditMsg;

        $booking->update([
            'check_out'   => $newCheckOut,
            'total_price' => $newTotalPrice,
            'notes'       => $notes,
        ]);

        return response()->json($booking->load(['room.roomType.tax', 'creator', 'bookingGroup']));
    }

    public function destroy(Booking $booking)
    {
        $booking->room->update(['status' => 'available']);
        $booking->delete();
        return response()->json(null, 204);
    }
}
