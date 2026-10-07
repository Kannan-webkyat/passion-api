<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\DoorloomBookingLink;
use App\Models\DoorloomIntegration;
use App\Models\DoorloomNight;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes Passion room types and overnight stays to Doorloom.
 * Hourly stays are skipped. A 409 does not roll back the Passion booking.
 */
final class DoorloomStaySync
{
    /**
     * @param  list<int>  $roomIds
     */
    public static function refusalMessage(array $roomIds, Carbon $checkIn, Carbon $checkOut): ?string
    {
        if ($checkOut->lessThanOrEqualTo($checkIn)) {
            return null;
        }

        $typeIds = Room::query()->whereIn('id', $roomIds)->pluck('room_type_id')->unique()->filter();
        foreach ($typeIds as $typeId) {
            $type = RoomType::query()->find($typeId);
            if (! $type || ! $type->doorloom_property_id) {
                continue;
            }
            $cursor = $checkIn->copy()->startOfDay();
            $end = $checkOut->copy()->startOfDay();
            while ($cursor->lt($end)) {
                $night = DoorloomNight::query()
                    ->where('doorloom_property_id', $type->doorloom_property_id)
                    ->whereDate('night_date', $cursor->toDateString())
                    ->first();
                if ($night && ($night->stop_sell || (int) $night->available_units === 0 && $night->available_units !== null)) {
                    return 'Doorloom has no free '.$type->name.' rooms on '.$cursor->toDateString().'.';
                }
                $cursor->addDay();
            }
        }

        return null;
    }

    /**
     * @param  iterable<Booking>  $bookings
     * @return array{status: string, message: string|null}
     */
    public static function syncBookings(iterable $bookings): array
    {
        $message = null;
        $status = 'skipped';
        foreach ($bookings as $booking) {
            $result = self::syncBooking($booking);
            if ($result['status'] !== 'skipped') {
                $status = $result['status'];
            }
            if (! empty($result['message'])) {
                $message = $result['message'];
            }
        }

        return ['status' => $status, 'message' => $message];
    }

    /**
     * @return array{status: string, message: string|null}
     */
    public static function syncBooking(Booking $booking): array
    {
        try {
            return self::syncBookingUnsafe($booking);
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, message: string|null}
     */
    private static function syncBookingUnsafe(Booking $booking): array
    {
        if (! DoorloomClient::ready()) {
            return ['status' => 'skipped', 'message' => null];
        }
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return ['status' => 'skipped', 'message' => null];
        }

        $booking->loadMissing(['room.roomType', 'ratePlan']);
        $roomType = $booking->room?->roomType;
        if (! $roomType || ! $roomType->doorloom_property_id) {
            return ['status' => 'skipped', 'message' => null];
        }

        $external = 'b-'.$booking->id.'-rt-'.$roomType->id;
        $previous = DoorloomBookingLink::query()
            ->where('booking_id', $booking->id)
            ->where('room_type_id', '!=', $roomType->id)
            ->get();
        foreach ($previous as $oldLink) {
            self::cancelLink($oldLink, $booking);
        }

        $link = DoorloomBookingLink::query()->firstOrCreate(
            ['booking_id' => $booking->id, 'room_type_id' => $roomType->id],
            ['external_booking_id' => $external, 'sync_status' => 'pending'],
        );

        if ($booking->status === 'cancelled') {
            return self::cancelLink($link, $booking);
        }

        $body = self::bookingBody($booking, $roomType, $external);
        $hash = hash('sha256', json_encode($body) ?: '');

        if ($link->doorloom_booking_id) {
            return self::patchLink($link, $body, $hash);
        }

        if ($link->payload_hash !== $hash || ! $link->idempotency_key) {
            $link->idempotency_key = (string) Str::uuid();
            $link->payload_hash = $hash;
            $link->save();
        }

        $result = DoorloomClient::createBooking($body, (string) $link->idempotency_key);

        return self::rememberBookingResult($link, $result, $hash);
    }

