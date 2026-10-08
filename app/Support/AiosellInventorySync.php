<?php

namespace App\Support;

use App\Models\AiosellIntegration;
use App\Models\AiosellRatePlanMap;
use App\Models\AiosellRoomMap;
use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\RoomType;
use App\Jobs\PushAiosellInventory;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pushes free-room counts and nightly prices to AioSell.
 * A failed push is stored on the integration row and does not undo the Passion save.
 */
final class AiosellInventorySync
{
    public const WINDOW_DAYS = 366;

    private const PENDING_KEY = 'aiosell.pending_inventory_room_types';

    private const INVENTORY_ERROR_PREFIX = 'Inventory: ';

    /**
     * @param  iterable<Booking>  $bookings
     * @param  list<int>  $previousRoomIds  Rooms the stays held before the save, so a room-type move frees the old type too.
     */
    public static function afterBookings(iterable $bookings, array $previousRoomIds = []): void
    {
        $roomIds = $previousRoomIds;
        foreach ($bookings as $booking) {
            $roomIds[] = (int) $booking->room_id;
            foreach (BookingSegment::query()->where('booking_id', $booking->id)->pluck('room_id') as $segmentRoomId) {
                $roomIds[] = (int) $segmentRoomId;
            }
        }
        self::afterRooms($roomIds);
    }

    public static function afterRoom(int $roomId): void
    {
        self::afterRooms([$roomId]);
    }

