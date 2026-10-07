<?php

namespace Tests\Feature\RoomChart;

use App\Models\DoorloomBookingLink;
use App\Models\DoorloomIntegration;
use App\Models\DoorloomNight;
use App\Models\RatePlan;
use App\Support\DoorloomAdapter;
use App\Support\DoorloomStaySync;
use Illuminate\Support\Facades\Http;

class DoorloomIntegrationTest extends RoomChartTestCase
{
    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->connect('secret');

        $this->call('POST', '/api/doorloom/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DOORLOOM_SIGNATURE' => 't='.time().',v1=deadbeef',
        ], '{"type":"ping"}')->assertUnauthorized();
    }

    public function test_a_lower_sequence_is_ignored(): void
    {
        $this->roomType->update(['doorloom_property_id' => 9001]);
        $this->postEvent($this->ratesEvent(5, '2026-10-12', 3100));
        $this->postEvent($this->ratesEvent(4, '2026-10-12', 1000));

        $this->assertSame('3100.00', DoorloomNight::query()->first()->base_price);
    }

    public function test_rates_changed_does_not_change_the_walk_in_price(): void
    {
        $this->roomType->update(['doorloom_property_id' => 9001]);
        $plan = RatePlan::query()->find($this->dayPlan->id);

        $this->postEvent($this->ratesEvent(8, '2026-10-12', 4500));

        $this->assertSame('2000.00', $plan->fresh()->base_price);
        $this->assertSame('4500.00', DoorloomNight::query()->first()->base_price);
    }

    public function test_sending_room_types_stores_the_ids_doorloom_returns(): void
    {
        $this->connect('secret');
        $room = $this->makeRoom('101');
        Http::fake([
            'https://doorloom.com/api/integrations/v1/properties/bulk' => Http::response([
                'data' => [
                    'results' => [[
                        'status' => 'created',
                        'property' => [
                            'external_id' => 'rt-'.$this->roomType->id,
                            'doorloom_id' => 9001,
                            'units' => [[
                                'external_unit_id' => 'room-'.$room->id,
                                'inventory_id' => 77,
                            ]],
                        ],
                    ]],
                ],
            ], 200),
        ]);

        $result = DoorloomStaySync::pushListings();

        $this->assertTrue($result['ok']);
        $this->assertSame(9001, (int) $this->roomType->fresh()->doorloom_property_id);
        $this->assertSame(77, (int) $room->fresh()->doorloom_inventory_id);
    }

    public function test_an_overnight_stay_sends_one_doorloom_booking_per_room_type(): void
    {
        $this->connect('secret');
        $this->roomType->update(['doorloom_property_id' => 9001]);
        $other = $this->makeRoomType(['name' => 'Suite', 'doorloom_property_id' => 9002]);
        $this->makeRatePlan($other, ['name' => 'Suite EP']);
        $a = $this->makeBooking($this->makeRoom('101'), $this->day(2), $this->day(4));
        $b = $this->makeBooking($this->makeRoom('201', $other), $this->day(2), $this->day(4), [
            'rate_plan_id' => RatePlan::query()->where('room_type_id', $other->id)->value('id'),
        ]);
        Http::fake([
            'https://doorloom.com/api/integrations/v1/bookings' => Http::response([
                'data' => ['id' => 55, 'revision' => 1],
            ], 201),
        ]);

        DoorloomStaySync::syncBookings([$a, $b]);

        Http::assertSentCount(2);
        $this->assertSame(2, DoorloomBookingLink::query()->count());
    }

    public function test_a_409_writes_the_returned_nights_and_keeps_the_passion_booking(): void
    {
        $this->connect('secret');
        $this->roomType->update(['doorloom_property_id' => 9001]);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(2), $this->day(3));
        Http::fake([
            'https://doorloom.com/api/integrations/v1/bookings' => Http::response([
                'code' => 'UNAVAILABLE',
                'message' => 'Those dates are not free on Doorloom.',
                'errors' => [
                    'availability' => [
                        'property' => ['doorloom_id' => 9001],
                        'window' => ['from' => $this->day(2), 'to' => $this->day(2)],
                        'dates' => [[
                            'date' => $this->day(2),
                            'available_units' => 0,
                            'total_units' => 1,
                        ]],
                    ],
                ],
            ], 409),
        ]);

        $result = DoorloomStaySync::syncBooking($booking);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $night = DoorloomNight::query()->first();
        $this->assertSame($this->day(2), $night->night_date->toDateString());
        $this->assertSame(0, (int) $night->available_units);
    }

    public function test_a_partner_echo_stores_the_revision_and_leaves_the_guest(): void
    {
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(2), $this->day(4));
        $link = DoorloomBookingLink::query()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $this->roomType->id,
            'external_booking_id' => 'b-'.$booking->id.'-rt-'.$this->roomType->id,
            'revision' => 1,
            'sync_status' => 'synced',
        ]);

        DoorloomAdapter::apply([
            'type' => 'booking.changed',
            'sequence' => 12,
            'data' => [
                'origin' => 'partner',
                'id' => 55,
                'revision' => 2,
                'external_booking_id' => $link->external_booking_id,
                'guest' => ['name' => 'Someone Else', 'mobile' => '9000000000'],
            ],
        ]);

        $this->assertSame('Asha', $booking->fresh()->first_name);
        $this->assertSame(2, (int) $link->fresh()->revision);
    }

    private function connect(string $secret): void
    {
        DoorloomIntegration::query()->create([
            'enabled' => true,
            'api_key' => 'test-key',
            'webhook_secret' => $secret,
            'address_line_1' => 'Lake Road',
            'city' => 'Munnar',
            'state' => 'Kerala',
            'pin_code' => '685612',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postEvent(array $event): void
    {
        $this->connect('secret');
        $body = json_encode($event);
        $stamp = time();
        $signature = hash_hmac('sha256', $stamp.'.'.$body, 'secret');
        $this->call('POST', '/api/doorloom/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DOORLOOM_SIGNATURE' => 't='.$stamp.',v1='.$signature,
        ], $body)->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function ratesEvent(int $sequence, string $date, int $price): array
    {
        return [
            'type' => 'rates.changed',
            'sequence' => $sequence,
            'property' => [
                'doorloom_id' => 9001,
                'external_id' => 'rt-'.$this->roomType->id,
            ],
            'window' => ['from' => $date, 'to' => $date],
            'data' => [
                'currency' => 'INR',
                'dates' => [[
                    'date' => $date,
                    'base_price' => $price,
                ]],
            ],
        ];
    }
}
