<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\InventoryTax;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\BookingSplitStayRoomMove;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\MigratesRoomChartTestSchema;
use Tests\TestCase;

/**
 * Shared fixtures for room chart feature tests. "Now" is frozen at 2026-10-10 10:00 hotel time.
 * Default room type: capacity 3, base occupancy 2, 1 extra bed, 12% tax, day plan ₹2000/night.
 */
abstract class RoomChartTestCase extends TestCase
{
    use MigratesRoomChartTestSchema;

    protected const TODAY = '2026-10-10';

    protected const ALL_RESERVATION_PERMISSIONS = [
        'reservation-view',
        'reservation-create',
        'reservation-create-group',
        'reservation-edit',
        'reservation-delete',
        'reservation-hold-room',
        'reservation-maintenance-room',
    ];

    protected InventoryTax $tax;

    protected RoomType $roomType;

    protected RatePlan $dayPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Kolkata',
            'booking.allow_early_checkout_inspection' => false,
            'booking.allow_early_check_in' => false,
        ]);
        date_default_timezone_set('Asia/Kolkata');
        Carbon::setTestNow(Carbon::parse(self::TODAY . ' 10:00:00', 'Asia/Kolkata'));

        $this->migrateRoomChartTestSchema();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tax = InventoryTax::query()->create(['name' => 'GST 12%', 'rate' => 12]);
        $this->roomType = $this->makeRoomType();
        $this->dayPlan = $this->makeRatePlan($this->roomType);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function day(int $offset): string
    {
        return Carbon::parse(self::TODAY)->addDays($offset)->toDateString();
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, string $name = 'Front Desk'): User
    {
        $user = User::factory()->create(['name' => $name]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function actingWith(array $permissions = self::ALL_RESERVATION_PERMISSIONS): User
    {
        $user = $this->userWith($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeRoomType(array $overrides = []): RoomType
    {
        return RoomType::query()->create(array_merge([
            'name' => 'Deluxe',
            'capacity' => 3,
            'base_occupancy' => 2,
            'extra_bed_capacity' => 1,
            'child_sharing_limit' => 1,
            'extra_bed_cost' => 500,
            'breakfast_price' => 300,
            'child_breakfast_price' => 150,
            'tax_id' => $this->tax->id,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeRatePlan(RoomType $roomType, array $overrides = []): RatePlan
    {
        return RatePlan::query()->create(array_merge([
            'room_type_id' => $roomType->id,
            'name' => 'EP',
            'billing_unit' => 'day',
            'base_price' => 2000,
            'meal_plan_type' => 'room_only',
            'is_active' => true,
        ], $overrides));
    }

    protected function makeHourlyPlan(array $overrides = []): RatePlan
    {
        return $this->makeRatePlan($this->roomType, array_merge([
            'name' => '3 Hour Package',
            'billing_unit' => 'hour_package',
            'package_hours' => 3,
            'package_price' => 1000,
            'base_price' => 1000,
            'overtime_hour_price' => 300,
            'overtime_step_minutes' => 60,
            'grace_minutes' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeRoom(string $number, ?RoomType $roomType = null, array $overrides = []): Room
    {
        return Room::query()->create(array_merge([
            'room_number' => $number,
            'room_type_id' => ($roomType ?? $this->roomType)->id,
            'status' => 'available',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Day-stay booking + matching segment, priced at ₹2000/night + 12% GST unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeBooking(Room $room, string $checkIn, string $checkOut, array $overrides = []): Booking
    {
        $nights = max(1, (int) Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut)));
        $status = $overrides['status'] ?? 'confirmed';

        $booking = Booking::query()->create(array_merge([
            'room_id' => $room->id,
            'rate_plan_id' => $this->dayPlan->id,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'phone' => '9876543210',
            'adults_count' => 2,
            'children_count' => 0,
            'extra_beds_count' => 0,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'check_in_at' => Carbon::parse($checkIn)->startOfDay(),
            'check_out_at' => Carbon::parse($checkOut)->startOfDay(),
            'booking_unit' => 'day',
            'total_price' => round(2000 * $nights * 1.12, 2),
            'deposit_amount' => 0,
            'payment_status' => 'pending',
            'status' => $status,
        ], $overrides));

        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'check_in' => $booking->check_in,
            'check_out' => $booking->check_out,
            'check_in_at' => $booking->check_in_at,
            'check_out_at' => $booking->check_out_at,
            'rate_plan_id' => $booking->rate_plan_id,
            'adults_count' => $booking->adults_count,
            'children_count' => $booking->children_count,
            'extra_beds_count' => $booking->extra_beds_count,
            'total_price' => $booking->total_price,
            'status' => in_array($status, ['checked_in', 'checked_out', 'cancelled'], true) ? $status : 'confirmed',
        ]);

        if ($status === 'checked_in') {
            $room->update(['status' => 'occupied']);
        }

        return $booking->fresh();
    }

    protected function makeBlock(Room $room, string $status, string $start, string $end, array $overrides = []): RoomStatusBlock
    {
        return RoomStatusBlock::query()->create(array_merge([
            'room_id' => $room->id,
            'status' => $status,
            'start_date' => $start,
            'end_date' => $end,
            'note' => ucfirst($status) . ' block',
            'is_active' => true,
        ], $overrides));
    }

    /** Cleared checkout inspection on every room the guest still occupies, as housekeeping leaves it. */
    protected function completeCheckoutInspection(Booking $booking): void
    {
        BookingSplitStayRoomMove::sync((int) $booking->id);
        $roomIds = $booking->segments()
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->pluck('room_id')
            ->whenEmpty(fn ($ids) => $ids->push($booking->room_id))
            ->unique();

        foreach ($roomIds as $roomId) {
            RoomStatusBlock::query()->create([
                'room_id' => $roomId,
                'status' => 'inspected',
                'start_date' => Carbon::parse($booking->check_in)->toDateString(),
                'end_date' => Carbon::parse($booking->check_out)->toDateString(),
                'note' => 'Checkout inspection cleared (no extra charges)',
                'inspection_snapshot' => ['cleared' => true, 'booking_id' => $booking->id, 'room_id' => (int) $roomId],
                'is_active' => true,
            ]);
        }
    }

    protected function setting(string $key, mixed $value): void
    {
        Setting::set($key, $value);
    }
}
