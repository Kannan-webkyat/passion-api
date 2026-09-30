<?php

namespace App\Support;

use App\Models\Booking;

/**
 * Guest-facing reservation number (e.g. RES-000123), derived from the booking id once it exists
 * so concurrent creates can never collide. Stored on bookings.booking_number and never changed.
 */
final class BookingNumber
{
    public const PREFIX = 'RES-';

    public static function format(int $bookingId): string
    {
        return self::PREFIX.str_pad((string) $bookingId, 6, '0', STR_PAD_LEFT);
    }

    public static function assign(Booking $booking): void
    {
        if (trim((string) ($booking->booking_number ?? '')) !== '') {
            return;
        }

        $booking->forceFill(['booking_number' => self::format((int) $booking->id)])->save();
    }

    public static function for(Booking $booking): string
    {
        $stored = trim((string) ($booking->booking_number ?? ''));

        return $stored !== '' ? $stored : self::format((int) $booking->id);
    }
}
