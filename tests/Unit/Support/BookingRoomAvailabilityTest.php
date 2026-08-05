<?php

namespace Tests\Unit\Support;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\RoomType;
use App\Support\BookingRoomAvailability;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class BookingRoomAvailabilityTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateHousekeepingTestSchema();
        $this->ensureRoomTypeCapacityColumns();
    }

    private function ensureRoomTypeCapacityColumns(): void
    {
        $sm = \Illuminate\Support\Facades\Schema::getConnection()->getSchemaBuilder();
        if (! $sm->hasColumn('room_types', 'base_occupancy')) {
            $sm->table('room_types', function ($table) {
                $table->unsignedInteger('base_occupancy')->default(2);
                $table->unsignedInteger('extra_bed_capacity')->default(0);
                $table->unsignedInteger('child_sharing_limit')->default(1);
                $table->boolean('is_active')->default(true);
            });
        }
    }

    private function createCapacityRoom(array $typeAttrs = []): Room
    {
        $roomType = RoomType::query()->create(array_merge([
            'name' => 'Standard',
            'description' => 'Test',
            'capacity' => 2,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 1,
            'child_sharing_limit' => 1,
            'is_active' => true,
        ], $typeAttrs));

        return Room::query()->create([
            'room_number' => 'T'.random_int(100, 999),
            'room_type_id' => $roomType->id,
            'status' => 'available',
            'is_active' => true,
        ]);
    }

    public function test_segment_overlap_blocks_second_booking(): void
    {
        $room = $this->createCapacityRoom();
        $checkIn = Carbon::today()->startOfDay();
        $checkOut = Carbon::today()->addDays(2)->startOfDay();

        $booking = Booking::query()->create([
            'room_id' => $room->id,
            'status' => 'confirmed',
            'first_name' => 'A',
            'last_name' => 'Guest',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'adults_count' => 1,
        ]);

        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => 'confirmed',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
        ]);

        $this->assertTrue(
            BookingRoomAvailability::hasSegmentOverlap($room->id, $checkIn->copy()->addDay(), $checkOut->copy()->addDay())
        );

        $this->expectException(ValidationException::class);
        BookingRoomAvailability::assertSellable(
            $room->id,
            $checkIn->copy()->addDay(),
            $checkOut->copy()->addDay(),
            'confirmed',
        );
    }

    public function test_cancelled_segment_does_not_block(): void
    {
        $room = $this->createCapacityRoom();
        $checkIn = Carbon::today()->startOfDay();
        $checkOut = Carbon::today()->addDays(2)->startOfDay();

        $booking = Booking::query()->create([
            'room_id' => $room->id,
            'status' => 'cancelled',
            'first_name' => 'A',
            'last_name' => 'Guest',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'adults_count' => 1,
        ]);

        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => 'cancelled',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
        ]);

        $this->assertFalse(
            BookingRoomAvailability::hasSegmentOverlap($room->id, $checkIn, $checkOut)
        );

        BookingRoomAvailability::assertSellable($room->id, $checkIn, $checkOut, 'confirmed');
        $this->assertTrue(true);
    }

    public function test_dirty_block_allows_confirmed_but_blocks_checkin(): void
    {
        $room = $this->createCapacityRoom();
        $checkIn = Carbon::today()->startOfDay();
        $checkOut = Carbon::today()->addDay()->startOfDay();

        RoomStatusBlock::query()->create([
            'room_id' => $room->id,
            'status' => 'dirty',
            'is_active' => true,
            'start_date' => $checkIn->toDateString(),
            'end_date' => $checkOut->toDateString(),
        ]);

        BookingRoomAvailability::assertSellable($room->id, $checkIn, $checkOut, 'confirmed');

        $this->expectException(ValidationException::class);
        BookingRoomAvailability::assertSellable($room->id, $checkIn, $checkOut, 'checked_in');
    }

    public function test_maintenance_block_prevents_booking(): void
    {
        $room = $this->createCapacityRoom();
        $checkIn = Carbon::today()->startOfDay();
        $checkOut = Carbon::today()->addDay()->startOfDay();

        RoomStatusBlock::query()->create([
            'room_id' => $room->id,
            'status' => 'maintenance',
            'is_active' => true,
            'start_date' => $checkIn->toDateString(),
            'end_date' => $checkOut->toDateString(),
        ]);

        $this->assertFalse(
            BookingRoomAvailability::isListedAsAvailable($room->id, $checkIn, $checkOut)
        );

        $this->expectException(ValidationException::class);
        BookingRoomAvailability::assertSellable($room->id, $checkIn, $checkOut, 'confirmed');
    }

    public function test_capacity_rejects_overbooked_guests(): void
    {
        $room = $this->createCapacityRoom([
            'capacity' => 2,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 0,
        ]);

        $errors = BookingRoomAvailability::capacityErrors($room->roomType, 3, 0, 0);
        $this->assertNotEmpty($errors);

        $this->expectException(ValidationException::class);
        BookingRoomAvailability::assertCapacity($room, 3, 0, 0);
    }

    public function test_capacity_allows_valid_extra_bed(): void
    {
        $room = $this->createCapacityRoom([
            'capacity' => 3,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 1,
        ]);

        $this->assertSame([], BookingRoomAvailability::capacityErrors($room->roomType, 3, 0, 1));
        BookingRoomAvailability::assertCapacity($room, 3, 0, 1);
        $this->assertTrue(true);
    }

    public function test_exclude_booking_id_allows_same_stay_on_update(): void
    {
        $room = $this->createCapacityRoom();
        $checkIn = Carbon::today()->startOfDay();
        $checkOut = Carbon::today()->addDays(2)->startOfDay();

        $booking = Booking::query()->create([
            'room_id' => $room->id,
            'status' => 'confirmed',
            'first_name' => 'A',
            'last_name' => 'Guest',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'adults_count' => 1,
        ]);

        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => 'confirmed',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
        ]);

        BookingRoomAvailability::assertSellable(
            $room->id,
            $checkIn,
            $checkOut->copy()->addDay(),
            'confirmed',
            (int) $booking->id,
        );
        $this->assertTrue(true);
    }
}
