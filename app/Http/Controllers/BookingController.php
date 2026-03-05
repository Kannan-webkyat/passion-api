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

        $rooms = Room::with(['roomType', 'bookings' => function ($q) use ($start, $end) {
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

    public function summary()
    {
        $today = Carbon::today();

        return response()->json([
            'total'          => Room::count(),
            'available'      => Room::where('status', 'available')->count(),
            'occupied'       => Room::where('status', 'occupied')->count(),
            'maintenance'    => Room::where('status', 'maintenance')->count(),
            'dirty'          => Room::where('status', 'dirty')->count(),
            'checkins_today' => Booking::whereDate('check_in',  $today)->whereIn('status', ['confirmed','checked_in'])->count(),
            'checkouts_today'=> Booking::whereDate('check_out', $today)->whereIn('status', ['checked_in','checked_out'])->count(),
        ]);
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
            'identity_type'    => 'nullable|string|max:255',
            'identity_image'   => 'nullable|string', // Can be base64 or path
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
            'booking_source'   => 'nullable|string',
            'source_reference' => 'nullable|string|max:255',
            'notes'            => 'nullable|string',
            'group_name'       => 'nullable|string|max:255', // For group master
        ]);

        $creatorId = $request->user()?->id;
        $roomIds = $request->input('room_ids', [$request->input('room_id')]);
        $checkIn = $validated['check_in'];
        $checkOut = $validated['check_out'];
        $status = $validated['status'] ?? 'confirmed';

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

        // Handle Identity Image
        $imagePath = null;
        if ($request->has('identity_image')) {
            $imageData = $request->input('identity_image');
            if (str_starts_with($imageData, 'data:image')) {
                // Base64 from Camera
                $format = str_contains($imageData, 'png') ? 'png' : 'jpg';
                $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
                $fileName = 'guest_id_' . time() . '.' . $format;
                \Storage::disk('public')->put('identities/' . $fileName, $data);
                $imagePath = 'identities/' . $fileName;
            } else if ($request->hasFile('identity_image')) {
                // Direct File Upload
                $imagePath = $request->file('identity_image')->store('identities', 'public');
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
            $bookingData['identity_image'] = $imagePath;

            // Apply individual room occupancy if provided
            if (isset($roomOccupancy[$roomId])) {
                $occ = $roomOccupancy[$roomId];
                $bookingData['adults_count'] = $occ['adults'] ?? $bookingData['adults_count'];
                $bookingData['children_count'] = $occ['children'] ?? ($bookingData['children_count'] ?? 0);
                $bookingData['infants_count'] = $occ['infants'] ?? ($bookingData['infants_count'] ?? 0);
                $bookingData['extra_beds_count'] = $occ['extra_beds'] ?? ($bookingData['extra_beds_count'] ?? 0);
            }

            $booking = Booking::create($bookingData);
            
            if (($validated['status'] ?? '') === 'checked_in') {
                Room::findOrFail($roomId)->update(['status' => 'occupied']);
            }
            
            $bookings[] = $booking->load(['room.roomType', 'creator', 'bookingGroup']);
        }

        return response()->json($isGroup ? $bookings : $bookings[0], 201);
    }

    public function show(Booking $booking)
    {
        return $booking->load(['room.roomType', 'creator', 'bookingGroup']);
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
        ]);

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

        return response()->json($booking->load(['room.roomType', 'creator']));
    }

    public function destroy(Booking $booking)
    {
        $booking->room->update(['status' => 'available']);
        $booking->delete();
        return response()->json(null, 204);
    }
}