    /**
     * @param  array{ok: bool, status: int, json: array<string, mixed>, message: string}  $result
     * @return array{status: string, message: string|null}
     */
    private static function rememberBookingResult(DoorloomBookingLink $link, array $result, string $hash): array
    {
        $data = is_array($result['json']['data'] ?? null) ? $result['json']['data'] : [];
        if ($result['ok']) {
            $link->doorloom_booking_id = (int) ($data['id'] ?? $link->doorloom_booking_id) ?: null;
            $link->revision = (int) ($data['revision'] ?? $link->revision);
            $link->sync_status = 'synced';
            $link->last_error = null;
            $link->payload_hash = $hash;
            $link->save();

            return ['status' => 'synced', 'message' => null];
        }

        if ($result['status'] === 409 && ($result['json']['code'] ?? '') === 'UNAVAILABLE') {
            $availability = $result['json']['errors']['availability'] ?? null;
            if (is_array($availability)) {
                DoorloomAdapter::applyUnavailablePayload($availability);
            }
            $link->sync_status = 'unavailable';
            $link->last_error = $result['message'];
            $link->save();

            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        if ($result['status'] === 412) {
            $current = (int) data_get($result, 'json.errors.current_revision', 0);
            if ($current > 0) {
                $link->revision = $current;
                $link->save();
            }
        }

        $link->sync_status = 'error';
        $link->last_error = $result['message'];
        $link->save();

        return ['status' => 'error', 'message' => $result['message']];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: string, message: string|null}
     */
    private static function patchLink(DoorloomBookingLink $link, array $body, string $hash): array
    {
        unset($body['external_booking_id'], $body['property'], $body['source']);
        $result = DoorloomClient::updateBooking((int) $link->doorloom_booking_id, $body, (int) $link->revision);
        if ($result['status'] === 412) {
            $current = (int) data_get($result, 'json.errors.current_revision', 0);
            if ($current > 0) {
                $link->revision = $current;
                $result = DoorloomClient::updateBooking((int) $link->doorloom_booking_id, $body, $current);
            }
        }

        return self::rememberBookingResult($link, $result, $hash);
    }

    /**
     * @return array{status: string, message: string|null}
     */
    private static function cancelLink(DoorloomBookingLink $link, Booking $booking): array
    {
        if (! $link->doorloom_booking_id || $link->sync_status === 'cancelled') {
            return ['status' => 'skipped', 'message' => null];
        }
        $result = DoorloomClient::cancelBooking((int) $link->doorloom_booking_id, [
            'reason' => (string) ($booking->cancellation_reason ?: 'Cancelled in Passion'),
        ]);
        if ($result['ok']) {
            $data = is_array($result['json']['data'] ?? null) ? $result['json']['data'] : [];
            $link->revision = (int) ($data['revision'] ?? $link->revision);
            $link->sync_status = 'cancelled';
            $link->last_error = null;
            $link->save();

            return ['status' => 'cancelled', 'message' => null];
        }
        $link->sync_status = 'error';
        $link->last_error = $result['message'];
        $link->save();

        return ['status' => 'error', 'message' => $result['message']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bookingBody(Booking $booking, RoomType $roomType, string $external): array
    {
        $checkIn = Carbon::parse($booking->check_in)->toDateString();
        $checkOut = Carbon::parse($booking->check_out)->toDateString();
        $phone = preg_replace('/\D+/', '', (string) $booking->phone) ?: '';
        $meal = self::mealCode($booking->ratePlan);

        return [
            'external_booking_id' => $external,
            'property' => ['external_id' => 'rt-'.$roomType->id],
            'units' => [[
                'external_unit_id' => $booking->room_id ? 'room-'.$booking->room_id : null,
                'adults' => max(1, (int) $booking->adults_count),
                'children' => (int) ($booking->children_count ?? 0),
                'meal_plan' => $meal,
                'stay_price' => round((float) $booking->total_price, 2),
            ]],
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guest' => [
                'name' => trim($booking->first_name.' '.$booking->last_name),
                'phone_code' => '+91',
                'mobile' => $phone !== '' ? $phone : '0000000000',
            ],
            'pricing' => [
                'total' => round((float) $booking->total_price, 2),
                'currency' => 'INR',
            ],
            'source' => [
                'channel' => 'walk_in',
                'reference' => (string) $booking->id,
            ],
            'status' => 'confirmed',
            'notes' => $booking->notes,
        ];
    }

    private static function mealCode(?RatePlan $plan): string
    {
        $type = (string) ($plan->meal_plan_type ?? 'room_only');

        return match ($type) {
            'breakfast' => 'CP',
            'half_board' => 'MP',
            'full_board' => 'AP',
            default => 'EP',
        };
    }

    public static function updateListingIfLinked(RoomType $roomType): void
    {
        try {
            if (! DoorloomClient::ready() || ! $roomType->doorloom_property_id) {
                return;
            }
            $body = self::propertyBody($roomType, false);
            DoorloomClient::updateProperty((int) $roomType->doorloom_property_id, $body);
        } catch (Throwable) {
            // Listing push must not fail the room-type save.
        }
    }

    /**
     * @return array{ok: bool, message: string, created: int}
     */
    public static function pushListings(): array
    {
        if (! DoorloomClient::ready()) {
            return ['ok' => false, 'message' => 'Save an API key and turn Doorloom on first.', 'created' => 0];
        }
        $integration = DoorloomIntegration::current();
        if (trim((string) $integration->address_line_1) === '' || trim((string) $integration->city) === '' || trim((string) $integration->state) === '' || trim((string) $integration->pin_code) === '') {
            return ['ok' => false, 'message' => 'Add the hotel address, city, state, and PIN before sending room types.', 'created' => 0];
        }

        $created = 0;
        $types = RoomType::query()->whereNull('doorloom_property_id')->where('is_active', true)->get();
        foreach ($types->chunk(25) as $chunk) {
            $properties = [];
            foreach ($chunk as $type) {
                $properties[] = self::propertyBody($type, true);
            }
            $result = DoorloomClient::bulkCreateProperties($properties, (string) Str::uuid());
            if (! $result['ok']) {
                foreach ($chunk as $type) {
                    $one = DoorloomClient::createProperty(self::propertyBody($type, true), (string) Str::uuid());
                    if ($one['ok']) {
                        self::storePropertyIds($type, $one['json']['data'] ?? []);
                        $created++;
                    } elseif (($one['json']['code'] ?? '') === 'PROPERTY_EXTERNAL_ID_EXISTS') {
                        $id = (int) data_get($one, 'json.errors.doorloom_id', 0);
                        if ($id > 0) {
                            $type->doorloom_property_id = $id;
                            $type->save();
                            $created++;
                        }
                    }
                }

                continue;
            }
            $rows = $result['json']['data']['results'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (! is_array($row) || ($row['status'] ?? '') === 'failed') {
                        $errorId = (int) data_get($row, 'error.errors.doorloom_id', 0);
                        $external = (string) ($row['external_id'] ?? '');
                        if ($errorId > 0 && str_starts_with($external, 'rt-')) {
                            $type = RoomType::query()->find((int) substr($external, 3));
                            if ($type && ! $type->doorloom_property_id) {
                                $type->doorloom_property_id = $errorId;
                                $type->save();
                                $created++;
                            }
                        }

                        continue;
                    }
                    $property = is_array($row['property'] ?? null) ? $row['property'] : [];
                    $external = (string) ($property['external_id'] ?? $row['external_id'] ?? '');
                    if (! str_starts_with($external, 'rt-')) {
                        continue;
                    }
                    $type = RoomType::query()->find((int) substr($external, 3));
                    if ($type) {
                        self::storePropertyIds($type, $property);
                        $created++;
                    }
                }
            }
        }

        return ['ok' => true, 'message' => $created.' room type(s) sent to Doorloom.', 'created' => $created];
    }

    /**
     * @return array<string, mixed>
     */
    public static function propertyBody(RoomType $roomType, bool $includePrice): array
    {
        $integration = DoorloomIntegration::current();
        $profile = Setting::getCompanyProfile();
        $line = trim((string) ($integration->address_line_1 ?: $profile['address'] ?: $profile['name']));
        $roomType->loadMissing('rooms');
        $units = [];
        foreach ($roomType->rooms as $room) {
            if (! $room->is_active) {
                continue;
            }
            $units[] = [
                'name' => 'Room '.$room->room_number,
                'external_id' => 'room-'.$room->id,
                'code' => (string) $room->room_number,
                'max_occupancy' => (int) ($roomType->capacity ?: $roomType->base_occupancy ?: 1),
            ];
        }

        $body = [
            'external_id' => 'rt-'.$roomType->id,
            'name' => $roomType->name,
            'property_type' => 'room',
            'property_category' => 'resort_hotel',
            'description' => trim((string) ($roomType->description ?: $roomType->name.' at '.$profile['name'])) ?: $roomType->name,
            'address' => [
                'address_line_1' => $line !== '' ? $line : 'Hotel',
                'city' => (string) $integration->city,
                'state' => (string) $integration->state,
                'pin_code' => (string) $integration->pin_code,
                'country' => 'India',
            ],
            'space' => [
                'bedrooms' => (int) ($roomType->bedrooms ?? 1),
                'washrooms' => (int) ($roomType->washrooms ?? 1),
            ],
            'occupancy' => [
                'max_guests' => max(1, (int) ($roomType->capacity ?: $roomType->base_occupancy ?: 1)),
            ],
        ];
        if ($units !== []) {
            $body['units'] = $units;
        }
        if ($includePrice) {
            $body['pricing'] = [
                'weekday' => ['base_price' => (float) $roomType->weekday_price],
                'weekend' => ['base_price' => (float) $roomType->weekend_price],
            ];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function storePropertyIds(RoomType $roomType, array $data): void
    {
        $id = (int) ($data['doorloom_id'] ?? 0);
        if ($id > 0) {
            $roomType->doorloom_property_id = $id;
            $roomType->save();
        }
        $units = is_array($data['units'] ?? null) ? $data['units'] : [];
        foreach ($units as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            $external = (string) ($unit['external_unit_id'] ?? '');
            $inventoryId = (int) ($unit['inventory_id'] ?? 0);
            if (! str_starts_with($external, 'room-') || $inventoryId <= 0) {
                continue;
            }
            Room::query()->whereKey((int) substr($external, 5))->update([
                'doorloom_inventory_id' => $inventoryId,
            ]);
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function catchUp(): array
    {
        if (! DoorloomClient::ready()) {
            return ['ok' => false, 'message' => 'Doorloom is not connected.'];
        }
        $after = (int) DoorloomIntegration::current()->highest_sequence;
        $applied = 0;
        do {
            $result = DoorloomClient::events($after, 200);
            if (! $result['ok']) {
                return ['ok' => false, 'message' => $result['message']];
            }
            $rows = is_array($result['json']['data'] ?? null) ? $result['json']['data'] : [];
            foreach ($rows as $row) {
                $event = is_array($row['event'] ?? null) ? $row['event'] : null;
                if ($event) {
                    DoorloomAdapter::apply($event);
                    $applied++;
                }
            }
            $after = (int) data_get($result, 'json.cursor.next_after_sequence', $after);
            $more = (bool) data_get($result, 'json.cursor.has_more', false);
        } while ($more);

        return ['ok' => true, 'message' => 'Caught up '.$applied.' event(s).'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function fullSync(): array
    {
        if (! DoorloomClient::ready()) {
            return ['ok' => false, 'message' => 'Doorloom is not connected.'];
        }
        $result = DoorloomClient::fullSync();
        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }
        $integration = DoorloomIntegration::current();
        $integration->last_full_sync_at = now();
        $integration->save();

        return ['ok' => true, 'message' => $result['message'] ?: 'Full sync queued.'];
    }
}
