<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesHousekeepingPermissions;
use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Events\BookingChargesUpdated;
use App\Events\HousekeepingStateUpdated;
use App\Models\Booking;
use App\Models\BookingExtraCharge;
use App\Models\BookingSegment;
use App\Models\LaundryRequest;
use App\Models\LaundryRequestLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LaundryRequestController extends Controller
{
    use AuthorizesHousekeepingPermissions;
    use AuthorizesSpatiePermissions;

    /**
     * Checked-in segment overlapping “today” for a room (same window as daily room cleaning).
     */
    private function activeSegmentForRoomToday(int $roomId): ?BookingSegment
    {
        $serviceDay = Carbon::today();
        $dayStart = $serviceDay->copy()->startOfDay();
        $dayEnd = $serviceDay->copy()->addDay()->startOfDay();

        return BookingSegment::query()
            ->where('room_id', '=', $roomId, 'and')
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $dayEnd)
            ->where('check_out_at', '>', $dayStart)
            ->whereHas('booking', function ($q) {
                $q->where('status', '=', 'checked_in');
            })
            ->with(['booking', 'room'])
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRequest(LaundryRequest $r): array
    {
        $r->loadMissing(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);

        $linesTotal = (float) $r->lines->sum('line_total');
        $expressExtra = $r->express ? (float) ($r->express_surcharge_amount ?? 0) : 0.0;
        $grand = round($linesTotal + $expressExtra, 2);

        return [
            'id' => $r->id,
            'booking_id' => (int) $r->booking_id,
            'room_id' => (int) $r->room_id,
            'room_number' => (string) ($r->room?->room_number ?? ''),
            'guest_name' => (string) $r->guest_name,
            'pickup_at' => $r->pickup_at?->toIso8601String(),
            'notes' => $r->notes,
            'damage_notes' => $r->damage_notes,
            'express' => (bool) $r->express,
            'express_surcharge_amount' => round((float) ($r->express_surcharge_amount ?? 0), 2),
            'status' => (string) $r->status,
            'pickup_items' => $r->pickup_items ?? [],
            'posted_at' => $r->posted_at?->toIso8601String(),
            'posted_amount' => $r->posted_amount !== null ? round((float) $r->posted_amount, 2) : null,
            'picked_up_at' => $r->picked_up_at?->toIso8601String(),
            'ready_at' => $r->ready_at?->toIso8601String(),
            'delivered_at' => $r->delivered_at?->toIso8601String(),
            'created_by' => $r->created_by ? (int) $r->created_by : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'lines' => $r->lines->map(fn(LaundryRequestLine $ln) => [
                'id' => $ln->id,
                'item_type' => $ln->item_type,
                'service_type' => $ln->service_type,
                'qty' => (float) $ln->qty,
                'unit_price' => round((float) $ln->unit_price, 2),
                'line_total' => round((float) $ln->line_total, 2),
            ])->values()->all(),
            'lines_total' => round($linesTotal, 2),
            'grand_total' => $grand,
        ];
    }

    private function broadcastCharges(Booking $booking, float $added): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $bookingId = (int) $booking->id;
        $extra = (float) ($booking->extra_charges ?? 0);
        App::terminating(function () use ($bookingId, $extra, $added) {
            try {
                event(new BookingChargesUpdated(
                    $bookingId,
                    $extra,
                    round($added, 2),
                    'Laundry posted to room folio'
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Checked-in segments overlapping a calendar day (same window as daily room cleaning / laundry prefill).
     *
     * @return \Illuminate\Support\Collection<int, BookingSegment>
     */
    private function segmentsCheckedInForDay(Carbon $serviceDay): \Illuminate\Support\Collection
    {
        $dayStart = $serviceDay->copy()->startOfDay();
        $dayEnd = $serviceDay->copy()->addDay()->startOfDay();

        return BookingSegment::query()
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $dayEnd)
            ->where('check_out_at', '>', $dayStart)
            ->whereHas('booking', function ($q) {
                $q->where('status', '=', 'checked_in');
            })
            ->whereHas('room', function ($q) {
                $q->where('is_active', true);
            })
            ->with(['room:id,room_number'])
            ->orderBy('room_id')
            ->get()
            ->unique('room_id')
            ->values();
    }

    /**
     * Rooms with an active checked-in stay today (for laundry request room picker).
     */
    public function checkedInRooms(Request $request)
    {
        $this->allowHousekeepingLaundryView();

        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);

        $day = isset($validated['date']) ? Carbon::parse($validated['date']) : Carbon::today();

        $rows = $this->segmentsCheckedInForDay($day)->map(function (BookingSegment $seg) {
            return [
                'id' => (int) $seg->room_id,
                'room_number' => (string) ($seg->room?->room_number ?? ''),
            ];
        })->filter(fn(array $r) => $r['room_number'] !== '');

        $sorted = $rows->sort(function (array $a, array $b) {
            return strnatcasecmp((string) $a['room_number'], (string) $b['room_number']);
        })->values()->all();

        return response()->json($sorted);
    }

    public function prefill(Request $request)
    {
        $this->allowHousekeepingLaundryView();

        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $roomId = (int) $validated['room_id'];
        $seg = $this->activeSegmentForRoomToday($roomId);
        if (! $seg) {
            return response()->json(['message' => 'No checked-in stay on this room for today.'], 422);
        }

        if (! empty($validated['booking_id']) && (int) $seg->booking_id !== (int) $validated['booking_id']) {
            return response()->json(['message' => 'Booking does not match the active stay on this room.'], 422);
        }

        /** @var Booking $booking */
        $booking = $seg->booking;
        $guestName = trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''));

        return response()->json([
            'room_id' => $roomId,
            'room_number' => (string) ($seg->room?->room_number ?? ''),
            'booking_id' => (int) $booking->id,
            'guest_name' => $guestName !== '' ? $guestName : 'Guest',
        ]);
    }

    public function index(Request $request)
    {
        $this->allowHousekeepingLaundryView();

        $validated = $request->validate([
            'queue' => 'nullable|string|in:all,pending_pickup,processing,ready,delivered',
            'unposted' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:200',
            'booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $queue = (string) ($validated['queue'] ?? 'all');
        $limit = (int) ($validated['limit'] ?? 100);
        $unposted = array_key_exists('unposted', $validated) && $validated['unposted'];

        $q = LaundryRequest::query()
            ->with(['room:id,room_number', 'booking:id,first_name,last_name', 'lines'])
            ->orderByDesc('id');

        if (! empty($validated['booking_id'])) {
            $q->where('booking_id', '=', (int) $validated['booking_id']);
        }

        if ($queue === 'pending_pickup') {
            $q->where('status', '=', LaundryRequest::STATUS_PENDING_PICKUP);
        } elseif ($queue === 'processing') {
            $q->whereIn('status', [LaundryRequest::STATUS_PICKED_UP, LaundryRequest::STATUS_PROCESSING]);
        } elseif ($queue === 'ready') {
            $q->where('status', '=', LaundryRequest::STATUS_READY);
        } elseif ($queue === 'delivered') {
            $q->where('status', '=', LaundryRequest::STATUS_DELIVERED);
            if ($unposted) {
                $q->whereNull('posted_at');
            }
        }

        $rows = $q->limit($limit)->get();

        return response()->json([
            'data' => $rows->map(fn(LaundryRequest $r) => $this->formatRequest($r))->values()->all(),
        ]);
    }

    public function store(Request $request)
    {
        $this->allowHousekeepingLaundryOperate();

        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'booking_id' => 'nullable|integer|exists:bookings,id',
            'guest_name' => 'nullable|string|max:255',
            'pickup_at' => 'required|date',
            'notes' => 'nullable|string|max:5000',
            'damage_notes' => 'nullable|string|max:5000',
            'express' => 'nullable|boolean',
            'express_surcharge_amount' => 'nullable|numeric|min:0',
        ]);

        $roomId = (int) $validated['room_id'];
        $seg = $this->activeSegmentForRoomToday($roomId);
        if (! $seg) {
            return response()->json(['message' => 'No checked-in stay on this room for today.'], 422);
        }

        if (! empty($validated['booking_id']) && (int) $seg->booking_id !== (int) $validated['booking_id']) {
            return response()->json(['message' => 'Booking does not match the active stay on this room.'], 422);
        }

        /** @var Booking $booking */
        $booking = $seg->booking;
        $guestName = isset($validated['guest_name']) ? trim((string) $validated['guest_name']) : '';
        if ($guestName === '') {
            $guestName = trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''));
        }
        if ($guestName === '') {
            $guestName = 'Guest';
        }

        $express = (bool) ($validated['express'] ?? false);
        $surcharge = $express ? round((float) ($validated['express_surcharge_amount'] ?? 0), 2) : 0.0;

        $lr = LaundryRequest::create([
            'booking_id' => (int) $booking->id,
            'room_id' => $roomId,
            'guest_name' => $guestName,
            'pickup_at' => Carbon::parse($validated['pickup_at']),
            'notes' => $validated['notes'] ?? null,
            'damage_notes' => $validated['damage_notes'] ?? null,
            'express' => $express,
            'express_surcharge_amount' => $surcharge,
            'status' => LaundryRequest::STATUS_PENDING_PICKUP,
            'created_by' => Auth::id(),
        ]);

        $lr->load(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);
        HousekeepingStateUpdated::dispatchIfEnabled([$roomId], 'laundry_request_created');

        return response()->json($this->formatRequest($lr->fresh(['lines'])), 201);
    }

    public function show(LaundryRequest $laundryRequest)
    {
        $this->allowHousekeepingLaundryView();
        $laundryRequest->load(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);

        return response()->json($this->formatRequest($laundryRequest));
    }

    public function update(Request $request, LaundryRequest $laundryRequest)
    {
        $this->allowHousekeepingLaundryOperate();

        if ($laundryRequest->posted_at) {
            return response()->json(['message' => 'This laundry request is already posted to the folio.'], 422);
        }

        $validated = $request->validate([
            'guest_name' => 'sometimes|string|max:255',
            'pickup_at' => 'sometimes|date',
            'notes' => 'nullable|string|max:5000',
            'damage_notes' => 'nullable|string|max:5000',
            'express' => 'sometimes|boolean',
            'express_surcharge_amount' => 'nullable|numeric|min:0',
        ]);

        if (array_key_exists('guest_name', $validated)) {
            $laundryRequest->guest_name = trim((string) $validated['guest_name']);
        }
        if (array_key_exists('pickup_at', $validated)) {
            $laundryRequest->pickup_at = Carbon::parse($validated['pickup_at']);
        }
        if (array_key_exists('notes', $validated)) {
            $laundryRequest->notes = $validated['notes'];
        }
        if (array_key_exists('damage_notes', $validated)) {
            $laundryRequest->damage_notes = $validated['damage_notes'];
        }
        if (array_key_exists('express', $validated)) {
            $laundryRequest->express = (bool) $validated['express'];
            if (! $laundryRequest->express) {
                $laundryRequest->express_surcharge_amount = 0;
            }
        }
        if (array_key_exists('express_surcharge_amount', $validated) && $laundryRequest->express) {
            $laundryRequest->express_surcharge_amount = round((float) ($validated['express_surcharge_amount'] ?? 0), 2);
        }

        $laundryRequest->save();
        $laundryRequest->load(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);
        HousekeepingStateUpdated::dispatchIfEnabled([(int) $laundryRequest->room_id], 'laundry_request_updated');

        return response()->json($this->formatRequest($laundryRequest));
    }

    public function pickup(Request $request, LaundryRequest $laundryRequest)
    {
        $this->allowHousekeepingLaundryOperate();

        if ($laundryRequest->posted_at) {
            return response()->json(['message' => 'This laundry request is already posted to the folio.'], 422);
        }

        if ($laundryRequest->status !== LaundryRequest::STATUS_PENDING_PICKUP) {
            return response()->json(['message' => 'Pickup is only allowed when status is pending pickup.'], 422);
        }

        $validated = $request->validate([
            'pickup_items' => 'required|array|min:1',
            'pickup_items.*.label' => 'required|string|max:120',
            'pickup_items.*.qty' => 'required|numeric|min:0.01',
        ]);

        $items = [];
        foreach ($validated['pickup_items'] as $row) {
            $items[] = [
                'label' => trim((string) $row['label']),
                'qty' => round((float) $row['qty'], 2),
            ];
        }

        $laundryRequest->pickup_items = $items;
        $laundryRequest->status = LaundryRequest::STATUS_PICKED_UP;
        $laundryRequest->picked_up_at = now();
        $laundryRequest->save();

        $laundryRequest->load(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);
        HousekeepingStateUpdated::dispatchIfEnabled([(int) $laundryRequest->room_id], 'laundry_pickup');

        return response()->json($this->formatRequest($laundryRequest));
    }

    public function syncLines(Request $request, LaundryRequest $laundryRequest)
    {
        $this->allowHousekeepingLaundryOperate();

        if ($laundryRequest->posted_at) {
            return response()->json(['message' => 'This laundry request is already posted to the folio.'], 422);
        }

        if (! in_array($laundryRequest->status, [
            LaundryRequest::STATUS_PICKED_UP,
            LaundryRequest::STATUS_PROCESSING,
        ], true)) {
            return response()->json(['message' => 'Charge lines can only be edited after pickup and before ready.'], 422);
        }

        $validated = $request->validate([
            'lines' => 'required|array',
            'lines.*.item_type' => 'required|string|max:255',
            'lines.*.service_type' => 'required|string|in:wash,wash_iron,iron_only,dry_clean',
            'lines.*.qty' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $laundryRequest->lines()->delete();

            $lineCount = count($validated['lines']);
            foreach ($validated['lines'] as $row) {
                $qty = round((float) $row['qty'], 2);
                $unit = round((float) $row['unit_price'], 2);
                $lineTotal = round($qty * $unit, 2);
                LaundryRequestLine::create([
                    'laundry_request_id' => $laundryRequest->id,
                    'item_type' => trim((string) $row['item_type']),
                    'service_type' => (string) $row['service_type'],
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ]);
            }

            if ($lineCount > 0 && $laundryRequest->status === LaundryRequest::STATUS_PICKED_UP) {
                $laundryRequest->status = LaundryRequest::STATUS_PROCESSING;
            } elseif ($lineCount === 0 && $laundryRequest->status === LaundryRequest::STATUS_PROCESSING) {
                $laundryRequest->status = LaundryRequest::STATUS_PICKED_UP;
            }
            $laundryRequest->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $laundryRequest->load(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);
        HousekeepingStateUpdated::dispatchIfEnabled([(int) $laundryRequest->room_id], 'laundry_lines');

        return response()->json($this->formatRequest($laundryRequest));
    }

    public function updateStatus(Request $request, LaundryRequest $laundryRequest)
    {
        $this->allowHousekeepingLaundryOperate();

        if ($laundryRequest->posted_at) {
            return response()->json(['message' => 'This laundry request is already posted to the folio.'], 422);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:picked_up,processing,ready,delivered',
        ]);

        $next = (string) $validated['status'];
        $cur = (string) $laundryRequest->status;

        $allowed = match ($cur) {
            LaundryRequest::STATUS_PICKED_UP => [LaundryRequest::STATUS_PROCESSING],
            LaundryRequest::STATUS_PROCESSING => [LaundryRequest::STATUS_READY],
            LaundryRequest::STATUS_READY => [LaundryRequest::STATUS_DELIVERED],
            default => [],
        };

        if (! in_array($next, $allowed, true)) {
            return response()->json([
                'message' => 'Invalid status transition from ' . $cur . ' to ' . $next . '.',
            ], 422);
        }

        if ($cur === LaundryRequest::STATUS_PICKED_UP && $next === LaundryRequest::STATUS_PROCESSING && $laundryRequest->lines()->count() < 1) {
            return response()->json(['message' => 'Add at least one charge line before moving to processing.'], 422);
        }

        if ($next === LaundryRequest::STATUS_READY && $laundryRequest->lines()->count() < 1) {
            return response()->json(['message' => 'Add at least one charge line before marking ready.'], 422);
        }

        $laundryRequest->status = $next;
        if ($next === LaundryRequest::STATUS_READY) {
            $laundryRequest->ready_at = now();
        }
        if ($next === LaundryRequest::STATUS_DELIVERED) {
            $laundryRequest->delivered_at = now();
        }
        $laundryRequest->save();

        $laundryRequest->load(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);
        HousekeepingStateUpdated::dispatchIfEnabled([(int) $laundryRequest->room_id], 'laundry_status');

        return response()->json($this->formatRequest($laundryRequest));
    }

    public function postToRoom(LaundryRequest $laundryRequest)
    {
        $this->allowHousekeepingLaundryOperate();

        if ($laundryRequest->posted_at) {
            return response()->json(['message' => 'Already posted to the room folio.'], 422);
        }

        if ($laundryRequest->status !== LaundryRequest::STATUS_DELIVERED) {
            return response()->json(['message' => 'Laundry must be delivered before posting to the folio.'], 422);
        }

        $laundryRequest->load(['lines', 'booking', 'room:id,room_number']);

        $linesTotal = (float) $laundryRequest->lines->sum('line_total');
        $expressExtra = $laundryRequest->express ? (float) ($laundryRequest->express_surcharge_amount ?? 0) : 0.0;
        $total = round($linesTotal + $expressExtra, 2);

        if ($total <= 0.0001) {
            return response()->json(['message' => 'Total amount must be greater than zero. Add charge lines first.'], 422);
        }

        $booking = $laundryRequest->booking;
        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 422);
        }

        $hasDescription = Schema::hasColumn('booking_extra_charges', 'description');

        $lineSummaries = $laundryRequest->lines->map(function (LaundryRequestLine $ln) {
            return [
                'item_type' => $ln->item_type,
                'service_type' => $ln->service_type,
                'qty' => (float) $ln->qty,
                'unit_price' => round((float) $ln->unit_price, 2),
                'line_total' => round((float) $ln->line_total, 2),
            ];
        })->values()->all();

        $meta = [
            'laundry_request_id' => $laundryRequest->id,
            'room_number' => (string) ($laundryRequest->room?->room_number ?? ''),
            'lines' => $lineSummaries,
            'express' => (bool) $laundryRequest->express,
            'express_surcharge_amount' => round($expressExtra, 2),
        ];

        $label = 'Guest laundry #' . $laundryRequest->id;
        $desc = Str::limit(
            'Laundry — ' . $label . ' — ' . $laundryRequest->guest_name,
            500,
        );

        DB::beginTransaction();
        try {
            $row = [
                'booking_id' => $booking->id,
                'source' => 'laundry',
                'kind' => 'laundry',
                'label' => $label,
                'qty' => 1,
                'unit_amount' => $total,
                'total_amount' => $total,
                'meta' => $meta,
            ];
            if ($hasDescription) {
                $row['description'] = $desc;
            }
            BookingExtraCharge::create($row);

            $booking->extra_charges = (float) ($booking->extra_charges ?? 0) + $total;
            $booking->save();

            $laundryRequest->posted_at = now();
            $laundryRequest->posted_amount = $total;
            $laundryRequest->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }

        $booking->refresh();
        $this->broadcastCharges($booking, $total);
        HousekeepingStateUpdated::dispatchIfEnabled([(int) $laundryRequest->room_id], 'laundry_posted');

        $laundryRequest->load(['room:id,room_number', 'booking:id,first_name,last_name', 'lines']);

        return response()->json([
            'message' => 'Posted to room folio.',
            'request' => $this->formatRequest($laundryRequest),
            'booking_id' => (int) $booking->id,
            'extra_charges' => round((float) ($booking->extra_charges ?? 0), 2),
            'posted_amount' => $total,
        ]);
    }
}
