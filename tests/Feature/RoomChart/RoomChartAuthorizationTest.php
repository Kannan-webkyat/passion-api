<?php

namespace Tests\Feature\RoomChart;

class RoomChartAuthorizationTest extends RoomChartTestCase
{
    public function test_every_room_chart_endpoint_rejects_user_without_permissions(): void
    {
        $room = $this->makeRoom('101');
        $other = $this->makeRoom('102');
        $booking = $this->makeBooking($room, $this->day(1), $this->day(3));
        $block = $this->makeBlock($other, 'on_hold', $this->day(5), $this->day(6));
        $id = $booking->id;
        $this->actingWith([]);

        $endpoints = [
            ['GET', '/api/bookings/chart'],
            ['GET', '/api/bookings/summary'],
            ['GET', '/api/bookings'],
            ['GET', "/api/bookings/{$id}"],
            ['GET', '/api/bookings/guest-search?phone=9876'],
            ['GET', '/api/bookings/available-rooms?check_in=' . $this->day(1) . '&check_out=' . $this->day(2)],
            ['POST', '/api/bookings'],
            ['POST', '/api/booking-groups'],
            ['PUT', "/api/bookings/{$id}"],
            ['DELETE', "/api/bookings/{$id}"],
            ['POST', "/api/bookings/{$id}/request-inspection"],
            ['POST', "/api/bookings/{$id}/early-checkin"],
            ['POST', "/api/bookings/{$id}/late-checkout"],
            ['POST', "/api/bookings/{$id}/extend"],
            ['POST', "/api/bookings/{$id}/preview-extend"],
            ['POST', "/api/bookings/{$id}/extend-hours"],
            ['POST', "/api/bookings/{$id}/preview-extend-hours"],
            ['POST', "/api/bookings/{$id}/preview-early-checkout"],
            ['POST', "/api/bookings/{$id}/early-checkout"],
            ['POST', "/api/bookings/{$id}/preview-cancellation"],
            ['POST', "/api/bookings/{$id}/cancel"],
            ['POST', "/api/bookings/{$id}/split-stay"],
            ['GET', "/api/bookings/{$id}/room-transfers"],
            ['POST', "/api/bookings/{$id}/preview-room-transfer"],
            ['POST', "/api/bookings/{$id}/room-transfer"],
            ['GET', "/api/bookings/{$id}/payments"],
            ['POST', "/api/bookings/{$id}/payments"],
            ['GET', "/api/bookings/{$id}/folio-postings"],
            ['GET', "/api/bookings/{$id}/inspection-charges"],
            ['GET', "/api/bookings/{$id}/voucher"],
            ['GET', "/api/bookings/{$id}/billing"],
            ['GET', '/api/room-status-blocks'],
            ['POST', '/api/room-status-blocks', [
                'room_id' => $other->id, 'status' => 'maintenance', 'start_date' => $this->day(8), 'end_date' => $this->day(9), 'note' => 'AC',
            ]],
            ['PUT', "/api/room-status-blocks/{$block->id}"],
            ['DELETE', "/api/room-status-blocks/{$block->id}"],
        ];

        $open = [];
        foreach ($endpoints as $endpoint) {
            [$method, $uri] = $endpoint;
            $status = $this->json($method, $uri, $endpoint[2] ?? [])->status();
            if ($status !== 403) {
                $open[] = "{$method} {$uri} → {$status}";
            }
        }

        $this->assertSame([], $open, "Endpoints reachable without permission:\n" . implode("\n", $open));
    }
}
