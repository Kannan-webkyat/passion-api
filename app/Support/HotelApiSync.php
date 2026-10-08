<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\RoomType;

/**
 * Fans a Passion save out to each hotel API. Each integration no-ops when it is off.
 */
final class HotelApiSync
{
    /**
     * @param  iterable<Booking>  $bookings
     * @param  list<int>  $previousRoomIds
     * @return array<string, mixed>
     */
    public static function syncBookings(iterable $bookings, array $previousRoomIds = []): array
    {
        $list = [];
        foreach ($bookings as $booking) {
            $list[] = $booking;
        }
        $doorloom = DoorloomStaySync::syncBookings($list);
        AiosellInventorySync::afterBookings($list, $previousRoomIds);

        return $doorloom;
    }

    /**
     * @param  list<int>  $previousRoomIds
     * @return array<string, mixed>
     */
    public static function syncBooking(Booking $booking, array $previousRoomIds = []): array
    {
        return self::syncBookings([$booking], $previousRoomIds);
    }

    public static function afterRoomType(RoomType $roomType, bool $pushRates = true): void
    {
        DoorloomStaySync::updateListingIfLinked($roomType);
        AiosellInventorySync::pushInventoryForRoomTypes([(int) $roomType->id]);
        if ($pushRates) {
            AiosellInventorySync::pushRatesForRoomType((int) $roomType->id);
        } else {
            AiosellInventorySync::holdRatesForRoomType((int) $roomType->id);
        }
    }

    public static function afterRoomBlock(int $roomId): void
    {
        AiosellInventorySync::afterRoom($roomId);
    }

    /**
     * @param  list<int>  $roomIds
     */
    public static function afterRoomsFreed(array $roomIds): void
    {
        AiosellInventorySync::afterRooms($roomIds);
    }
}
