<?php

namespace Tests\Feature\RoomChart;

class BookingDataExposureTest extends RoomChartTestCase
{
    public function test_chart_hides_guest_contact_and_money_without_reservation_view(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(2), [
            'phone' => '9876543210',
            'email' => 'guest@example.com',
            'guest_identities' => ['identities/guest_id_x_0.jpg'],
            'total_price' => 4480,
        ]);

        $this->actingWith(['view-rooms']);
        $booking = $this->getJson('/api/bookings/chart?start='.$this->day(0).'&end='.$this->day(3))
            ->assertOk()
            ->json('rooms.0.segments.0.booking');

        $this->assertSame('confirmed', $booking['status']);
        $this->assertArrayHasKey('guest_name', $booking);
        $this->assertArrayHasKey('check_in_at', $booking);
        foreach (['phone', 'email', 'guest_identities', 'guest_identity_urls', 'total_price', 'deposit_amount', 'notes', 'guest_gstin'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $booking, "{$hidden} should be hidden");
        }
    }

    public function test_chart_keeps_full_booking_for_reservation_viewers(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(2), ['phone' => '9876543210']);

        $this->actingWith(['reservation-view']);
        $this->getJson('/api/bookings/chart?start='.$this->day(0).'&end='.$this->day(3))
            ->assertOk()
            ->assertJsonPath('rooms.0.segments.0.booking.phone', '9876543210');
    }

    public function test_booking_detail_exposes_only_creator_id_and_name(): void
    {
        $creator = $this->userWith([], 'Reception Lead');
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2), ['created_by' => $creator->id]);

        $this->actingWith(['reservation-view']);
        $creatorJson = $this->getJson("/api/bookings/{$booking->id}")->assertOk()->json('creator');

        $this->assertSame(['id' => $creator->id, 'name' => 'Reception Lead'], array_intersect_key($creatorJson, ['id' => 1, 'name' => 1]));
        $this->assertArrayNotHasKey('email', $creatorJson);
    }

    public function test_aiosell_no_show_and_reply_need_reservation_edit(): void
    {
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->actingWith(['reservation', 'manage-settings']);
        $this->postJson("/api/aiosell/bookings/{$booking->id}/no-show")->assertForbidden();
        $this->postJson("/api/aiosell/bookings/{$booking->id}/messages", ['content' => 'Hi'])->assertForbidden();
    }
}
