<?php

namespace Tests\Unit\Services;

use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\DailyRoomCleaning;
use App\Models\Room;
use App\Models\RoomCleaningRelease;
use App\Services\HospitalityReportService;
use Carbon\Carbon;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class HospitalityReportServiceTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    private HospitalityReportService $service;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCreatesHousekeepingFixtures();
        $this->service = new HospitalityReportService;
        $this->room = $this->createRoom('101');
        $this->room->update(['is_active' => true]);
    }

    public function test_channel_source_mix_groups_by_source(): void
    {
        $today = Carbon::today()->toDateString();
        $this->makeBooking('walk-in', $today, Carbon::today()->addDays(2)->toDateString(), 5000);
        $this->makeBooking('ota', $today, Carbon::today()->addDay()->toDateString(), 3000);
        $this->makeBooking('ota', $today, Carbon::today()->addDays(3)->toDateString(), 9000);

        $report = $this->service->channelSourceMix($today, $today);

        $this->assertSame(3, $report['summary']['bookings']);
        $this->assertCount(2, $report['by_source']);
        $ota = collect($report['by_source'])->firstWhere('source', 'ota');
        $this->assertSame(2, $ota['bookings']);
    }

    public function test_cleaning_adherence_counts_on_time_and_late(): void
    {
        $today = Carbon::today();
        $this->createActiveRelease($this->room, [
            'window_start' => $today->copy()->setTime(9, 0),
            'window_end' => $today->copy()->setTime(14, 0),
            'status' => RoomCleaningRelease::STATUS_READY,
            'started_at' => $today->copy()->setTime(10, 0),
            'completed_at' => $today->copy()->setTime(12, 0),
            'is_active' => false,
            'service_type' => 'daily',
        ]);
        $this->createActiveRelease($this->room, [
            'window_start' => $today->copy()->setTime(9, 0),
            'window_end' => $today->copy()->setTime(11, 0),
            'status' => RoomCleaningRelease::STATUS_COMPLETED,
            'priority' => 'urgent',
            'service_type' => 'other',
            'service_subtype' => 'rerelease',
            'started_at' => $today->copy()->setTime(10, 0),
            'completed_at' => $today->copy()->setTime(13, 0),
            'is_active' => false,
        ]);

        DailyRoomCleaning::query()->create([
            'room_id' => $this->room->id,
            'service_date' => $today->toDateString(),
            'status' => 'cleaned',
            'completed_at' => $today->copy()->setTime(12, 0),
            'daily_cleaning_completed_at' => $today->copy()->setTime(12, 0),
        ]);

        $report = $this->service->cleaningScheduleAdherence(
            $today->toDateString(),
            $today->toDateString(),
        );

        $this->assertSame(2, $report['summary']['releases_total']);
        $this->assertSame(2, $report['summary']['releases_completed']);
        $this->assertSame(1, $report['summary']['on_time']);
        $this->assertSame(1, $report['summary']['overdue_or_late']);
        $this->assertSame(1, $report['summary']['daily_tasks_completed']);
        $this->assertSame(1, $report['summary']['by_service_subtype']['rerelease']);
    }

    public function test_rooms_performance_computes_occupancy_slice(): void
    {
        $today = Carbon::today();
        $this->makeBooking(
            'website',
            $today->toDateString(),
            $today->copy()->addDays(2)->toDateString(),
            4400,
            'checked_in',
        );

        $report = $this->service->roomsPerformance(
            $today->toDateString(),
            $today->toDateString(),
        );

        $this->assertGreaterThan(0, $report['summary']['room_nights_sold']);
        $this->assertGreaterThan(0, $report['summary']['occupancy_pct']);
        $this->assertNotEmpty($report['by_room_type']);
    }

    public function test_front_office_flash_counts_arrivals(): void
    {
        $today = Carbon::today()->toDateString();
        $this->makeBooking('walk-in', $today, Carbon::today()->addDay()->toDateString(), 2200, 'confirmed');

        $report = $this->service->frontOfficeDailyFlash($today);

        $this->assertSame(1, $report['summary']['arrivals_expected']);
        $this->assertSame(1, $report['summary']['walk_ins']);
        $this->assertCount(1, $report['arrivals']);
    }

    public function test_housekeeping_productivity_counts_completed_daily(): void
    {
        $today = Carbon::today()->toDateString();
        $user = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        DailyRoomCleaning::query()->create([
            'room_id' => $this->room->id,
            'service_date' => $today,
            'status' => 'cleaned',
            'started_at' => Carbon::parse($today)->setTime(10, 0),
            'completed_at' => Carbon::parse($today)->setTime(10, 45),
            'daily_cleaning_completed_at' => Carbon::parse($today)->setTime(10, 45),
            'completed_by' => $user->id,
        ]);

        $report = $this->service->housekeepingProductivity($today, $today);

        $this->assertSame(1, $report['summary']['daily_completed']);
        $this->assertSame(1, $report['summary']['active_staff']);
        $this->assertSame(45.0, $report['summary']['avg_cleaning_minutes']);
    }

    private function makeBooking(
        string $source,
        string $checkIn,
        string $checkOut,
        float $total,
        string $status = 'confirmed',
    ): Booking {
        $booking = Booking::query()->create([
            'room_id' => $this->room->id,
            'first_name' => 'Test',
            'last_name' => 'Guest',
            'adults_count' => 2,
            'children_count' => 0,
            'infants_count' => 0,
            'extra_beds_count' => 0,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'check_in_at' => Carbon::parse($checkIn)->startOfDay(),
            'check_out_at' => Carbon::parse($checkOut)->startOfDay(),
            'booking_unit' => 'day',
            'total_price' => $total,
            'deposit_amount' => 0,
            'payment_status' => 'pending',
            'status' => $status,
            'booking_source' => $source,
        ]);

        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $this->room->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'check_in_at' => Carbon::parse($checkIn)->startOfDay(),
            'check_out_at' => Carbon::parse($checkOut)->startOfDay(),
            'adults_count' => 2,
            'children_count' => 0,
            'extra_beds_count' => 0,
            'total_price' => $total,
            'status' => $status === 'checked_in' ? 'checked_in' : 'confirmed',
        ]);

        return $booking;
    }
}
