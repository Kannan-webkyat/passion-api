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
     * @return array<string, mixed>
     */
    public static function syncBookings(iterable $bookings): array
    {
        $list = [];
        foreach ($bookings as $booking) {
            $list[] = $booking;
        }
        $doorloom = DoorloomStaySync::syncBookings($list);
        AiosellInventorySync::afterBookings($list);

        return $doorloom;
    }

    /**
     * @return array<string, mixed>
     */
    public static function syncBooking(Booking $booking): array
    {
        return self::syncBookings([$booking]);
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
}
