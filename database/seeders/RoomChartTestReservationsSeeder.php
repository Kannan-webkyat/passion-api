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
 * Sample reservations for Room Chart / checkout / inspection testing.
 *
 * Run: php artisan db:seed --class=RoomChartTestReservationsSeeder
 *
 * Fills gaps for the next {@see BULK_FILL_HORIZON_DAYS} days (including future weeks).
 * Skips slots that already overlap an active segment.
 */
class RoomChartTestReservationsSeeder extends Seeder
{
    /** Chart + bulk fill window (today through this many days ahead). */
    private const BULK_FILL_HORIZON_DAYS = 60;

    public function run(): void
    {
        $today = Carbon::today();
        $adminId = User::query()->value('id');

        /** @var list<array<string, mixed>> $rows */
        $rows = [
            [
                'room_number' => '103',
                'first_name' => 'Ravi',
                'last_name' => 'Checkout',
                'phone' => '9876500999',
                'email' => 'checkout.test@example.com',
                'check_in_offset' => -2,
                'nights' => 2,
                'status' => 'checked_in',
                'payment_status' => 'paid',
                'deposit_amount' => 4400,
                'booking_source' => 'walk-in',
                'adults_count' => 2,
                'notes' => '[Test seed] In-house — checkout today for testing.',
            ],
            [
                'room_number' => '104',
                'first_name' => 'Rahul',
                'last_name' => 'Varma',
                'phone' => '9876500104',
                'check_in_offset' => 0,
                'nights' => 1,
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'deposit_amount' => 0,
                'booking_source' => 'ota',
                'adults_count' => 1,
                'notes' => '[Test seed] Confirmed arrival today.',
            ],
            [
                'room_number' => '105',
                'first_name' => 'Meera',
                'last_name' => 'Nair',
                'phone' => '9876500105',
                'check_in_offset' => 2,
                'nights' => 3,
                'status' => 'confirmed',
                'payment_status' => 'partial',
                'deposit_amount' => 1500,
                'booking_source' => 'phone',
                'adults_count' => 2,
                'children_count' => 1,
                'notes' => '[Test seed] Future stay — mid-week block.',
            ],
            [
                'room_number' => '201',
                'first_name' => 'Arjun',
                'last_name' => 'Kapoor',
                'phone' => '9876500201',
                'check_in_offset' => 0,
                'nights' => 1,
                'status' => 'checked_in',
                'payment_status' => 'paid',
                'deposit_amount' => 6800,
                'booking_source' => 'walk-in',
                'adults_count' => 2,
                'notes' => '[Test seed] Deluxe in-house — single night.',
            ],
            [
                'room_number' => '202',
                'first_name' => 'Sneha',
                'last_name' => 'Iyer',
                'phone' => '9876500202',
                'check_in_offset' => 5,
                'nights' => 2,
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'deposit_amount' => 0,
                'booking_source' => 'website',
                'adults_count' => 1,
                'notes' => '[Test seed] Deluxe weekend reservation.',
            ],
            [
                'room_number' => '203',
                'first_name' => 'Vikram',
                'last_name' => 'Reddy',
                'phone' => '9876500203',
                'check_in_offset' => 1,
                'nights' => 4,
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'deposit_amount' => 12000,
                'booking_source' => 'corporate',
                'adults_count' => 1,
                'notes' => '[Test seed] Longer deluxe stay.',
            ],
            // ── Batch 2: fill empty rooms + back-to-back on partially booked rooms ──
            [
                'room_number' => '204',
                'first_name' => 'Dev',
                'last_name' => 'Prasad',
                'phone' => '9876500204',
                'check_in_offset' => 0,
                'nights' => 3,
                'status' => 'confirmed',
                'payment_status' => 'partial',
                'deposit_amount' => 2000,
                'booking_source' => 'ota',
                'adults_count' => 2,
                'notes' => '[Test seed] Deluxe #204 — 3-night block.',
            ],
            [
                'room_number' => '205',
                'first_name' => 'Latha',
                'last_name' => 'Krishnan',
                'phone' => '9876500205',
                'check_in_offset' => 0,
                'nights' => 2,
                'status' => 'checked_in',
                'payment_status' => 'paid',
                'deposit_amount' => 7500,
                'booking_source' => 'walk-in',
                'adults_count' => 1,
                'notes' => '[Test seed] Deluxe #205 in-house.',
            ],
            [
                'room_number' => '206',
                'first_name' => 'Mohan',
                'last_name' => 'Das',
                'phone' => '9876500206',
                'check_in_offset' => 6,
                'nights' => 3,
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'deposit_amount' => 0,
                'booking_source' => 'website',
                'adults_count' => 2,
                'notes' => '[Test seed] Deluxe #206 — later week.',
            ],
            [
                'room_number' => '301',
                'first_name' => 'Raj',
                'last_name' => 'Family',
                'phone' => '9876500301',
                'check_in_offset' => 0,
                'nights' => 4,
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'deposit_amount' => 14000,
                'booking_source' => 'phone',
                'adults_count' => 2,
                'children_count' => 2,
                'notes' => '[Test seed] Family room — 2 adults + 2 children.',
            ],
            [
                'room_number' => '302',
                'first_name' => 'Anitha',
                'last_name' => 'Joseph',
                'phone' => '9876500302',
                'check_in_offset' => 3,
                'nights' => 2,
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'deposit_amount' => 0,
                'booking_source' => 'ota',
                'adults_count' => 3,
                'notes' => '[Test seed] Family room — mid chart.',
            ],
            [
                'room_number' => '401',
                'first_name' => 'Thomas',
                'last_name' => 'George',
                'phone' => '9876500401',
                'check_in_offset' => 0,
                'nights' => 1,
                'status' => 'checked_in',
                'payment_status' => 'paid',
                'deposit_amount' => 9500,
                'booking_source' => 'walk-in',
                'adults_count' => 2,
                'notes' => '[Test seed] Suite in-house — premium checkout test.',
            ],
            [
                'room_number' => '402',
                'first_name' => 'Diana',
                'last_name' => 'Smith',
                'phone' => '9876500402',
                'check_in_offset' => 7,
                'nights' => 3,
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'deposit_amount' => 18000,
                'booking_source' => 'corporate',
                'adults_count' => 1,
                'notes' => '[Test seed] Suite — corporate block.',
            ],
            [
                'room_number' => '201',
                'first_name' => 'Kiran',
                'last_name' => 'Menon',
                'phone' => '9876500211',
                'check_in_offset' => 1,
                'nights' => 2,
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'deposit_amount' => 0,
                'booking_source' => 'ota',
                'adults_count' => 1,
                'notes' => '[Test seed] Back-to-back on #201 after Arjun.',
            ],
            [
                'room_number' => '104',
                'first_name' => 'Geeta',
                'last_name' => 'Pillai',
                'phone' => '9876500114',
                'check_in_offset' => 1,
                'nights' => 2,
                'status' => 'confirmed',
                'payment_status' => 'partial',
                'deposit_amount' => 1000,
                'booking_source' => 'phone',
                'adults_count' => 2,
                'notes' => '[Test seed] Back-to-back on #104 after Rahul.',
            ],
            [
                'room_number' => '103',
                'first_name' => 'Suresh',
                'last_name' => 'Babu',
                'phone' => '9876500113',
                'check_in_offset' => 2,
                'nights' => 2,
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'deposit_amount' => 0,
                'booking_source' => 'website',
                'adults_count' => 1,
                'notes' => '[Test seed] Follows Priya on #103.',
            ],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $this->createReservationRow($row, $today, $adminId, $created, $skipped, quiet: true);
        }

        $bulkCreated = $this->seedBulkChartFill($today, $adminId, self::BULK_FILL_HORIZON_DAYS, $skipped);
        $created += $bulkCreated;

        $this->command?->info(
            "Done — {$created} created, {$skipped} skipped (bulk fill: {$bulkCreated} over next "
                . self::BULK_FILL_HORIZON_DAYS . ' days).',
        );
    }

