<?php

namespace Tests\Feature\RoomChart;

use App\Models\BookingSegment;
use Illuminate\Support\Facades\Storage;

class BookingEdgeRulesTest extends RoomChartTestCase
{
    public function test_chart_rejects_reversed_or_oversized_range(): void
    {
        $this->actingWith();

        $this->getJson('/api/bookings/chart?start='.$this->day(5).'&end='.$this->day(1))->assertStatus(422);
        $this->getJson('/api/bookings/chart?start='.$this->day(0).'&end='.$this->day(70))->assertStatus(422);
        $this->getJson('/api/bookings/chart?start='.$this->day(0).'&end='.$this->day(29))->assertOk();
    }

    public function test_walk_in_check_in_reads_utc_arrival_in_hotel_time(): void
    {
        $this->actingWith();
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'phone' => '9876543210',
            'adults_count' => 2,
            'check_in' => $this->day(-1).'T18:30:00Z',
            'check_out' => $this->day(2),
            'rate_plan_id' => $this->dayPlan->id,
            'status' => 'checked_in',
        ])->assertCreated()->assertJsonPath('status', 'checked_in');
    }

    public function test_available_rooms_reads_utc_instants_in_hotel_time(): void
    {
        $this->actingWith();
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(1));

        $ids = collect($this->getJson('/api/bookings/available-rooms?'.http_build_query([
            'check_in' => $this->day(0).'T18:30:00Z',
            'check_out' => $this->day(1).'T18:30:00Z',
        ]))->assertOk()->json())->pluck('id');

        $this->assertContains($room->id, $ids);
    }

    public function test_available_rooms_hides_room_on_nightly_guest_departure_morning(): void
    {
        $this->actingWith();
        $departing = $this->makeRoom('101');
        $free = $this->makeRoom('102');
        $this->makeBooking($departing, $this->day(0), $this->day(1));

        $ids = collect($this->getJson('/api/bookings/available-rooms?'.http_build_query([
            'check_in' => $this->day(1).' 08:00:00',
            'check_out' => $this->day(1).' 11:00:00',
        ]))->assertOk()->json())->pluck('id');

        $this->assertNotContains($departing->id, $ids);
        $this->assertContains($free->id, $ids);
    }

    public function test_payment_status_ignores_client_bill_total(): void
    {
        $this->actingWith();
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/payments", [
            'amount' => 1000,
            'method' => 'cash',
            'bill_total' => 500,
        ])->assertCreated();

        $this->assertSame('partial', $booking->fresh()->payment_status);
    }

    public function test_request_inspection_adds_activity_line(): void
    {
        $this->actingWith();
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$booking->id}/request-inspection")->assertOk();

        $this->assertStringContainsString(
            '[Checkout inspection requested for room 101 by Front Desk on ',
            (string) $booking->fresh()->notes,
        );
    }

    public function test_transfer_preview_does_not_write_a_segment(): void
    {
        $this->actingWith();
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        BookingSegment::query()->where('booking_id', $booking->id)->delete();
        $target = $this->makeRoom('102');

        $this->postJson("/api/bookings/{$booking->id}/preview-room-transfer", [
            'new_room_id' => $target->id,
            'transfer_reason' => 'guest_request',
            'rate_mode' => 'keep_existing',
        ])->assertOk();

        $this->assertDatabaseMissing('booking_segments', ['booking_id' => $booking->id]);
    }

    public function test_refused_edit_removes_new_id_uploads(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }
        Storage::fake('local');
        config(['guest_identity.disk' => 'local', 'guest_identity.directory' => 'identities']);
        $this->actingWith();
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));

        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($img);
        $png = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        imagedestroy($img);

        $this->patchJson("/api/bookings/{$booking->id}", [
            'adults_count' => 6,
            'guest_identities' => [$png],
        ])->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles('identities'));
    }
}
