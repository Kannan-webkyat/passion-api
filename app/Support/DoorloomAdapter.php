<?php

namespace App\Support;

use App\Models\DoorloomBookingLink;
use App\Models\DoorloomEventCursor;
use App\Models\DoorloomIntegration;
use App\Models\DoorloomNight;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

/**
 * Applies Doorloom webhooks. Walk-in rate plans are never written.
 */
final class DoorloomAdapter
{
    public static function verifySignature(string $secret, string $rawBody, string $header): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = (int) substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $signatures[] = strtolower(substr($part, 3));
            }
        }

        if (! $timestamp || abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $sig) {
            if (strlen($sig) === strlen($expected) && hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function apply(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        if ($type === 'ping') {
            return;
        }

        $sequence = (int) ($event['sequence'] ?? 0);
        $propertyId = (int) data_get($event, 'property.doorloom_id', 0);
        $externalId = data_get($event, 'property.external_id');

        if ($propertyId > 0) {
            self::linkProperty($propertyId, is_string($externalId) ? $externalId : null);
        }

        if ($type === 'booking.changed') {
            self::applyBooking($event);
            self::bumpHighest($sequence);

            return;
        }

        if ($propertyId <= 0 || $sequence <= 0) {
            return;
        }

        $cursor = DoorloomEventCursor::query()->firstOrCreate(
            ['doorloom_property_id' => $propertyId, 'event_type' => $type],
            ['last_sequence' => 0],
        );
        if ($sequence <= (int) $cursor->last_sequence) {
            return;
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $window = is_array($event['window'] ?? null) ? $event['window'] : null;

        DB::transaction(function () use ($type, $data, $window, $propertyId, $cursor, $sequence) {
            if ($type === 'rates.changed') {
                self::applyRates($propertyId, $data, $window);
            } elseif ($type === 'availability.changed') {
                self::applyAvailability($propertyId, $data, $window);
            } elseif ($type === 'restrictions.changed') {
                self::applyRestrictions($propertyId, $data, $window);
            } elseif ($type === 'inventory.changed') {
                self::applyUnits(is_array($data['units'] ?? null) ? $data['units'] : []);
            }

            $cursor->last_sequence = $sequence;
            $cursor->save();
        });

        self::bumpHighest($sequence);
    }

    /**
     * @param  array<string, mixed>  $availability
     */
    public static function applyUnavailablePayload(array $availability): void
    {
        $propertyId = (int) data_get($availability, 'property.doorloom_id', 0);
        $window = is_array($availability['window'] ?? null) ? $availability['window'] : null;
        if ($propertyId <= 0) {
            return;
        }
        self::applyAvailability($propertyId, $availability, $window);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $window
     */
    private static function applyRates(int $propertyId, array $data, ?array $window): void
    {
        $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
        self::replaceWindow($propertyId, $window, $dates, function (DoorloomNight $night, array $row) use ($data) {
            $night->fill([
                'base_price' => $row['base_price'] ?? null,
                'is_weekend' => $row['is_weekend'] ?? null,
                'price_source' => $row['source'] ?? null,
                'base_guests' => $row['base_guests'] ?? null,
                'extra_adult_price' => $row['extra_adult_price'] ?? null,
                'extra_child_price' => $row['extra_child_price'] ?? null,
                'currency' => $data['currency'] ?? 'INR',
                'gst_applicable' => $data['gst_applicable'] ?? null,
                'price_includes_gst' => $data['price_includes_gst'] ?? null,
                'meal_plans' => $data['meal_plans'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $window
     */
    private static function applyAvailability(int $propertyId, array $data, ?array $window): void
    {
        if (is_array($data['units'] ?? null)) {
            self::applyUnits($data['units']);
        }
        $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
        self::replaceWindow($propertyId, $window, $dates, function (DoorloomNight $night, array $row) {
            $night->fill([
                'available_units' => $row['available_units'] ?? null,
                'total_units' => $row['total_units'] ?? null,
                'is_blocked' => $row['is_blocked'] ?? null,
                'block_scope' => $row['block_scope'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $window
     */
    private static function applyRestrictions(int $propertyId, array $data, ?array $window): void
    {
        $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
        self::replaceWindow($propertyId, $window, $dates, function (DoorloomNight $night, array $row) {
            $night->fill([
                'min_nights' => $row['min_nights'] ?? null,
                'max_nights' => $row['max_nights'] ?? null,
                'block_scope' => $row['block_scope'] ?? $night->block_scope,
                'stop_sell' => $row['stop_sell'] ?? null,
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $units
     */
    private static function applyUnits(array $units): void
    {
        foreach ($units as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            $external = (string) ($unit['external_unit_id'] ?? '');
            $inventoryId = (int) ($unit['inventory_id'] ?? 0);
            if (! str_starts_with($external, 'room-') || $inventoryId <= 0) {
                continue;
            }
            $roomId = (int) substr($external, 5);
            $room = Room::query()->find($roomId);
            if (! $room) {
                continue;
            }
            $inactive = ($unit['deleted'] ?? false) === true || ($unit['is_active'] ?? true) === false;
            $room->doorloom_inventory_id = $inactive ? null : $inventoryId;
            if ($inactive) {
                $room->is_active = false;
            }
            $room->save();
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function applyBooking(array $event): void
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $origin = (string) ($data['origin'] ?? '');
        $external = (string) ($data['external_booking_id'] ?? '');
        if ($external === '') {
            return;
        }

        $link = DoorloomBookingLink::query()->where('external_booking_id', $external)->first();
        if (! $link) {
            return;
        }

        $revision = (int) ($data['revision'] ?? $link->revision);
        if ($revision < (int) $link->revision) {
            return;
        }

        $link->revision = $revision;
        $link->doorloom_booking_id = (int) ($data['id'] ?? $link->doorloom_booking_id) ?: null;
        if ((string) ($data['status'] ?? '') === 'cancelled') {
            $link->sync_status = 'cancelled';
        }
        $link->save();

        if ($origin !== 'doorloom') {
            return;
        }

        $booking = $link->booking;
        if (! $booking) {
            return;
        }

        $status = (string) ($data['status'] ?? '');
        if ($status === 'cancelled') {
            if ($booking->status !== 'cancelled') {
                $booking->status = 'cancelled';
                $booking->cancelled_at = $booking->cancelled_at ?? now();
                $booking->cancellation_notes = (string) ($data['cancellation_note'] ?? $booking->cancellation_notes);
            }
        } else {
            if (! empty($data['check_in'])) {
                $booking->check_in = $data['check_in'];
            }
            if (! empty($data['check_out'])) {
                $booking->check_out = $data['check_out'];
            }
            $guestName = trim((string) data_get($data, 'guest.name', ''));
            if ($guestName !== '') {
                $parts = preg_split('/\s+/', $guestName, 2) ?: [];
                $booking->first_name = $parts[0] ?? $booking->first_name;
                $booking->last_name = $parts[1] ?? $booking->last_name;
            }
            $mobile = trim((string) data_get($data, 'guest.mobile', ''));
            if ($mobile !== '') {
                $booking->phone = $mobile;
            }
            if (array_key_exists('notes', $data)) {
                $booking->notes = $data['notes'];
            }
            if (isset($data['pricing']['total'])) {
                $booking->total_price = $data['pricing']['total'];
            }
        }
        $booking->save();
    }

    /**
     * @param  array<string, mixed>|null  $window
     * @param  list<mixed>  $dates
     * @param  callable(DoorloomNight, array<string, mixed>): void  $fill
     */
    private static function replaceWindow(int $propertyId, ?array $window, array $dates, callable $fill): void
    {
        $roomTypeId = RoomType::query()->where('doorloom_property_id', $propertyId)->value('id');
        $seen = [];
        foreach ($dates as $row) {
            if (! is_array($row) || empty($row['date'])) {
                continue;
            }
            $date = (string) $row['date'];
            $seen[] = $date;
            $night = DoorloomNight::query()->firstOrNew([
                'doorloom_property_id' => $propertyId,
                'night_date' => $date,
            ]);
            $night->room_type_id = $roomTypeId;
            $fill($night, $row);
            $night->save();
        }

        if (is_array($window) && ! empty($window['from']) && ! empty($window['to']) && $seen !== []) {
            DoorloomNight::query()
                ->where('doorloom_property_id', $propertyId)
                ->whereDate('night_date', '>=', $window['from'])
                ->whereDate('night_date', '<=', $window['to'])
                ->whereNotIn('night_date', $seen)
                ->delete();
        }
    }

    private static function linkProperty(int $propertyId, ?string $externalId): void
    {
        if ($externalId !== null && str_starts_with($externalId, 'rt-')) {
            $roomTypeId = (int) substr($externalId, 3);
            $roomType = RoomType::query()->find($roomTypeId);
            if ($roomType && $roomType->doorloom_property_id === null) {
                $roomType->doorloom_property_id = $propertyId;
                $roomType->save();
            }
        }
    }

    private static function bumpHighest(int $sequence): void
    {
        if ($sequence <= 0) {
            return;
        }
        $integration = DoorloomIntegration::current();
        if ($sequence > (int) $integration->highest_sequence) {
            $integration->highest_sequence = $sequence;
            $integration->save();
        }
    }
}
