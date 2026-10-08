<?php

namespace App\Support;

use App\Models\AiosellBookingLink;
use App\Models\AiosellRatePlanMap;
use App\Models\AiosellRoomMap;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\BookingSegment;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates, overwrites, and cancels Passion stays from an AioSell reservation webhook.
 */
final class AiosellReservationWriter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, bookings: list<Booking>}
     */
    public static function apply(array $payload): array
    {
        unset($payload['creditCard']);
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if (! AiosellClient::ready()) {
            return self::fail('AioSell is not connected.');
        }
        $hotelCode = trim((string) ($payload['hotelCode'] ?? ''));
        if ($hotelCode === '' || $hotelCode !== (string) AiosellClient::integration()->hotel_code) {
            return self::fail('Hotel code does not match this property.');
        }

        try {
            $applied = DB::transaction(function () use ($action, $payload) {
                return match ($action) {
                    'book' => self::book($payload),
                    'modify' => self::modify($payload),
                    'cancel' => self::cancel($payload),
                    default => throw new RuntimeException('Unknown reservation action.'),
                };
            });
        } catch (ValidationException $e) {
            return self::fail(collect($e->errors())->flatten()->first() ?: 'This room cannot take the occupancy.');
        } catch (RuntimeException $e) {
            return self::fail($e->getMessage());
        }

        if ($applied['bookings'] !== []) {
            HotelApiSync::syncBookings($applied['bookings'], $applied['previous_room_ids'] ?? []);
        }
        unset($applied['previous_room_ids']);

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, bookings: list<Booking>}
     */
    private static function book(array $payload): array
    {
        $channel = self::channel($payload);
        $bookingId = self::otaId($payload);
        $existing = AiosellBookingLink::query()
            ->where('channel', $channel)
            ->where('booking_id', $bookingId)
            ->exists();
        if ($existing) {
            return [
                'ok' => true,
                'message' => 'Reservation Updated Successfully',
                'bookings' => [],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Reservation Updated Successfully',
            'bookings' => self::writeRooms($payload, $channel, $bookingId, null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, bookings: list<Booking>, previous_room_ids?: list<int>}
     */
    private static function modify(array $payload): array
    {
        $channel = self::channel($payload);
        $bookingId = self::otaId($payload);
        $links = AiosellBookingLink::query()
            ->where('channel', $channel)
            ->where('booking_id', $bookingId)
            ->orderBy('room_index')
            ->get();
        if ($links->isEmpty()) {
            return [
                'ok' => true,
                'message' => 'Reservation Modified Successfully',
                'bookings' => self::writeRooms($payload, $channel, $bookingId, null),
            ];
        }

        $previousRoomIds = [];
        foreach ($links as $link) {
            $stay = $link->passion_booking_id ? Booking::query()->find($link->passion_booking_id) : null;
            if ($stay && in_array($stay->status, ['checked_out', 'completed'], true)) {
                throw new RuntimeException('This stay has already checked out.');
            }
            if ($stay) {
                $previousRoomIds[] = (int) $stay->room_id;
            }
        }

        $rooms = self::rooms($payload);
        $groupId = $links->first()->booking_group_id;
        if (count($rooms) > 1 && ! $groupId) {
            $groupId = self::makeGroup($payload, $channel, $bookingId)->id;
        }

        $bookings = [];
        $taken = [];
        foreach ($rooms as $index => $roomPayload) {
            $link = $links->firstWhere('room_index', $index);
            $stay = $link && $link->passion_booking_id ? Booking::query()->find($link->passion_booking_id) : null;
            if ($stay && $stay->status === 'cancelled') {
                $stay = null;
            }
            if ($stay && $stay->status === 'checked_in') {
                $bookings[] = self::rewriteCheckedIn($stay, $payload, $roomPayload, $index, $link);
                $taken[] = (int) $stay->room_id;
                continue;
            }
            if ($stay) {
                $bookings[] = self::rewriteConfirmed($stay, $payload, $roomPayload, $index, $link, $taken, $groupId);
                $taken[] = (int) $stay->fresh()->room_id;
                continue;
            }
            $created = self::writeRooms($payload, $channel, $bookingId, $groupId, [$index => $roomPayload], $taken);
            foreach ($created as $booking) {
                $bookings[] = $booking;
                $taken[] = (int) $booking->room_id;
            }
        }

        foreach ($links as $link) {
            if ($link->room_index < count($rooms)) {
                continue;
            }
            $stay = $link->passion_booking_id ? Booking::query()->find($link->passion_booking_id) : null;
            if ($stay && $stay->status === 'checked_in') {
                throw new RuntimeException('Checked-in stay cannot be removed by a modify.');
            }
            if ($stay && in_array($stay->status, ['pending', 'confirmed'], true)) {
                self::markCancelled($stay, $channel, $bookingId);
                $bookings[] = $stay->fresh();
            }
        }

        self::syncPrepaid($bookings, $payload);

        return [
            'ok' => true,
            'message' => 'Reservation Modified Successfully',
            'bookings' => $bookings,
            'previous_room_ids' => $previousRoomIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, bookings: list<Booking>}
     */
    private static function cancel(array $payload): array
    {
        $channel = self::channel($payload);
        $bookingId = self::otaId($payload);
        $links = AiosellBookingLink::query()
            ->where('channel', $channel)
            ->where('booking_id', $bookingId)
            ->get();
        if ($links->isEmpty()) {
            return [
                'ok' => true,
                'message' => 'Reservation Cancelled Successfully',
                'bookings' => [],
            ];
        }

        $bookings = [];
        foreach ($links as $link) {
            $stay = $link->passion_booking_id ? Booking::query()->find($link->passion_booking_id) : null;
            if (! $stay || $stay->status === 'cancelled') {
                continue;
            }
            if (! in_array($stay->status, ['pending', 'confirmed'], true)) {
                throw new RuntimeException('In-house stays must be checked out before this cancellation.');
            }
            self::markCancelled($stay, $channel, $bookingId);
            $bookings[] = $stay->fresh();
        }

        return [
            'ok' => true,
            'message' => 'Reservation Cancelled Successfully',
            'bookings' => $bookings,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>|null  $onlyRooms
     * @param  list<int>  $taken
     * @return list<Booking>
     */
    private static function writeRooms(array $payload, string $channel, string $bookingId, ?int $groupId, ?array $onlyRooms = null, array $taken = []): array
    {
        $rooms = $onlyRooms ?? self::rooms($payload);
        if ($rooms === []) {
            throw new RuntimeException('Reservation has no rooms.');
        }
        if ($groupId === null && count($rooms) > 1) {
            $groupId = self::makeGroup($payload, $channel, $bookingId)->id;
        }

        [$start, $end] = self::stayDates($payload);
        $bookings = [];
        foreach ($rooms as $index => $roomPayload) {
            $placed = self::placeRoom($payload, $roomPayload, (int) $index, $start, $end, null, $taken);
            $taken[] = $placed['room_id'];
            $booking = self::insertStay($payload, $roomPayload, $placed, $start, $end, $groupId, $index === array_key_first($rooms));
            AiosellBookingLink::query()->updateOrCreate(
                ['channel' => $channel, 'booking_id' => $bookingId, 'room_index' => (int) $index],
                self::linkAmounts($payload, $booking, $groupId),
            );
            $bookings[] = $booking;
        }
        if ($onlyRooms === null) {
            self::syncPrepaid($bookings, $payload);
        }

        return $bookings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $roomPayload
     * @param  list<int>  $taken
     */
    private static function rewriteConfirmed(Booking $stay, array $payload, array $roomPayload, int $index, AiosellBookingLink $link, array &$taken, ?int $groupId): Booking
    {
        [$start, $end] = self::stayDates($payload);
        $placed = self::placeRoom($payload, $roomPayload, $index, $start, $end, $stay, $taken);
        self::fillStay($stay, $payload, $roomPayload, $placed, $start, $end, $groupId);
        $link->fill(self::linkAmounts($payload, $stay, $groupId));
        $link->save();

        return $stay->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $roomPayload
     */
    private static function rewriteCheckedIn(Booking $stay, array $payload, array $roomPayload, int $index, AiosellBookingLink $link): Booking
    {
        [$start, $end] = self::stayDates($payload);
        $map = self::roomMap($roomPayload);
        $currentType = (int) (Room::query()->whereKey($stay->room_id)->value('room_type_id') ?? 0);
        if ((int) $map->room_type_id !== $currentType) {
            throw new RuntimeException('Checked-in stay cannot move to another room type.');
        }
        if (! BookingRoomAvailability::isListedAsAvailable((int) $stay->room_id, $start, $end, (int) $stay->id)) {
            throw new RuntimeException('Checked-in stay does not fit the new dates.');
        }
        $plan = self::rateMap($roomPayload);
        self::fillStay($stay, $payload, $roomPayload, [
            'room_id' => (int) $stay->room_id,
            'rate_plan_id' => $plan?->rate_plan_id,
            'total' => self::roomTotal($payload, $index),
        ], $start, $end, $stay->booking_group_id ? (int) $stay->booking_group_id : null);
        $link->fill(self::linkAmounts($payload, $stay, $stay->booking_group_id ? (int) $stay->booking_group_id : null));
        $link->save();

        return $stay->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $roomPayload
     * @param  array{room_id: int, rate_plan_id: int|null, total: float}  $placed
     */
    private static function insertStay(array $payload, array $roomPayload, array $placed, Carbon $start, Carbon $end, ?int $groupId, bool $first): Booking
    {
        $guest = self::guest($payload, $roomPayload);
        $room = Room::query()->findOrFail($placed['room_id']);
        $audit = '[Reservation created: AioSell '.self::channel($payload).' '.self::otaId($payload).' · Room #'.$room->room_number.' on '.now()->format('Y-m-d H:i:s').']';
        $notes = self::notes($payload, $audit);
        $booking = Booking::query()->create([
            'room_id' => $placed['room_id'],
            'rate_plan_id' => $placed['rate_plan_id'],
            'first_name' => $guest['first'],
            'last_name' => $guest['last'],
            'email' => $guest['email'],
            'phone' => $guest['phone'],
            'city' => $guest['city'],
            'country' => $guest['country'],
            'bill_to_name' => trim($guest['first'].' '.$guest['last']),
            'adults_count' => $guest['adults'],
            'children_count' => $guest['children'],
            'infants_count' => 0,
            'extra_beds_count' => 0,
            'check_in' => $start->toDateString(),
            'check_out' => $end->toDateString(),
            'check_in_at' => $start,
            'check_out_at' => $end,
            'booking_unit' => 'day',
            'total_price' => $placed['total'],
            'payment_status' => 'unpaid',
            'deposit_amount' => 0,
            'status' => 'confirmed',
            'booking_source' => self::channel($payload),
            'source_reference' => self::otaId($payload),
            'notes' => $notes,
            'booking_group_id' => $groupId,
        ]);
        BookingNumber::assign($booking);
        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $placed['room_id'],
            'check_in' => $start->toDateString(),
            'check_out' => $end->toDateString(),
            'check_in_at' => $start,
            'check_out_at' => $end,
            'rate_plan_id' => $placed['rate_plan_id'],
            'adults_count' => $guest['adults'],
            'children_count' => $guest['children'],
            'extra_beds_count' => 0,
            'total_price' => $placed['total'],
            'status' => 'confirmed',
        ]);

        return $booking->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $roomPayload
     * @param  array{room_id: int, rate_plan_id: int|null, total: float}  $placed
     */
    private static function fillStay(Booking $stay, array $payload, array $roomPayload, array $placed, Carbon $start, Carbon $end, ?int $groupId): void
    {
        $guest = self::guest($payload, $roomPayload);
        $room = Room::query()->findOrFail($placed['room_id']);
        $audit = '[AioSell modify: '.self::channel($payload).' '.self::otaId($payload).' · Room #'.$room->room_number.' on '.now()->format('Y-m-d H:i:s').']';
        $stay->fill([
            'room_id' => $placed['room_id'],
            'rate_plan_id' => $placed['rate_plan_id'],
            'first_name' => $guest['first'],
            'last_name' => $guest['last'],
            'email' => $guest['email'],
            'phone' => $guest['phone'],
            'city' => $guest['city'],
            'country' => $guest['country'],
            'adults_count' => $guest['adults'],
            'children_count' => $guest['children'],
            'check_in' => $start->toDateString(),
            'check_out' => $end->toDateString(),
            'check_in_at' => $start,
            'check_out_at' => $end,
            'total_price' => $placed['total'],
            'booking_source' => self::channel($payload),
            'source_reference' => self::otaId($payload),
            'notes' => self::appendNote((string) $stay->notes, self::notes($payload, $audit)),
            'booking_group_id' => $groupId ?: $stay->booking_group_id,
        ]);
        $stay->save();
        $segment = BookingSegment::query()
            ->where('booking_id', $stay->id)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->orderByDesc('id')
            ->first();
        if ($segment) {
            $segment->fill([
                'room_id' => $placed['room_id'],
                'check_in' => $start->toDateString(),
                'check_out' => $end->toDateString(),
                'check_in_at' => $start,
                'check_out_at' => $end,
                'rate_plan_id' => $placed['rate_plan_id'],
                'adults_count' => $guest['adults'],
                'children_count' => $guest['children'],
                'total_price' => $placed['total'],
            ]);
            $segment->save();
        }
    }

    private static function markCancelled(Booking $stay, string $channel, string $bookingId): void
    {
        $audit = '[Cancellation: AioSell '.$channel.' '.$bookingId.' on '.now()->format('Y-m-d H:i:s').']';
        $stay->status = 'cancelled';
        $stay->cancelled_at = now();
        $stay->cancellation_reason = 'other';
        $stay->cancellation_notes = 'Cancelled on AioSell.';
        $stay->notes = self::appendNote((string) $stay->notes, $audit);
        $stay->save();
        BookingSegment::query()
            ->where('booking_id', $stay->id)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->update(['status' => 'cancelled']);
    }

    /**
     * @param  list<Booking>  $bookings
     * @param  array<string, mixed>  $payload
     */
    private static function syncPrepaid(array $bookings, array $payload): void
    {
        if ($bookings === [] || self::pah($payload)) {
            return;
        }
        if (round((float) data_get($payload, 'amount.amountAfterTax', 0), 2) <= 0.004) {
            return;
        }
        $note = self::channel($payload).' '.self::otaId($payload);
        foreach ($bookings as $booking) {
            $stay = $booking->fresh();
            if (! $stay || $stay->status === 'cancelled') {
                continue;
            }
            $target = round((float) ($stay->total_price ?? 0), 2);
            $due = round($target - round((float) ($stay->deposit_amount ?? 0), 2), 2);
            if ($due <= 0.004) {
                continue;
            }
            if (BookingPaymentLedger::enabled()) {
                BookingPaymentLedger::recordPayment($stay, [
                    'amount' => $due,
                    'method' => 'bank_transfer',
                    'source' => 'aiosell',
                    'reference_no' => self::otaId($payload),
                    'notes' => $note,
                    'bill_total' => $target,
                ]);
            } else {
                $stay->forceFill([
                    'deposit_amount' => $target,
                    'payment_method' => 'bank_transfer',
                    'payment_status' => 'paid',
                ])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $roomPayload
     * @param  list<int>  $taken
     * @return array{room_id: int, rate_plan_id: int|null, total: float}
     */
    private static function placeRoom(array $payload, array $roomPayload, int $index, Carbon $start, Carbon $end, ?Booking $keep, array $taken): array
    {
        $map = self::roomMap($roomPayload);
        $plan = self::rateMap($roomPayload);
        $exclude = $keep ? (int) $keep->id : null;
        if ($keep && (int) $map->room_type_id === (int) Room::query()->whereKey($keep->room_id)->value('room_type_id')) {
            if (! in_array((int) $keep->room_id, $taken, true)
                && BookingRoomAvailability::isListedAsAvailable((int) $keep->room_id, $start, $end, $exclude)) {
                return [
                    'room_id' => (int) $keep->room_id,
                    'rate_plan_id' => $plan?->rate_plan_id,
                    'total' => self::roomTotal($payload, $index),
                ];
            }
        }

        $roomIds = Room::query()
            ->where('room_type_id', $map->room_type_id)
            ->orderBy('room_number')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($roomIds === []) {
            throw new RuntimeException('No Passion rooms are mapped for '.$map->room_code.'.');
        }

        $roomId = BookingRoomAvailability::withRoomLocks($roomIds, function () use ($roomIds, $roomPayload, $start, $end, $exclude, $taken) {
            foreach ($roomIds as $id) {
                if (in_array($id, $taken, true)) {
                    continue;
                }
                if (! BookingRoomAvailability::isListedAsAvailable($id, $start, $end, $exclude)) {
                    continue;
                }
                $candidate = Room::query()->with('roomType')->find($id);
                if (! $candidate) {
                    continue;
                }
                try {
                    BookingRoomAvailability::assertCapacity(
                        $candidate,
                        max(1, (int) data_get($roomPayload, 'occupancy.adults', 1)),
                        max(0, (int) data_get($roomPayload, 'occupancy.children', 0)),
                        0,
                        null,
                    );
                } catch (ValidationException) {
                    continue;
                }

                return $id;
            }

            return null;
        });
        if (! $roomId) {
            throw new RuntimeException('No free room for '.$map->room_code.' on these dates.');
        }

        return [
            'room_id' => (int) $roomId,
            'rate_plan_id' => $plan?->rate_plan_id,
            'total' => self::roomTotal($payload, $index),
        ];
    }

    /**
     * @param  array<string, mixed>  $roomPayload
     */
    private static function roomMap(array $roomPayload): AiosellRoomMap
    {
        $code = trim((string) ($roomPayload['roomCode'] ?? ''));
        $map = AiosellRoomMap::query()->where('room_code', $code)->where('active', true)->whereNotNull('room_type_id')->first();
        if (! $map) {
            throw new RuntimeException('Room code '.$code.' is not mapped.');
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $roomPayload
     */
    private static function rateMap(array $roomPayload): ?AiosellRatePlanMap
    {
        $code = trim((string) ($roomPayload['rateplanCode'] ?? ''));
        if ($code === '') {
            return null;
        }

        return AiosellRatePlanMap::query()->where('rateplan_code', $code)->where('active', true)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function stayDates(array $payload): array
    {
        $tz = (string) config('app.timezone');
        $start = Carbon::parse((string) ($payload['checkin'] ?? ''), $tz)->startOfDay();
        $end = Carbon::parse((string) ($payload['checkout'] ?? ''), $tz)->startOfDay();
        if ($end->lte($start)) {
            throw new RuntimeException('Check-out must be after check-in.');
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function rooms(array $payload): array
    {
        $rooms = $payload['rooms'] ?? [];
        if (! is_array($rooms) || $rooms === []) {
            throw new RuntimeException('Reservation has no rooms.');
        }
        $out = [];
        foreach (array_values($rooms) as $room) {
            if (is_array($room)) {
                $out[] = $room;
            }
        }
        if ($out === []) {
            throw new RuntimeException('Reservation has no rooms.');
        }

        return $out;
    }

    /**
     * The room's share of amountAfterTax, weighted by its nightly sellRate total. The last room takes the rounding
     * remainder so the shares add up to the OTA total.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function roomTotal(array $payload, int $index): float
    {
        $sums = [];
        foreach (self::rooms($payload) as $room) {
            $sum = 0.0;
            foreach ($room['prices'] ?? [] as $price) {
                if (is_array($price)) {
                    $sum += (float) ($price['sellRate'] ?? 0);
                }
            }
            $sums[] = round($sum, 2);
        }
        $afterTax = round((float) data_get($payload, 'amount.amountAfterTax', 0), 2);
        if ($afterTax <= 0) {
            return $sums[$index] ?? 0.0;
        }

        $count = count($sums);
        $weight = array_sum($sums);
        $given = 0.0;
        foreach ($sums as $i => $sum) {
            $share = $i === $count - 1
                ? round($afterTax - $given, 2)
                : ($weight > 0 ? round($afterTax * $sum / $weight, 2) : round($afterTax / $count, 2));
            if ($i === $index) {
                return $share;
            }
            $given += $share;
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $roomPayload
     * @return array{first: string, last: string, email: ?string, phone: ?string, city: ?string, country: string, adults: int, children: int}
     */
    private static function guest(array $payload, array $roomPayload): array
    {
        $first = trim((string) data_get($payload, 'guest.firstName', ''));
        $last = trim((string) data_get($payload, 'guest.lastName', ''));
        if ($first === '' && ! empty($roomPayload['guestName'])) {
            $parts = preg_split('/\s+/', trim((string) $roomPayload['guestName']), 2) ?: [];
            $first = (string) ($parts[0] ?? '');
            $last = (string) ($parts[1] ?? '');
        }
        if ($first === '') {
            $first = 'Guest';
        }
        $adults = (int) data_get($roomPayload, 'occupancy.adults', 1);
        $children = (int) data_get($roomPayload, 'occupancy.children', 0);

        return [
            'first' => $first,
            'last' => $last,
            'email' => self::blank(data_get($payload, 'guest.email')),
            'phone' => self::blank(data_get($payload, 'guest.phone')),
            'city' => self::blank(data_get($payload, 'guest.address.city')),
            'country' => self::blank(data_get($payload, 'guest.address.country')) ?: 'India',
            'adults' => max(1, $adults),
            'children' => max(0, $children),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function notes(array $payload, string $audit): string
    {
        $special = trim((string) ($payload['specialRequests'] ?? ''));

        return $special !== '' ? $audit."\n".$special : $audit;
    }

    private static function appendNote(string $existing, string $line): string
    {
        $existing = trim($existing);

        return $existing !== '' ? $existing."\n".$line : $line;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function linkAmounts(array $payload, Booking $booking, ?int $groupId): array
    {
        $amount = is_array($payload['amount'] ?? null) ? $payload['amount'] : [];

        return [
            'cm_booking_id' => self::blank($payload['cmBookingId'] ?? null),
            'passion_booking_id' => $booking->id,
            'booking_group_id' => $groupId,
            'pah' => self::pah($payload),
            'currency' => self::blank($amount['currency'] ?? null),
            'amount_after_tax' => self::money($amount['amountAfterTax'] ?? null),
            'amount_before_tax' => self::money($amount['amountBeforeTax'] ?? null),
            'tax' => self::money($amount['tax'] ?? null),
            'commission' => self::money($amount['commission'] ?? null),
            'tcs' => self::money($amount['tcs'] ?? null),
            'tds' => self::money($amount['tds'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function makeGroup(array $payload, string $channel, string $bookingId): BookingGroup
    {
        $guest = self::guest($payload, []);

        return BookingGroup::query()->create([
            'name' => 'AioSell '.$channel.' '.$bookingId,
            'contact_person' => trim($guest['first'].' '.$guest['last']),
            'phone' => $guest['phone'],
            'email' => $guest['email'],
            'status' => 'confirmed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function channel(array $payload): string
    {
        $channel = trim((string) ($payload['channel'] ?? ''));
        if ($channel === '') {
            throw new RuntimeException('Channel is required.');
        }

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function otaId(array $payload): string
    {
        $id = trim((string) ($payload['bookingId'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Booking id is required.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function pah(array $payload): bool
    {
        return filter_var($payload['pah'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private static function blank(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private static function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @return array{ok: bool, message: string, bookings: list<Booking>}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'bookings' => []];
    }
}
