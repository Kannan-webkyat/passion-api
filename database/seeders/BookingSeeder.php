<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        $rooms = Room::all();
        $admin = User::first();
        $today = Carbon::today();

        if ($rooms->count() > 0) {
            // Room 101: Occupied today
            Booking::create([
                'room_id' => $rooms->where('room_number', '101')->first()->id,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'phone' => '1234567890',
                'adults_count' => 2,
                'check_in' => $today->copy()->subDays(2),
                'check_out' => $today->copy()->addDays(2),
                'total_price' => 5000,
                'payment_status' => 'paid',
                'payment_method' => 'card',
                'status' => 'checked_in',
                'booking_source' => 'website',
                'created_by' => $admin->id ?? null
            ]);

            // Room 102: Upcoming tomorrow
            Booking::create([
                'room_id' => $rooms->where('room_number', '102')->first()->id,
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'adults_count' => 1,
                'children_count' => 1,
                'check_in' => $today->copy()->addDay(),
                'check_out' => $today->copy()->addDays(4),
                'total_price' => 7500,
                'payment_status' => 'pending',
                'status' => 'confirmed',
                'booking_source' => 'ota',
                'created_by' => $admin->id ?? null
            ]);

            // Room 201: Checked out yesterday
            Booking::create([
                'room_id' => $rooms->where('room_number', '201')->first()->id,
                'first_name' => 'Bob',
                'last_name' => 'Wilson',
                'adults_count' => 2,
                'check_in' => $today->copy()->subDays(5),
                'check_out' => $today->copy()->subDay(),
                'total_price' => 12000,
                'payment_status' => 'paid',
                'payment_method' => 'cash',
                'status' => 'checked_out',
                'booking_source' => 'walk-in',
                'created_by' => $admin->id ?? null
            ]);
            
            // Update room statuses based on bookings
            $rooms->where('room_number', '101')->first()->update(['status' => 'occupied']);
            $rooms->where('room_number', '201')->first()->update(['status' => 'dirty']);
        }
    }
}
