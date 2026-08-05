<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds exactly 10 non-overlapping bookings (each with a segment) for room-chart QA.
 *
 * Run: php artisan db:seed --class=BookingSeeder
 */
class BookingSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();
        $adminId = User::query()->value('id');

        DB::transaction(function () use ($today, $adminId) {
            Booking::query()->delete();
            Room::query()->update(['status' => 'available']);

            /** @var list<array<string, mixed>> $scenarios */
            $scenarios = [
                [
                    'label' => 'Checkout today',
                    'room_number' => '103',
                    'first_name' => 'Ravi',
                    'last_name' => 'Checkout',
                    'email' => 'checkout.test@example.com',
                    'phone' => '9876500101',
                    'check_in_offset' => -2,
                    'nights' => 2,
                    'status' => 'checked_in',
                    'payment_status' => 'paid',
                    'payment_method' => 'cash',
                    'deposit_amount' => 4400,
                    'booking_source' => 'walk-in',
                    'adults_count' => 2,
                    'notes' => '[Seed] In-house — checkout today.',
                ],
                [
                    'label' => 'In-house multi-night',
                    'room_number' => '101',
                    'first_name' => 'Anurag',
                    'last_name' => 'Mohan',
                    'email' => 'anurag.test@example.com',
                    'phone' => '9876500102',
                    'check_in_offset' => 0,
                    'nights' => 4,
                    'status' => 'checked_in',
                    'payment_status' => 'paid',
                    'payment_method' => 'card',
                    'deposit_amount' => 8800,
                    'booking_source' => 'website',
                    'adults_count' => 2,
                    'children_count' => 1,
                    'notes' => '[Seed] Standard in-house stay.',
                ],
                [
                    'label' => 'Arrival today (confirmed)',
                    'room_number' => '104',
                    'first_name' => 'Rahul',
                    'last_name' => 'Varma',
                    'email' => 'rahul.test@example.com',
                    'phone' => '9876500103',
                    'check_in_offset' => 0,
                    'nights' => 1,
                    'status' => 'confirmed',
                    'payment_status' => 'pending',
                    'deposit_amount' => 0,
                    'booking_source' => 'ota',
                    'adults_count' => 1,
                    'notes' => '[Seed] Confirmed — check in today, balance due.',
                ],
                [
                    'label' => 'Future reservation',
                    'room_number' => '105',
                    'first_name' => 'Meera',
                    'last_name' => 'Nair',
                    'email' => 'meera.test@example.com',
                    'phone' => '9876500104',
                    'check_in_offset' => 3,
                    'nights' => 2,
                    'status' => 'confirmed',
                    'payment_status' => 'partial',
                    'deposit_amount' => 1500,
                    'booking_source' => 'phone',
                    'adults_count' => 2,
                    'children_count' => 1,
                    'notes' => '[Seed] Future mid-week block.',
                ],
                [
                    'label' => 'Checked out yesterday',
                    'room_number' => '201',
                    'first_name' => 'Bob',
                    'last_name' => 'Wilson',
                    'email' => 'bob.test@example.com',
                    'phone' => '9876500105',
                    'check_in_offset' => -4,
                    'nights' => 3,
                    'status' => 'checked_out',
                    'payment_status' => 'paid',
                    'payment_method' => 'cash',
                    'deposit_amount' => 8400,
                    'booking_source' => 'walk-in',
                    'adults_count' => 2,
                    'notes' => '[Seed] Past stay — room ready for dirty/clean flow.',
                ],
                [
                    'label' => 'Arrival tomorrow',
                    'room_number' => '102',
                    'first_name' => 'Alice',
                    'last_name' => 'Smith',
                    'email' => 'alice.test@example.com',
                    'phone' => '9876500106',
                    'check_in_offset' => 1,
                    'nights' => 3,
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'payment_method' => 'upi',
                    'deposit_amount' => 6600,
                    'booking_source' => 'ota',
                    'adults_count' => 1,
                    'children_count' => 1,
                    'notes' => '[Seed] Reserved — arrives tomorrow.',
                ],
                [
                    'label' => 'Partial payment in-house',
                    'room_number' => '202',
                    'first_name' => 'Priya',
                    'last_name' => 'Menon',
                    'email' => 'priya.test@example.com',
                    'phone' => '9876500107',
                    'check_in_offset' => -1,
                    'nights' => 3,
                    'status' => 'checked_in',
                    'payment_status' => 'partial',
                    'deposit_amount' => 2000,
                    'booking_source' => 'walk-in',
                    'adults_count' => 2,
                    'notes' => '[Seed] In-house with balance to collect.',
                ],
                [
                    'label' => 'Multi-adult KYC',
                    'room_number' => '203',
                    'first_name' => 'Chris',
                    'last_name' => 'Gupta',
                    'email' => 'chris.test@example.com',
                    'phone' => '9876500108',
                    'check_in_offset' => -1,
                    'nights' => 3,
                    'status' => 'checked_in',
                    'payment_status' => 'paid',
                    'payment_method' => 'card',
                    'deposit_amount' => 9000,
                    'booking_source' => 'corporate',
                    'adults_count' => 3,
                    'notes' => '[Seed] Three adults — ID upload / KYC testing.',
                ],
                [
                    'label' => 'Family suite future',
                    'room_number' => '301',
                    'first_name' => 'Sanjay',
                    'last_name' => 'Kapoor',
                    'email' => 'sanjay.test@example.com',
                    'phone' => '9876500109',
                    'check_in_offset' => 5,
                    'nights' => 2,
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'payment_method' => 'bank_transfer',
                    'deposit_amount' => 7200,
                    'booking_source' => 'website',
                    'adults_count' => 2,
                    'children_count' => 2,
                    'notes' => '[Seed] Family room — weekend ahead.',
                ],
                [
                    'label' => 'Deluxe corporate future',
                    'room_number' => '302',
                    'first_name' => 'Vikram',
                    'last_name' => 'Reddy',
                    'email' => 'vikram.test@example.com',
                    'phone' => '9876500110',
                    'check_in_offset' => 10,
                    'nights' => 3,
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'payment_method' => 'bank_transfer',
                    'deposit_amount' => 12000,
                    'booking_source' => 'corporate',
                    'adults_count' => 1,
                    'notes' => '[Seed] Long-lead corporate booking.',
                ],
            ];

            foreach ($scenarios as $row) {
                $this->createBookingWithSegment($row, $today, $adminId);
            }
        });

        $this->command?->info('BookingSeeder: removed all prior bookings and created 10 scenario reservations.');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createBookingWithSegment(array $row, Carbon $today, ?int $adminId): void
    {
        $room = Room::query()->with('roomType')->where('room_number', '=', (string) $row['room_number'])->first();
        if (! $room) {
            $this->command?->warn("Room #{$row['room_number']} not found — skipped \"{$row['label']}\".");

            return;
        }

        $checkIn = $today->copy()->addDays((int) $row['check_in_offset'])->startOfDay();
        $nights = max(1, (int) $row['nights']);
        $checkOut = $checkIn->copy()->addDays($nights)->startOfDay();

        $ratePlanId = RatePlan::query()
            ->where('room_type_id', '=', (int) $room->room_type_id)
            ->orderBy('id')
            ->value('id');

        $base = str_contains(strtolower((string) ($room->roomType?->name ?? '')), 'suite') ? 4500
            : (str_contains(strtolower((string) ($room->roomType?->name ?? '')), 'family') ? 3200
                : (str_contains(strtolower((string) ($room->roomType?->name ?? '')), 'deluxe') ? 2800 : 2200));
        $totalPrice = round($base * $nights * 1.12, 2);

        $bookingStatus = (string) ($row['status'] ?? 'confirmed');
        $segmentStatus = match ($bookingStatus) {
            'checked_in' => 'checked_in',
            'checked_out' => 'checked_out',
            default => 'confirmed',
        };

        $booking = Booking::create([
            'room_id' => $room->id,
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'adults_count' => (int) ($row['adults_count'] ?? 1),
            'children_count' => (int) ($row['children_count'] ?? 0),
            'infants_count' => 0,
            'extra_beds_count' => 0,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'booking_unit' => 'day',
            'rate_plan_id' => $ratePlanId,
            'total_price' => $totalPrice,
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'] ?? null,
            'deposit_amount' => (float) ($row['deposit_amount'] ?? 0),
            'status' => $bookingStatus,
            'booking_source' => $row['booking_source'] ?? 'walk-in',
            'notes' => $row['notes'] ?? '[Seed]',
            'created_by' => $adminId,
            'adult_breakfast_count' => 0,
            'child_breakfast_count' => 0,
        ]);

        BookingSegment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'check_in' => $booking->check_in,
            'check_out' => $booking->check_out,
            'check_in_at' => $booking->check_in_at,
            'check_out_at' => $booking->check_out_at,
            'rate_plan_id' => $ratePlanId,
            'adults_count' => $booking->adults_count,
            'children_count' => $booking->children_count,
            'extra_beds_count' => 0,
            'total_price' => $booking->total_price,
            'status' => $segmentStatus,
        ]);

        if ($bookingStatus === 'checked_in') {
            $room->update(['status' => 'occupied']);
        } elseif ($bookingStatus === 'checked_out') {
            $room->update(['status' => 'dirty']);
        }

        $this->command?->info(sprintf(
            '  · %s — #%d %s %s · Room %s · %s → %s · %s',
            $row['label'],
            $booking->id,
            $booking->first_name,
            $booking->last_name,
            $row['room_number'],
            $booking->check_in,
            $booking->check_out,
            $bookingStatus,
        ));
    }
}