    /**
     * Queues the room types for one inventory push after the response is sent, so the save does not wait on AioSell.
     *
     * @param  list<int>  $roomIds
     */
    public static function afterRooms(array $roomIds): void
    {
        $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds))));
        if ($roomIds === [] || ! AiosellClient::ready()) {
            return;
        }
        $typeIds = Room::withTrashed()
            ->whereIn('id', $roomIds)
            ->pluck('room_type_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($typeIds === []) {
            return;
        }

        $app = app();
        if (! $app->bound(self::PENDING_KEY)) {
            $app->instance(self::PENDING_KEY, new \ArrayObject);
            App::terminating(fn () => self::pushPending());
        }
        $pending = $app->make(self::PENDING_KEY);
        foreach ($typeIds as $typeId) {
            $pending[$typeId] = $typeId;
        }
    }

    public static function pushPending(): void
    {
        $app = app();
        if (! $app->bound(self::PENDING_KEY)) {
            return;
        }
        $typeIds = array_values($app->make(self::PENDING_KEY)->getArrayCopy());
        $app->forgetInstance(self::PENDING_KEY);
        if ($typeIds === []) {
            return;
        }
        if (! config('services.aiosell.queue')) {
            self::pushInventoryForRoomTypes($typeIds);

            return;
        }
        foreach ($typeIds as $typeId) {
            PushAiosellInventory::dispatch((int) $typeId);
        }
    }

    /**
     * After a queued push succeeds, drop the inventory error unless another inventory push is still waiting to retry.
     */
    public static function clearErrorWhenQueueIsClear(): void
    {
        $integration = AiosellIntegration::current();
        if (! str_starts_with((string) $integration->last_error, self::INVENTORY_ERROR_PREFIX)) {
            return;
        }
        if (self::waitingInventoryJobs() > 0) {
            return;
        }
        $integration->last_error = null;
        $integration->inventory_dirty = false;
        $integration->save();
    }

    /**
     * Queued inventory pushes not yet picked up. Only the database queue can be read; other drivers report 0.
     */
    public static function waitingInventoryJobs(?int $olderThanSeconds = null): int
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return 0;
        }

        return DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('payload', 'like', '%PushAiosellInventory%')
            ->when($olderThanSeconds !== null, fn ($q) => $q->where('available_at', '<', now()->getTimestamp() - $olderThanSeconds))
            ->count();
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function pushNow(): array
    {
        $typeIds = AiosellRoomMap::query()->where('active', true)->whereNotNull('room_type_id')->pluck('room_type_id')->unique()->all();
        $inventory = self::pushInventoryForRoomTypes($typeIds);
        $rates = self::pushRatesForRoomTypes($typeIds);
        if ($inventory['ok'] && $rates['ok']) {
            self::rememberError(null, false, true);
            self::forgetPendingRates([]);
        }
        if (! $inventory['ok']) {
            return $inventory;
        }

        return $rates;
    }

    /**
     * @param  list<int>  $roomTypeIds
     * @return array{ok: bool, message: string}
     */
    public static function pushInventoryForRoomTypes(array $roomTypeIds): array
    {
        if (! AiosellClient::ready()) {
            return ['ok' => false, 'message' => 'AioSell is not connected.'];
        }

        $maps = AiosellRoomMap::query()
            ->where('active', true)
            ->whereNotNull('room_type_id')
            ->when($roomTypeIds !== [], fn ($q) => $q->whereIn('room_type_id', $roomTypeIds))
            ->get();
        if ($maps->isEmpty()) {
            return ['ok' => true, 'message' => 'No room types are mapped.'];
        }

        $updates = [];
        foreach ($maps as $map) {
            foreach (self::availabilityRanges((int) $map->room_type_id) as $range) {
                $updates[] = [
                    'startDate' => $range['start'],
                    'endDate' => $range['end'],
                    'rooms' => [[
                        'roomCode' => $map->room_code,
                        'available' => $range['available'],
                    ]],
                ];
            }
        }

        return self::postInventory($updates);
    }

    /**
     * @param  list<int>  $roomTypeIds
     * @return array{ok: bool, message: string}
     */
    public static function pushRatesForRoomTypes(array $roomTypeIds): array
    {
        if (! AiosellClient::ready()) {
            return ['ok' => false, 'message' => 'AioSell is not connected.'];
        }

        $maps = AiosellRatePlanMap::query()
            ->with(['ratePlan.roomType.seasons'])
            ->where('active', true)
            ->whereNotNull('rate_plan_id')
            ->when($roomTypeIds !== [], fn ($q) => $q->whereIn('room_type_id', $roomTypeIds))
            ->get();

        $updates = [];
        foreach ($maps as $map) {
            $plan = $map->ratePlan;
            if (! $plan || (string) $plan->billing_unit === 'hour_package') {
                continue;
            }
            foreach (self::rateRanges($map, $plan) as $range) {
                $updates[] = [
                    'startDate' => $range['start'],
                    'endDate' => $range['end'],
                    'rates' => [[
                        'roomCode' => $map->room_code,
                        'rateplanCode' => $map->rateplan_code,
                        'rate' => $range['rate'],
                    ]],
                ];
            }
        }

        if ($updates === []) {
            self::forgetPendingRates($roomTypeIds);

            return ['ok' => true, 'message' => 'No rate plans are mapped.'];
        }

        $result = AiosellClient::pushRates([
            'hotelCode' => (string) AiosellClient::integration()->hotel_code,
            'updates' => $updates,
        ]);
        if (! $result['ok']) {
            self::rememberError($result['message'] ?: 'Rate push failed.', true);
        } else {
            self::forgetPendingRates($roomTypeIds);
        }

        return ['ok' => $result['ok'], 'message' => $result['message'] ?: 'Rates updated.'];
    }

    public static function pushRatesForRoomType(int $roomTypeId): void
    {
        self::pushRatesForRoomTypes([$roomTypeId]);
    }

    /**
     * Price saved in Passion only. Inventory still goes out; rates wait for the next rate push.
     */
    public static function holdRatesForRoomType(int $roomTypeId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('aiosell_integrations')) {
            return;
        }
        $integration = AiosellIntegration::current();
        if (! $integration->enabled) {
            return;
        }
        $pending = array_map('intval', $integration->rates_pending_room_type_ids ?? []);
        if (! in_array($roomTypeId, $pending, true)) {
            $pending[] = $roomTypeId;
        }
        $integration->rates_pending_room_type_ids = array_values($pending);
        $integration->save();
    }

    /**
     * @param  list<int>  $roomTypeIds  Empty clears every room type.
     */
    private static function forgetPendingRates(array $roomTypeIds): void
    {
        $integration = AiosellIntegration::current();
        $pending = array_map('intval', $integration->rates_pending_room_type_ids ?? []);
        if ($pending === []) {
            return;
        }
        $left = $roomTypeIds === [] ? [] : array_values(array_diff($pending, array_map('intval', $roomTypeIds)));
        $integration->rates_pending_room_type_ids = $left === [] ? null : $left;
        $integration->save();
    }

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $restrictions
     * @return array{ok: bool, message: string}
     */
    public static function pushRestrictions(
        string $start,
        string $end,
        string $roomCode,
        ?string $rateplanCode,
        array $channels,
        array $restrictions,
    ): array {
        $integration = AiosellClient::integration();
        if ($rateplanCode) {
            $result = AiosellClient::pushRates([
                'hotelCode' => (string) $integration->hotel_code,
                'toChannels' => array_values($channels),
                'updates' => [[
                    'startDate' => $start,
                    'endDate' => $end,
                    'rates' => [[
                        'roomCode' => $roomCode,
                        'rateplanCode' => $rateplanCode,
                        'restrictions' => $restrictions,
                    ]],
                ]],
            ]);
        } else {
            $result = AiosellClient::pushInventory([
                'hotelCode' => (string) $integration->hotel_code,
                'toChannels' => array_values($channels),
                'updates' => [[
                    'startDate' => $start,
                    'endDate' => $end,
                    'rooms' => [[
                        'roomCode' => $roomCode,
                        'restrictions' => $restrictions,
                    ]],
                ]],
            ]);
        }
        if (! $result['ok']) {
            self::rememberError($result['message'] ?: 'Restriction push failed.', true);
        }

        return ['ok' => $result['ok'], 'message' => $result['message'] ?: 'Restrictions updated.'];
    }

    public static function availableCount(int $roomTypeId, Carbon $night): int
    {
        $start = $night->copy()->startOfDay();
        $end = $start->copy()->addDay();
        $count = 0;
        $rooms = Room::query()->where('room_type_id', $roomTypeId)->pluck('id');
        foreach ($rooms as $roomId) {
            if (BookingRoomAvailability::isListedAsAvailable((int) $roomId, $start, $end)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Same answer as availableCount() for every night of the window, from one segment read and one block read.
     * Mirrors BookingRoomAvailability::isListedAsAvailable() for a midnight-to-midnight night: active segment overlap,
     * an hourly stay starting the next morning before standard check-out, and hard blocks.
     *
     * @return array<string, int>  Night date => sellable rooms.
     */
    private static function nightlyAvailability(int $roomTypeId, Carbon $from): array
    {
        $windowStart = $from->copy()->startOfDay();
        $windowEnd = $windowStart->copy()->addDays(self::WINDOW_DAYS);
        $nights = [];
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $nights[$windowStart->copy()->addDays($i)->toDateString()] = true;
        }

        $roomIds = Room::query()->where('room_type_id', $roomTypeId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $busy = [];
        $markBusy = function (int $roomId, string $date) use (&$busy, $nights): void {
            if (isset($nights[$date])) {
                $busy[$date][$roomId] = true;
            }
        };

        if ($roomIds !== []) {
            $standardCheckOut = BookingRoomAvailability::standardCheckOutTime();
            $segments = BookingSegment::query()
                ->whereIn('room_id', $roomIds)
                ->whereNotIn('status', BookingRoomAvailability::INACTIVE_SEGMENT_STATUSES)
                ->where('check_in_at', '<', $windowEnd->copy()->addDay())
                ->where('check_out_at', '>', $windowStart)
                ->get(['room_id', 'check_in_at', 'check_out_at']);
            foreach ($segments as $segment) {
                $roomId = (int) $segment->room_id;
                $checkIn = Carbon::parse($segment->check_in_at);
                $checkOut = Carbon::parse($segment->check_out_at);
                for ($day = $checkIn->copy()->startOfDay(); $day->lt($checkOut) && $day->lt($windowEnd); $day->addDay()) {
                    $markBusy($roomId, $day->toDateString());
                }
                if ($checkIn->format('H:i:s') !== '00:00:00' && $checkIn->format('H:i') < $standardCheckOut) {
                    $markBusy($roomId, $checkIn->copy()->subDay()->toDateString());
                }
            }

            $blocks = RoomStatusBlock::query()
                ->whereIn('room_id', $roomIds)
                ->where('is_active', true)
                ->whereIn('status', BookingRoomAvailability::HARD_BLOCK_STATUSES)
                ->where('start_date', '<', $windowEnd->toDateString())
                ->where('end_date', '>', $windowStart->toDateString())
                ->get(['room_id', 'start_date', 'end_date']);
            foreach ($blocks as $block) {
                if ($block->start_date === null || $block->end_date === null) {
                    continue;
                }
                $end = Carbon::parse($block->end_date)->startOfDay();
                for ($day = Carbon::parse($block->start_date)->startOfDay(); $day->lt($end) && $day->lt($windowEnd); $day->addDay()) {
                    $markBusy((int) $block->room_id, $day->toDateString());
                }
            }
        }

        $total = count($roomIds);
        $out = [];
        foreach (array_keys($nights) as $date) {
            $out[$date] = $total - count($busy[$date] ?? []);
        }

        return $out;
    }

    /**
     * @return list<array{start: string, end: string, available: int}>
     */
    private static function availabilityRanges(int $roomTypeId): array
    {
        $today = Carbon::today();
        $nightly = self::nightlyAvailability($roomTypeId, $today);
        $ranges = [];
        $start = null;
        $previous = null;
        $count = null;
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $night = $today->copy()->addDays($i);
            $date = $night->toDateString();
            $available = $nightly[$date];
            if ($start === null) {
                $start = $previous = $date;
                $count = $available;
                continue;
            }
            $contiguous = Carbon::parse($previous)->addDay()->toDateString() === $date;
            if ($contiguous && $available === $count) {
                $previous = $date;
                continue;
            }
            $ranges[] = ['start' => $start, 'end' => $previous, 'available' => (int) $count];
            $start = $previous = $date;
            $count = $available;
        }
        if ($start !== null) {
            $ranges[] = ['start' => $start, 'end' => (string) $previous, 'available' => (int) $count];
        }

        return $ranges;
    }

    /**
     * @return list<array{start: string, end: string, rate: float}>
     */
    private static function rateRanges(AiosellRatePlanMap $map, RatePlan $plan): array
    {
        $roomType = $plan->roomType ?: RoomType::query()->with('seasons')->find($plan->room_type_id);
        $seasons = $roomType?->seasons;
        $today = Carbon::today();
        $ranges = [];
        $start = null;
        $previous = null;
        $rate = null;
        $base = $map->price_override !== null ? (float) $map->price_override : (float) $plan->base_price;
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $night = $today->copy()->addDays($i);
            $date = $night->toDateString();
            $nightly = $map->price_override !== null
                ? round($base, 2)
                : round(SeasonalRoomPricing::applyToBase($base, SeasonalRoomPricing::seasonForDate($seasons, $night)), 2);
            if ($start === null) {
                $start = $previous = $date;
                $rate = $nightly;
                continue;
            }
            $contiguous = Carbon::parse($previous)->addDay()->toDateString() === $date;
            if ($contiguous && abs($nightly - (float) $rate) < 0.001) {
                $previous = $date;
                continue;
            }
            $ranges[] = ['start' => $start, 'end' => $previous, 'rate' => (float) $rate];
            $start = $previous = $date;
            $rate = $nightly;
        }
        if ($start !== null) {
            $ranges[] = ['start' => $start, 'end' => (string) $previous, 'rate' => (float) $rate];
        }

        return $ranges;
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return array{ok: bool, message: string}
     */
    private static function postInventory(array $updates): array
    {
        if ($updates === []) {
            return ['ok' => true, 'message' => 'Nothing to push.'];
        }
        $result = AiosellClient::pushInventory([
            'hotelCode' => (string) AiosellClient::integration()->hotel_code,
            'updates' => $updates,
        ]);
        if (! $result['ok']) {
            self::rememberError(self::INVENTORY_ERROR_PREFIX.($result['message'] ?: 'Inventory push failed.'), true);
        }

        return ['ok' => $result['ok'], 'message' => $result['message'] ?: 'Inventory updated.'];
    }

    private static function rememberError(?string $message, bool $dirty, bool $clearDirty = false): void
    {
        $integration = AiosellIntegration::current();
        if ($message !== null || $clearDirty) {
            $integration->last_error = $message !== null ? mb_substr($message, 0, 2000) : null;
        }
        if ($dirty) {
            $integration->inventory_dirty = true;
        } elseif ($clearDirty) {
            $integration->inventory_dirty = false;
        }
        $integration->save();
    }
}
