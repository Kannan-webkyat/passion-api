<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\DailyRoomCleaning;
use App\Models\HousekeepingJob;
use App\Models\Room;
use App\Models\RoomCleaningRelease;
use App\Support\BookingInvoiceRoomStay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Period reports for rooms / front office / housekeeping.
 */
class HospitalityReportService
{
    /**
     * @return array<string, mixed>
     */
    public function roomsPerformance(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $totalRooms = max(1, (int) Room::query()->where('is_active', true)->count());

        $bookings = Booking::query()
            ->with(['room.roomType'])
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereDate('check_in', '<=', $to)
            ->whereDate('check_out', '>', $from)
            ->get();

        $roomNights = 0;
        $roomRevenue = 0.0;
        $bookingsCount = 0;
        $byRoomType = [];

        foreach ($bookings as $booking) {
            $nights = $this->overlapRoomNights($booking, $from, $to);
            if ($nights <= 0) {
                continue;
            }
            $bookingsCount++;
            $roomNights += $nights;
            $gross = $this->bookingGross($booking);
            $stayNights = max(1, $this->bookingRoomNights($booking));
            $sliceRevenue = round(($gross / $stayNights) * $nights, 2);
            $roomRevenue += $sliceRevenue;

            $typeName = (string) ($booking->room?->roomType?->name ?? 'Unknown');
            if (! isset($byRoomType[$typeName])) {
                $byRoomType[$typeName] = [
                    'room_type' => $typeName,
                    'bookings' => 0,
                    'room_nights' => 0,
                    'revenue' => 0.0,
                ];
            }
            $byRoomType[$typeName]['bookings']++;
            $byRoomType[$typeName]['room_nights'] += $nights;
            $byRoomType[$typeName]['revenue'] = round($byRoomType[$typeName]['revenue'] + $sliceRevenue, 2);
        }

        $availableRoomNights = $totalRooms * $days;
        $occupancyPct = $availableRoomNights > 0
            ? round(($roomNights / $availableRoomNights) * 100, 1)
            : 0.0;
        $adr = $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : 0.0;
        $revpar = round($roomRevenue / $availableRoomNights, 2);

        $checkoutBookings = Booking::query()
            ->where('status', 'checked_out')
            ->whereDate('check_out', '>=', $from)
            ->whereDate('check_out', '<=', $to)
            ->get(['check_in', 'check_out', 'booking_unit']);

        $avgLos = 0.0;
        if ($checkoutBookings->isNotEmpty()) {
            $losSum = 0;
            foreach ($checkoutBookings as $b) {
                $losSum += $this->bookingRoomNights($b);
            }
            $avgLos = round($losSum / $checkoutBookings->count(), 1);
        }

        $rows = collect($byRoomType)
            ->map(function (array $row) {
                $rn = max(1, (int) $row['room_nights']);

                return [
                    ...$row,
                    'adr' => round($row['revenue'] / $rn, 2),
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();

        return [
            'from' => $from,
            'to' => $to,
            'summary' => [
                'total_rooms' => $totalRooms,
                'period_days' => $days,
                'available_room_nights' => $availableRoomNights,
                'room_nights_sold' => $roomNights,
                'occupancy_pct' => $occupancyPct,
                'adr' => $adr,
                'revpar' => $revpar,
                'room_revenue' => round($roomRevenue, 2),
                'bookings_count' => $bookingsCount,
                'avg_length_of_stay' => $avgLos,
            ],
            'by_room_type' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function frontOfficeDailyFlash(string $date): array
    {
        $day = Carbon::parse($date)->toDateString();
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = Carbon::parse($date)->addDay()->startOfDay();

        $arrivalsExpected = Booking::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereDate('check_in', $day)
            ->count();

        $arrivalsCheckedIn = Booking::query()
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->whereDate('check_in', $day)
            ->whereNotNull('check_in_at')
            ->count();

        $departuresExpected = Booking::query()
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->whereDate('check_out', $day)
            ->count();

        $departuresCompleted = Booking::query()
            ->where('status', 'checked_out')
            ->whereDate('check_out', $day)
            ->count();

        $inHouse = Booking::query()
            ->where('status', 'checked_in')
            ->count();

        $walkIns = Booking::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereDate('check_in', $day)
            ->where(function ($q) {
                $q->where('booking_source', 'walk-in')
                    ->orWhere('booking_source', 'walk_in');
            })
            ->count();

        $noShows = Booking::query()
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereDate('check_in', $day)
            ->whereNull('check_in_at')
            ->whereDate('check_in', '<', Carbon::today()->toDateString())
            ->count()
            + Booking::query()
                ->where('status', 'cancelled')
                ->where('cancellation_reason', 'no_show')
                ->whereDate('check_in', $day)
                ->count();

        $cancellations = Booking::query()
            ->where('status', 'cancelled')
            ->where(fn ($q) => $q->whereNull('cancellation_reason')->orWhere('cancellation_reason', '!=', 'no_show'))
            ->where(function ($q) use ($day) {
                $q->whereDate('check_in', $day)
                    ->orWhereDate('updated_at', $day);
            })
            ->count();

        $rooms = Room::with([
            'statusBlocks' => function ($q) use ($day) {
                $q->where('is_active', true)
                    ->where('start_date', '<=', $day)
                    ->where('end_date', '>', $day);
            },
            'segments' => function ($q) use ($dayStart, $dayEnd) {
                $q->whereNotIn('status', ['cancelled'])
                    ->where('check_in_at', '<', $dayEnd)
                    ->where('check_out_at', '>', $dayStart)
                    ->with('booking');
            },
        ])->where('is_active', true)->get();

        $occupied = 0;
        $reserved = 0;
        $available = 0;
        $ooo = 0;
        $dirty = 0;

        foreach ($rooms as $room) {
            $block = $room->statusBlocks->first();
            if ($block && in_array($block->status, ['maintenance', 'on_hold'], true)) {
                $ooo++;
                continue;
            }
            if ($block && in_array($block->status, ['dirty', 'cleaning'], true)) {
                $dirty++;
            }

            $activeSeg = $room->segments->first(function ($seg) {
                $bs = strtolower((string) ($seg->booking?->status ?? ''));

                return ! in_array($seg->status, ['checked_out', 'completed'], true)
                    && $bs !== 'checked_out'
                    && $bs !== 'cancelled';
            });

            if ($activeSeg) {
                $bs = $activeSeg->booking?->status;
                if ($bs === 'checked_in' || $activeSeg->status === 'checked_in') {
                    $occupied++;
                } else {
                    $reserved++;
                }
            } elseif (! $block || ! in_array($block->status, ['dirty', 'cleaning', 'maintenance', 'on_hold'], true)) {
                $available++;
            }
        }

        $totalRooms = $rooms->count();
        $sold = $occupied + $reserved;
        $occupancyPct = $totalRooms > 0 ? round(($sold / $totalRooms) * 100, 1) : 0.0;

        $arrivalRows = Booking::query()
            ->with('room:id,room_number')
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out', 'pending'])
            ->whereDate('check_in', $day)
            ->orderBy('check_in_at')
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'guest_name' => trim($b->first_name.' '.$b->last_name),
                'room_number' => $b->room?->room_number,
                'status' => $b->status,
                'adults' => (int) $b->adults_count,
                'children' => (int) ($b->children_count ?? 0),
                'source' => $b->booking_source,
                'check_in_at' => optional($b->check_in_at)?->toDateTimeString(),
            ])
            ->values()
            ->all();

        $departureRows = Booking::query()
            ->with('room:id,room_number')
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->whereDate('check_out', $day)
            ->orderBy('check_out_at')
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'guest_name' => trim($b->first_name.' '.$b->last_name),
                'room_number' => $b->room?->room_number,
                'status' => $b->status,
                'balance_hint' => max(0, round((float) $b->total_price - (float) $b->deposit_amount, 2)),
                'source' => $b->booking_source,
                'check_out_at' => optional($b->check_out_at)?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return [
            'date' => $day,
            'summary' => [
                'arrivals_expected' => $arrivalsExpected,
                'arrivals_checked_in' => $arrivalsCheckedIn,
                'departures_expected' => $departuresExpected,
                'departures_completed' => $departuresCompleted,
                'in_house' => $inHouse,
                'walk_ins' => $walkIns,
                'no_shows' => $noShows,
                'cancellations' => $cancellations,
                'total_rooms' => $totalRooms,
                'occupied' => $occupied,
                'reserved' => $reserved,
                'available' => $available,
                'out_of_order' => $ooo,
                'dirty_or_cleaning' => $dirty,
                'occupancy_pct' => $occupancyPct,
            ],
            'arrivals' => $arrivalRows,
            'departures' => $departureRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function housekeepingProductivity(string $from, string $to): array
    {
        $daily = DailyRoomCleaning::query()
            ->with(['room:id,room_number', 'completedByUser:id,name', 'assignedUser:id,name'])
            ->whereDate('service_date', '>=', $from)
            ->whereDate('service_date', '<=', $to)
            ->get();

        $releases = RoomCleaningRelease::query()
            ->with(['room:id,room_number', 'completedByUser:id,name', 'assignedUser:id,name'])
            ->whereDate('release_date', '>=', $from)
            ->whereDate('release_date', '<=', $to)
            ->whereNotIn('status', [RoomCleaningRelease::STATUS_CANCELLED])
            ->get();

        $jobs = Schema::hasTable('housekeeping_jobs')
            ? HousekeepingJob::query()
                ->with(['room:id,room_number', 'finishedByUser:id,name', 'startedByUser:id,name'])
                ->whereDate('updated_at', '>=', $from)
                ->whereDate('updated_at', '<=', $to)
                ->whereIn('status', ['inspected', 'completed'])
                ->get()
            : collect();

        $staff = [];

        $bump = function (array &$staff, ?int $userId, ?string $name, string $metric, float $value = 1, ?float $minutes = null) {
            $key = $userId ? 'u'.$userId : 'unassigned';
            if (! isset($staff[$key])) {
                $staff[$key] = [
                    'user_id' => $userId,
                    'name' => $name ?: 'Unassigned',
                    'daily_completed' => 0,
                    'releases_completed' => 0,
                    'checkout_jobs' => 0,
                    'total_minutes' => 0.0,
                    'timed_jobs' => 0,
                ];
            }
            $staff[$key][$metric] = ($staff[$key][$metric] ?? 0) + $value;
            if ($minutes !== null && $minutes > 0) {
                $staff[$key]['total_minutes'] += $minutes;
                $staff[$key]['timed_jobs']++;
            }
        };

        $dailyCompleted = 0;
        $dailyPending = 0;
        $dailyInProgress = 0;
        $durationSum = 0.0;
        $durationCount = 0;

        foreach ($daily as $row) {
            if ($row->status === 'cleaned' || $row->daily_cleaning_completed_at || $row->completed_at) {
                $dailyCompleted++;
                $minutes = $this->minutesBetween($row->started_at, $row->completed_at ?? $row->daily_cleaning_completed_at);
                if ($minutes !== null) {
                    $durationSum += $minutes;
                    $durationCount++;
                }
                $bump(
                    $staff,
                    $row->completed_by ?: $row->assigned_to,
                    $row->completedByUser?->name ?? $row->assignedUser?->name,
                    'daily_completed',
                    1,
                    $minutes
                );
            } elseif ($row->status === 'in_progress') {
                $dailyInProgress++;
            } else {
                $dailyPending++;
            }
        }

        $releasesCompleted = 0;
        $releasesOpen = 0;
        foreach ($releases as $rel) {
            $done = in_array($rel->status, [
                RoomCleaningRelease::STATUS_COMPLETED,
                RoomCleaningRelease::STATUS_READY,
            ], true) || $rel->completed_at !== null;

            if ($done) {
                $releasesCompleted++;
                $minutes = $this->minutesBetween($rel->started_at, $rel->completed_at);
                $bump(
                    $staff,
                    $rel->completed_by ?: $rel->assigned_to,
                    $rel->completedByUser?->name ?? $rel->assignedUser?->name,
                    'releases_completed',
                    1,
                    $minutes
                );
            } else {
                $releasesOpen++;
            }
        }

        foreach ($jobs as $job) {
            $minutes = $job->created_at && $job->updated_at
                ? max(0, $job->created_at->diffInMinutes($job->updated_at))
                : null;
            $bump(
                $staff,
                $job->finished_by ?: $job->started_by,
                $job->finishedByUser?->name ?? $job->startedByUser?->name,
                'checkout_jobs',
                1,
                $minutes !== null ? (float) $minutes : null
            );
        }

        $staffRows = collect($staff)
            ->map(function (array $row) {
                $rooms = (int) $row['daily_completed'] + (int) $row['releases_completed'] + (int) $row['checkout_jobs'];
                $avg = $row['timed_jobs'] > 0
                    ? round($row['total_minutes'] / $row['timed_jobs'], 1)
                    : null;

                return [
                    'user_id' => $row['user_id'],
                    'name' => $row['name'],
                    'daily_completed' => (int) $row['daily_completed'],
                    'releases_completed' => (int) $row['releases_completed'],
                    'checkout_jobs' => (int) $row['checkout_jobs'],
                    'rooms_cleaned' => $rooms,
                    'avg_minutes_per_room' => $avg,
                ];
            })
            ->sortByDesc('rooms_cleaned')
            ->values()
            ->all();

        return [
            'from' => $from,
            'to' => $to,
            'summary' => [
                'daily_completed' => $dailyCompleted,
                'daily_pending' => $dailyPending,
                'daily_in_progress' => $dailyInProgress,
                'releases_completed' => $releasesCompleted,
                'releases_open' => $releasesOpen,
                'checkout_jobs_completed' => $jobs->count(),
                'avg_cleaning_minutes' => $durationCount > 0 ? round($durationSum / $durationCount, 1) : null,
                'active_staff' => count(array_filter($staffRows, fn ($r) => $r['rooms_cleaned'] > 0)),
            ],
            'by_staff' => $staffRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cleaningScheduleAdherence(string $from, string $to): array
    {
        $releases = RoomCleaningRelease::query()
            ->with(['room:id,room_number'])
            ->whereDate('release_date', '>=', $from)
            ->whereDate('release_date', '<=', $to)
            ->where('status', '!=', RoomCleaningRelease::STATUS_CANCELLED)
            ->get();

        $total = $releases->count();
        $completed = 0;
        $onTime = 0;
        $overdue = 0;
        $inWindow = 0;
        $byServiceType = ['daily' => 0, 'other' => 0];
        $bySubtype = ['requested' => 0, 'complaint' => 0, 'rerelease' => 0];
        $byPriority = [];
        $detail = [];

        foreach ($releases as $rel) {
            $serviceType = (string) ($rel->service_type ?: 'daily');
            $byServiceType[$serviceType] = ($byServiceType[$serviceType] ?? 0) + 1;
            if ($rel->service_subtype) {
                $sub = (string) $rel->service_subtype;
                $bySubtype[$sub] = ($bySubtype[$sub] ?? 0) + 1;
            }
            $prio = (string) ($rel->priority ?: 'normal');
            $byPriority[$prio] = ($byPriority[$prio] ?? 0) + 1;

            $isDone = in_array($rel->status, [
                RoomCleaningRelease::STATUS_COMPLETED,
                RoomCleaningRelease::STATUS_READY,
            ], true) || $rel->completed_at !== null;

            $windowEnd = $rel->window_end;
            $completedAt = $rel->completed_at;
            $adherence = 'open';

            if ($isDone) {
                $completed++;
                if ($windowEnd && $completedAt) {
                    if ($completedAt->lte($windowEnd)) {
                        $onTime++;
                        $adherence = 'on_time';
                    } else {
                        $overdue++;
                        $adherence = 'late';
                    }
                } else {
                    $onTime++;
                    $adherence = 'completed';
                }
            } elseif ($windowEnd && now()->gt($windowEnd)) {
                $overdue++;
                $adherence = 'overdue';
            } else {
                $inWindow++;
                $adherence = 'in_window';
            }

            $detail[] = [
                'id' => $rel->id,
                'room_number' => $rel->room?->room_number,
                'release_date' => optional($rel->release_date)?->toDateString(),
                'status' => $rel->status,
                'priority' => $prio,
                'service_type' => $serviceType,
                'service_subtype' => $rel->service_subtype,
                'window_start' => optional($rel->window_start)?->toDateTimeString(),
                'window_end' => optional($windowEnd)?->toDateTimeString(),
                'started_at' => optional($rel->started_at)?->toDateTimeString(),
                'completed_at' => optional($completedAt)?->toDateTimeString(),
                'adherence' => $adherence,
                'duration_minutes' => $this->minutesBetween($rel->started_at, $completedAt),
            ];
        }

        $dailyRows = DailyRoomCleaning::query()
            ->whereDate('service_date', '>=', $from)
            ->whereDate('service_date', '<=', $to)
            ->get();

        $dailyTotal = $dailyRows->count();
        $dailyDone = $dailyRows->filter(fn ($r) => $r->status === 'cleaned' || $r->daily_cleaning_completed_at || $r->completed_at)->count();
        $dailyCompletionPct = $dailyTotal > 0 ? round(($dailyDone / $dailyTotal) * 100, 1) : 0.0;
        $releaseOnTimePct = $completed > 0 ? round(($onTime / max(1, $completed)) * 100, 1) : 0.0;

        usort($detail, fn ($a, $b) => strcmp((string) $b['release_date'], (string) $a['release_date']));

        return [
            'from' => $from,
            'to' => $to,
            'summary' => [
                'releases_total' => $total,
                'releases_completed' => $completed,
                'on_time' => $onTime,
                'overdue_or_late' => $overdue,
                'still_in_window' => $inWindow,
                'on_time_pct' => $releaseOnTimePct,
                'daily_tasks_total' => $dailyTotal,
                'daily_tasks_completed' => $dailyDone,
                'daily_completion_pct' => $dailyCompletionPct,
                'by_service_type' => $byServiceType,
                'by_service_subtype' => $bySubtype,
                'by_priority' => $byPriority,
            ],
            'releases' => array_slice($detail, 0, 200),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function channelSourceMix(string $from, string $to): array
    {
        $bookings = Booking::query()
            ->with(['room.roomType'])
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereDate('check_in', '>=', $from)->whereDate('check_in', '<=', $to);
                })->orWhere(function ($q2) use ($from, $to) {
                    $q2->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
                });
            })
            ->get();

        $bySource = [];
        $totalBookings = 0;
        $totalRevenue = 0.0;
        $totalNights = 0;

        foreach ($bookings as $booking) {
            $source = $this->normalizeSource((string) ($booking->booking_source ?: 'unknown'));
            if (! isset($bySource[$source])) {
                $bySource[$source] = [
                    'source' => $source,
                    'bookings' => 0,
                    'room_nights' => 0,
                    'revenue' => 0.0,
                    'deposit' => 0.0,
                    'checked_in' => 0,
                    'checked_out' => 0,
                    'confirmed' => 0,
                ];
            }
            $nights = $this->bookingRoomNights($booking);
            $gross = $this->bookingGross($booking);
            $bySource[$source]['bookings']++;
            $bySource[$source]['room_nights'] += $nights;
            $bySource[$source]['revenue'] = round($bySource[$source]['revenue'] + $gross, 2);
            $bySource[$source]['deposit'] = round($bySource[$source]['deposit'] + (float) ($booking->deposit_amount ?? 0), 2);
            $status = (string) $booking->status;
            if (isset($bySource[$source][$status])) {
                $bySource[$source][$status]++;
            }
            $totalBookings++;
            $totalRevenue += $gross;
            $totalNights += $nights;
        }

        $rows = collect($bySource)
            ->map(function (array $row) use ($totalBookings, $totalRevenue) {
                return [
                    ...$row,
                    'bookings_share_pct' => $totalBookings > 0
                        ? round(($row['bookings'] / $totalBookings) * 100, 1)
                        : 0.0,
                    'revenue_share_pct' => $totalRevenue > 0
                        ? round(($row['revenue'] / $totalRevenue) * 100, 1)
                        : 0.0,
                    'adr' => $row['room_nights'] > 0
                        ? round($row['revenue'] / $row['room_nights'], 2)
                        : 0.0,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();

        return [
            'from' => $from,
            'to' => $to,
            'summary' => [
                'bookings' => $totalBookings,
                'room_nights' => $totalNights,
                'revenue' => round($totalRevenue, 2),
                'channels' => count($rows),
            ],
            'by_source' => $rows,
        ];
    }

    /**
     * Checked-out stays whose folio still carries a balance (charges posted or payments voided after departure).
     * Bill = invoice gross − checkout discount (same figure the checkout guard uses); received = payments − refunds.
     * Group rooms are skipped while the group's pooled payments still cover the pooled bill.
     *
     * @return array<string, mixed>
     */
    public function unpaidCheckouts(string $from, string $to): array
    {
        $bookings = Booking::query()
            ->with(['room.roomType.tax', 'room.roomType.ratePlans', 'room.roomType.seasons', 'bookingGroup'])
            ->where('status', 'checked_out')
            ->whereDate('check_out', '>=', $from)
            ->whereDate('check_out', '<=', $to)
            ->orderBy('check_out')
            ->orderBy('id')
            ->get();

        $today = Carbon::today();
        $groupCovered = [];
        $rows = [];

        foreach ($bookings as $booking) {
            $bill = $this->checkoutBill($booking);
            $received = round((float) ($booking->deposit_amount ?? 0) - (float) ($booking->refund_amount ?? 0), 2);
            $balance = round($bill - $received, 2);
            if ($balance <= 0.01) {
                continue;
            }

            $groupId = (int) ($booking->booking_group_id ?? 0);
            if ($groupId > 0) {
                $groupCovered[$groupId] ??= $this->groupPaymentsCoverBill($groupId);
                if ($groupCovered[$groupId]) {
                    continue;
                }
            }

            $checkOut = Carbon::parse($booking->check_out)->startOfDay();
            $rows[] = [
                'booking_id' => (int) $booking->id,
                'guest_name' => trim((string) $booking->guest_name) ?: 'Guest',
                'phone' => $booking->phone,
                'email' => $booking->email,
                'room_number' => $booking->room?->room_number,
                'room_type' => $booking->room?->roomType?->name,
                'group_name' => $booking->bookingGroup?->name,
                'booking_source' => $booking->booking_source,
                'check_in' => Carbon::parse($booking->check_in)->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'room_charges' => round((float) ($booking->total_price ?? 0), 2),
                'extra_charges' => round((float) ($booking->extra_charges ?? 0), 2),
                'discount' => round((float) ($booking->checkout_discount_amount ?? 0), 2),
                'bill' => $bill,
                'received' => $received,
                'balance' => $balance,
                'days_outstanding' => max(0, (int) $checkOut->diffInDays($today, false)),
                'payment_status' => $booking->payment_status,
                'last_payment_at' => null,
            ];
        }

        if ($rows !== [] && Schema::hasTable('booking_payments')) {
            $lastPaid = BookingPayment::query()
                ->whereIn('booking_id', array_column($rows, 'booking_id'))
                ->where('type', BookingPayment::TYPE_PAYMENT)
                ->whereNull('voided_at')
                ->groupBy('booking_id')
                ->selectRaw('booking_id, MAX(paid_at) as last_paid_at')
                ->pluck('last_paid_at', 'booking_id');
            foreach ($rows as &$row) {
                $at = $lastPaid[$row['booking_id']] ?? null;
                $row['last_payment_at'] = $at ? Carbon::parse($at)->toIso8601String() : null;
            }
            unset($row);
        }

        usort($rows, fn ($a, $b) => $b['balance'] <=> $a['balance']);

        $aging = [
            ['bucket' => '0-7', 'label' => '0–7 days', 'count' => 0, 'amount' => 0.0],
            ['bucket' => '8-30', 'label' => '8–30 days', 'count' => 0, 'amount' => 0.0],
            ['bucket' => '31-60', 'label' => '31–60 days', 'count' => 0, 'amount' => 0.0],
            ['bucket' => '60+', 'label' => 'Over 60 days', 'count' => 0, 'amount' => 0.0],
        ];
        foreach ($rows as $row) {
            $d = $row['days_outstanding'];
            $i = $d <= 7 ? 0 : ($d <= 30 ? 1 : ($d <= 60 ? 2 : 3));
            $aging[$i]['count']++;
            $aging[$i]['amount'] = round($aging[$i]['amount'] + $row['balance'], 2);
        }

        $outstanding = round(array_sum(array_column($rows, 'balance')), 2);

        return [
            'summary' => [
                'bookings' => count($rows),
                'outstanding' => $outstanding,
                'billed' => round(array_sum(array_column($rows, 'bill')), 2),
                'received' => round(array_sum(array_column($rows, 'received')), 2),
                'average_balance' => count($rows) > 0 ? round($outstanding / count($rows), 2) : 0.0,
                'oldest_days' => $rows === [] ? 0 : max(array_column($rows, 'days_outstanding')),
                'checkouts_in_period' => $bookings->count(),
            ],
            'aging' => $aging,
            'rows' => $rows,
        ];
    }

    /** Mirrors BookingController::checkoutPaymentState() for a single room. */
    private function checkoutBill(Booking $booking): float
    {
        $gross = ($booking->booking_unit ?? 'day') === 'hour_package'
            ? (float) ($booking->total_price ?? 0) + (float) ($booking->extra_charges ?? 0)
            : (float) BookingInvoiceRoomStay::summarizeForInvoice($booking)['gross_before_checkout_discount'];
        $discount = max(0.0, (float) ($booking->checkout_discount_amount ?? 0));
        $grand = max(0.0, $gross - min($discount, $gross));

        return round(max($grand, (float) ($booking->total_price ?? 0)), 2);
    }

    private function groupPaymentsCoverBill(int $groupId): bool
    {
        $group = Booking::query()
            ->with(['room.roomType.tax', 'room.roomType.ratePlans', 'room.roomType.seasons'])
            ->where('booking_group_id', $groupId)
            ->where('status', '!=', 'cancelled')
            ->get();
        $bill = (float) $group->sum(fn (Booking $b) => $this->checkoutBill($b));
        $received = (float) $group->sum(
            fn (Booking $b) => (float) ($b->deposit_amount ?? 0) - (float) ($b->refund_amount ?? 0)
        );

        return $received + 0.009 >= $bill;
    }

    private function normalizeSource(string $source): string
    {
        $s = strtolower(trim(str_replace('_', '-', $source)));

        return $s !== '' ? $s : 'unknown';
    }

    private function minutesBetween(mixed $start, mixed $end): ?float
    {
        if (! $start || ! $end) {
            return null;
        }
        try {
            $a = $start instanceof Carbon ? $start : Carbon::parse($start);
            $b = $end instanceof Carbon ? $end : Carbon::parse($end);
            if ($b->lt($a)) {
                return null;
            }

            return round((float) $a->diffInMinutes($b), 1);
        } catch (\Throwable) {
            return null;
        }
    }

    private function bookingRoomNights(Booking $booking): int
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return 1;
        }
        $checkIn = Carbon::parse($booking->check_in)->startOfDay();
        $checkOut = Carbon::parse($booking->check_out)->startOfDay();

        return max(1, (int) $checkIn->diffInDays($checkOut));
    }

    private function overlapRoomNights(Booking $booking, string $from, string $to): int
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            $ci = Carbon::parse($booking->check_in)->toDateString();

            return ($ci >= $from && $ci <= $to) ? 1 : 0;
        }
        $stayStart = Carbon::parse($booking->check_in)->startOfDay();
        $stayEnd = Carbon::parse($booking->check_out)->startOfDay();
        $rangeStart = Carbon::parse($from)->startOfDay();
        $rangeEndExclusive = Carbon::parse($to)->startOfDay()->addDay();

        $overlapStart = $stayStart->greaterThan($rangeStart) ? $stayStart->copy() : $rangeStart->copy();
        $overlapEnd = $stayEnd->lessThan($rangeEndExclusive) ? $stayEnd->copy() : $rangeEndExclusive->copy();

        if ($overlapEnd->lte($overlapStart)) {
            return 0;
        }

        return max(0, (int) $overlapStart->diffInDays($overlapEnd));
    }

    private function bookingGross(Booking $booking): float
    {
        return round(
            (float) ($booking->total_price ?? 0) + (float) ($booking->extra_charges ?? 0),
            2,
        );
    }
}
