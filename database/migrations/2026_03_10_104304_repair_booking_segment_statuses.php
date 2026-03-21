<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sync segment status for simple (single-segment) bookings
        $bookings = Booking::with('segments')->get();

        foreach ($bookings as $booking) {
            if ($booking->segments->count() === 1) {
                $segment = $booking->segments->first();
                if ($segment->status !== $booking->status) {
                    $segment->update(['status' => $booking->status]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
