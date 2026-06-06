<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesHousekeepingPermissions;
use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Events\HousekeepingStateUpdated;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomCleaningRelease;
use App\Models\RoomCleaningReleaseAudit;
use App\Models\RoomStatusBlock;
use App\Models\User;
use App\Services\RoomCleaningAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class RoomCleaningReleaseController extends Controller
{
    use AuthorizesHousekeepingPermissions;
    use AuthorizesSpatiePermissions;

    public function __construct(
        private readonly RoomCleaningAvailabilityService $availability,
    ) {}

    public function metrics(Request $request)
    {
        $this->allowHousekeepingNav();

        $date = $request->query('date')
            ? Carbon::parse($request->query('date'))
            : Carbon::today();

        return response()->json($this->availability->dashboardMetrics($date));
    }

    public function roomContext(Request $request, Room $room)
    {
        $this->authorizePermissions([self::HK_CLEANING_AVAILABILITY, 'reservation-edit', 'manage-rooms']);

        $bookingId = $request->query('booking_id');
        $booking = null;
        if ($bookingId) {
            $booking = Booking::query()
                ->where('id', (int) $bookingId)
                ->where(function ($q) use ($room) {
                    $q->where('room_id', $room->id)
                        ->orWhereHas('segments', fn($s) => $s->where('room_id', $room->id));
                })
                ->first(['id', 'first_name', 'last_name', 'status', 'check_in', 'check_out', 'room_id']);
        }

        $today = Carbon::today()->toDateString();
        $activeRelease = $this->availability->activeReleaseForRoom((int) $room->id);

        $dirtyBlock = RoomStatusBlock::query()
            ->where('room_id', $room->id)
            ->where('is_active', true)
            ->whereIn('status', ['dirty', 'cleaning'])
            ->where('start_date', '<=', $today)
            ->where('end_date', '>', $today)
            ->first(['id', 'status', 'note']);

        $staff = User::query()
            ->whereHas('roles', fn($q) => $q->where('name', 'Housekeeping'))
            ->orWhereHas('permissions', fn($q) => $q->where('name', self::HK_DAILY))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'room' => $room->load('roomType:id,name'),
            'booking' => $booking,
            'active_release' => $activeRelease,
            'dirty_block' => $dirtyBlock,
            'can_release' => (bool) Auth::user()?->can(self::HK_CLEANING_AVAILABILITY),
            'staff' => $staff,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermissions([self::HK_CLEANING_AVAILABILITY]);

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'release_date' => 'required|date',
            'window_start' => 'required|date',
            'window_end' => 'required|date|after:window_start',
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'nullable|in:normal,urgent,vip,deep_clean',
            'remarks' => 'nullable|string|max:5000',
        ]);

        try {
            $release = $this->availability->releaseForCleaning($validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($release, 201);
    }

    public function extend(Request $request, RoomCleaningRelease $roomCleaningRelease)
    {
        $this->authorizePermissions([self::HK_CLEANING_AVAILABILITY]);

        $validated = $request->validate([
            'window_end' => 'required|date',
            'remarks' => 'nullable|string|max:5000',
        ]);

        if (! $roomCleaningRelease->is_active) {
            return response()->json(['message' => 'This release is no longer active.'], 422);
        }

        try {
            $release = $this->availability->extendWindow($roomCleaningRelease, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($release);
    }

    public function reschedule(Request $request, RoomCleaningRelease $roomCleaningRelease)
    {
        $this->authorizePermissions([self::HK_CLEANING_AVAILABILITY]);

        $validated = $request->validate([
            'release_date' => 'required|date',
            'window_start' => 'required|date',
            'window_end' => 'required|date|after:window_start',
            'remarks' => 'nullable|string|max:5000',
        ]);

        if (! $roomCleaningRelease->is_active) {
            return response()->json(['message' => 'This release is no longer active.'], 422);
        }

        try {
            $release = $this->availability->rescheduleWindow($roomCleaningRelease, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($release);
    }

    public function cancel(Request $request, RoomCleaningRelease $roomCleaningRelease)
    {
        $this->authorizePermissions([self::HK_CLEANING_AVAILABILITY]);

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
        ]);

        if (! $roomCleaningRelease->is_active) {
            return response()->json(['message' => 'This release is no longer active.'], 422);
        }

        $release = $this->availability->cancelRelease(
            $roomCleaningRelease,
            $validated['remarks'] ?? null,
        );

        return response()->json($release);
    }

    public function markInspected(Request $request, RoomCleaningRelease $roomCleaningRelease)
    {
        $this->authorizePermissions([self::HK_SUPERVISOR_INSPECTION]);

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
        ]);

        if (! $roomCleaningRelease->is_active) {
            return response()->json(['message' => 'This release is no longer active.'], 422);
        }

        if ($roomCleaningRelease->status !== RoomCleaningRelease::STATUS_INSPECTION_PENDING) {
            return response()->json(['message' => 'Room is not awaiting inspection.'], 422);
        }

        $this->availability->markInspectionCompleted(
            $roomCleaningRelease,
            $validated['remarks'] ?? null,
        );

        HousekeepingStateUpdated::dispatchIfEnabled(
            [(int) $roomCleaningRelease->room_id],
            'cleaning_release_inspected',
        );

        return response()->json([
            'message' => 'Room inspection approved.',
            'release' => $roomCleaningRelease->fresh([
                'room.roomType:id,name',
                'assignedUser:id,name',
                'completedByUser:id,name',
            ]),
        ]);
    }

    public function audits(RoomCleaningRelease $roomCleaningRelease)
    {
        $this->allowHousekeepingNav();

        $rows = $roomCleaningRelease->audits()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['audits' => $rows]);
    }
}
