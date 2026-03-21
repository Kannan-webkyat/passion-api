<?php

use App\Models\Booking;
use App\Models\BookingSegment;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For every booking that doesn't have segments, create one based on the booking details
        $bookings = Booking::doesntHave('segments')->get();

        foreach ($bookings as $booking) {
            BookingSegment::create([
                'booking_id' => $booking->id,
                'room_id' => $booking->room_id,
                'check_in' => $booking->check_in,
                'check_out' => $booking->check_out,
                'rate_plan_id' => $booking->rate_plan_id,
                'adults_count' => $booking->adults_count ?? 1,
                'children_count' => $booking->children_count ?? 0,
                'extra_beds_count' => $booking->extra_beds_count ?? 0,
                'total_price' => $booking->total_price,
                'status' => $booking->status,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No easy way to distinguish between segments created by this migration vs others
        // but we could delete segments for bookings that only have one segment if we're careful.
        // For safety, we'll just leave them.
    }
};