    /**
     * Fill empty chart gaps on every room from today through today + $days.
     */
    private function seedBulkChartFill(Carbon $today, ?int $adminId, int $days, int &$skipped): int
    {
        $rangeStart = $today->copy()->startOfDay();
        $rangeEnd = $today->copy()->addDays($days)->startOfDay();
        $firstNames = [
            'Aarav',
            'Isha',
            'Rohan',
            'Kavya',
            'Aditya',
            'Neha',
            'Sanjay',
            'Deepa',
            'Manoj',
            'Lekha',
            'Harish',
            'Pooja',
            'Naveen',
            'Shreya',
            'Gopal',
            'Reena',
            'Farhan',
            'Aisha',
            'Imran',
            'Zara',
            'Chris',
            'Emma',
            'James',
            'Olivia',
            'Noah',
            'Sophia',
            'Liam',
            'Mia',
            'Ethan',
            'Ava',
        ];
        $lastNames = [
            'Sharma',
            'Patel',
            'Gupta',
            'Singh',
            'Kumar',
            'Nair',
            'Menon',
            'Reddy',
            'Iyer',
            'Pillai',
            'Das',
            'Bose',
            'Chopra',
            'Malhotra',
            'Verma',
            'Joshi',
            'Mehta',
            'Kapoor',
            'Khanna',
            'Saxena',
            'Brown',
            'Wilson',
            'Taylor',
            'Anderson',
            'Thomas',
            'Jackson',
            'White',
            'Harris',
            'Martin',
            'Clark',
        ];
        $sources = ['walk-in', 'ota', 'website', 'phone', 'corporate'];
        $payments = ['pending', 'partial', 'paid', 'paid', 'pending'];

        $created = 0;
        $guestIdx = (int) (Booking::query()->max('id') ?? 0);

        foreach (Room::query()->orderBy('room_number')->get() as $room) {
            $cursor = $rangeStart->copy();

            while ($cursor->lt($rangeEnd)) {
                while ($cursor->lt($rangeEnd) && $this->calendarDayOccupied((int) $room->id, $cursor)) {
                    $cursor->addDay();
                }
                if (! $cursor->lt($rangeEnd)) {
                    break;
                }

                $freeRunStart = $cursor->copy();
                while ($cursor->lt($rangeEnd) && ! $this->calendarDayOccupied((int) $room->id, $cursor)) {
                    $cursor->addDay();
                }
                $freeRunEnd = $cursor->copy();
                $packCursor = $freeRunStart->copy();

                while ($packCursor->lt($freeRunEnd)) {
                    $remaining = (int) $packCursor->diffInDays($freeRunEnd);
                    if ($remaining < 1) {
                        break;
                    }
                    $nights = $remaining >= 4 ? min(2, $remaining) : min(3, max(1, $remaining));

                    $fn = $firstNames[$guestIdx % count($firstNames)];
                    $ln = $lastNames[intdiv($guestIdx, count($firstNames)) % count($lastNames)];
                    $startsToday = $packCursor->toDateString() === $today->toDateString();
                    $status = ($startsToday && $guestIdx % 5 === 0) ? 'checked_in' : 'confirmed';

                    $ok = $this->createReservationRow([
                        'room_number' => (string) $room->room_number,
                        'first_name' => $fn,
                        'last_name' => $ln,
                        'phone' => '9800' . str_pad((string) (10000 + $guestIdx), 5, '0', STR_PAD_LEFT),
                        'email' => strtolower($fn . '.' . $ln . $guestIdx . '@test.example'),
                        'check_in_offset' => (int) $rangeStart->diffInDays($packCursor),
                        'nights' => $nights,
                        'status' => $status,
                        'payment_status' => $payments[$guestIdx % count($payments)],
                        'deposit_amount' => $guestIdx % 3 === 0 ? 1500 : 0,
                        'booking_source' => $sources[$guestIdx % count($sources)],
                        'adults_count' => 1 + ($guestIdx % 3),
                        'children_count' => $guestIdx % 4 === 0 ? 1 : 0,
                        'notes' => '[Test seed bulk] Auto-filled chart gap.',
                    ], $today, $adminId, $created, $skipped, quiet: true);

                    if (! $ok) {
                        $packCursor->addDay();
                        break;
                    }

                    $guestIdx++;
                    $packCursor->addDays($nights);
                }
            }
        }

        $this->command?->info(
            "Bulk fill — {$created} reservations added (horizon: "
                . $rangeStart->toDateString() . ' → ' . $rangeEnd->copy()->subDay()->toDateString() . ').',
        );

        return $created;
    }

    /** Guest in-house on this calendar night (check-in day inclusive, check-out day exclusive). */
    private function calendarDayOccupied(int $roomId, Carbon $day): bool
    {
        $dayStart = $day->copy()->startOfDay();
        $dayStr = $dayStart->toDateString();

        return BookingSegment::query()
            ->where('room_id', '=', $roomId)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->whereDate('check_in', '<=', $dayStr)
            ->whereDate('check_out', '>', $dayStr)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createReservationRow(
        array $row,
        Carbon $today,
        ?int $adminId,
        int &$created,
        int &$skipped,
        bool $quiet = false,
    ): bool {
        $room = Room::query()->with('roomType')->where('room_number', '=', (string) $row['room_number'])->first();
        if (! $room) {
            if (! $quiet) {
                $this->command?->warn("Room #{$row['room_number']} not found — skipped.");
            }
            $skipped++;

            return false;
        }

        $checkIn = $today->copy()->addDays((int) $row['check_in_offset'])->startOfDay();
        $checkOut = $checkIn->copy()->addDays(max(1, (int) $row['nights']))->startOfDay();
        $checkOutAt = $checkOut->copy();

        if ($this->roomHasOverlap((int) $room->id, $checkIn, $checkOutAt)) {
            if (! $quiet) {
                $this->command?->warn("Room #{$row['room_number']} busy {$checkIn->toDateString()} → {$checkOut->toDateString()} — skipped.");
            }
            $skipped++;

            return false;
        }

        $ratePlanId = RatePlan::query()
            ->where('room_type_id', '=', (int) $room->room_type_id)
            ->orderBy('id')
            ->value('id');

        $nights = max(1, (int) $row['nights']);
        $base = str_contains(strtolower((string) ($room->roomType?->name ?? '')), 'suite') ? 4500
            : (str_contains(strtolower((string) ($room->roomType?->name ?? '')), 'family') ? 3200
                : (str_contains(strtolower((string) ($room->roomType?->name ?? '')), 'deluxe') ? 2800 : 2200));
        $totalPrice = round($base * $nights * 1.12, 2);

        DB::transaction(function () use (
            $row,
            $room,
            $checkIn,
            $checkOut,
            $checkOutAt,
            $ratePlanId,
            $totalPrice,
            $adminId,
            &$created,
            $quiet,
        ) {
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
                'check_out_at' => $checkOutAt,
                'booking_unit' => 'day',
                'rate_plan_id' => $ratePlanId,
                'total_price' => $totalPrice,
                'payment_status' => $row['payment_status'] ?? 'pending',
                'payment_method' => ($row['payment_status'] ?? '') === 'paid' ? 'cash' : null,
                'deposit_amount' => (float) ($row['deposit_amount'] ?? 0),
                'status' => $row['status'] ?? 'confirmed',
                'booking_source' => $row['booking_source'] ?? 'walk-in',
                'notes' => $row['notes'] ?? '[Test seed]',
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
                'status' => $booking->status === 'checked_in' ? 'checked_in' : 'confirmed',
            ]);

            if ($booking->status === 'checked_in') {
                $room->update(['status' => 'occupied']);
            }

            $created++;
            if (! $quiet) {
                $this->command?->info("Created #{$booking->id} {$row['first_name']} {$row['last_name']} · Room #{$row['room_number']} · {$booking->check_in} → {$booking->check_out} · {$booking->status}");
            }
        });

        return true;
    }

    private function roomHasOverlap(int $roomId, Carbon $checkIn, Carbon $checkOutAt): bool
    {
        return BookingSegment::query()
            ->where('room_id', '=', $roomId)
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $checkOutAt)
            ->where('check_out_at', '>', $checkIn)
            ->exists();
    }
}
