<?php

namespace Tests\Feature\RoomChart;

use App\Models\AiosellBookingLink;
use App\Models\AiosellIntegration;
use App\Models\AiosellRatePlanMap;
use App\Models\AiosellRoomMap;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Support\Facades\Http;

class AiosellIntegrationTest extends RoomChartTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://live.aiosell.com/*' => Http::response(['success' => true, 'message' => 'Inventory Updated Successfully'], 200),
        ]);
    }

    public function test_webhook_rejects_bad_basic_auth(): void
    {
        $this->connect();

        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('user:wrong')])
            ->postJson('/api/aiosell/webhook', $this->bookPayload())
            ->assertUnauthorized();

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_book_assigns_the_lowest_numbered_room_and_a_repeat_does_not_duplicate(): void
    {
        $this->connect();
        $this->makeRoom('102');
        $first = $this->makeRoom('101');

        $this->postWebhook($this->bookPayload())
            ->assertOk()
            ->assertJsonPath('message', 'Reservation Updated Successfully');

        $booking = Booking::query()->first();
        $this->assertNotNull($booking);
        $this->assertSame($first->id, $booking->room_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('booking.com', $booking->booking_source);
        $this->assertSame('BDC1', $booking->source_reference);
        $this->assertSame(1, AiosellBookingLink::query()->count());

        $this->postWebhook($this->bookPayload())->assertOk();
        $this->assertSame(1, Booking::query()->count());
    }

    public function test_prepaid_stay_records_a_bank_transfer(): void
    {
        $this->connect();
        $this->makeRoom('101');

        $this->postWebhook($this->bookPayload())->assertOk();

        $payment = BookingPayment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame('bank_transfer', $payment->method);
        $this->assertSame('aiosell', $payment->source);
        $this->assertEquals(4000, (float) $payment->amount);
        $this->assertEquals(4000, (float) Booking::query()->first()->deposit_amount);
    }

    public function test_push_now_calls_the_inventory_update(): void
    {
        $this->connect();
        $this->makeRoom('101');
        $this->actingWith(['manage-settings']);

        $this->postJson('/api/aiosell/push')->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/update/sample-pms')
                && $request['hotelCode'] === 'HOTEL1';
        });
    }

    public function test_room_type_saved_in_passion_only_holds_rates_until_push_now(): void
    {
        $this->connect();
        $this->mapRatePlan();
        $this->actingWith(['room-types-edit', 'manage-settings']);

        $this->putJson('/api/room-types/'.$this->roomType->id, [
            'capacity' => 4,
            'rate_plans' => [[
                'id' => $this->dayPlan->id,
                'name' => $this->dayPlan->name,
                'base_price' => 4500,
            ]],
            'push_rates' => false,
        ])->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/update-rates/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/update/sample-pms'));
        $this->assertSame([$this->roomType->id], AiosellIntegration::current()->rates_pending_room_type_ids);
        $this->getJson('/api/aiosell')->assertJsonPath('rates_pending_room_types.0.id', $this->roomType->id);

        $this->postJson('/api/aiosell/push')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/update-rates/sample-pms'));
        $this->assertNull(AiosellIntegration::current()->rates_pending_room_type_ids);
    }

    public function test_room_type_save_pushes_rates_by_default(): void
    {
        $this->connect();
        $this->mapRatePlan();
        $this->actingWith(['room-types-edit']);

        $this->putJson('/api/room-types/'.$this->roomType->id, [
            'capacity' => 4,
            'rate_plans' => [[
                'id' => $this->dayPlan->id,
                'name' => $this->dayPlan->name,
                'base_price' => 4500,
            ]],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/update-rates/sample-pms')
            && $request['updates'][0]['rates'][0]['rate'] == 4500);
        $this->getJson('/api/aiosell/status?room_type_id='.$this->roomType->id)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('room_type_mapped', true);
    }

    public function test_cancel_cancels_the_linked_stay(): void
    {
        $this->connect();
        $this->makeRoom('101');
        $this->postWebhook($this->bookPayload())->assertOk();

        $this->postWebhook([
            'action' => 'cancel',
            'hotelCode' => 'HOTEL1',
            'channel' => 'booking.com',
            'bookingId' => 'BDC1',
        ])->assertOk()->assertJsonPath('message', 'Reservation Cancelled Successfully');

        $this->assertSame('cancelled', Booking::query()->first()->status);
    }

    public function test_restrictions_reject_an_empty_channel_list(): void
    {
        $this->connect();
        $this->actingWith(['manage-settings']);

        $this->postJson('/api/aiosell/restrictions', [
            'start_date' => $this->day(1),
            'end_date' => $this->day(2),
            'room_type_id' => $this->roomType->id,
            'channels' => [],
            'stop_sell' => true,
        ])->assertStatus(422);
    }

    private function connect(): void
    {
        $integration = AiosellIntegration::current();
        $integration->forceFill([
            'enabled' => true,
            'username' => 'user',
            'password' => 'pass',
            'partner_id' => 'sample-pms',
            'hotel_code' => 'HOTEL1',
        ])->save();
        AiosellRoomMap::query()->create([
            'room_type_id' => $this->roomType->id,
            'room_code' => 'deluxe',
            'room_name' => 'Deluxe',
            'active' => true,
        ]);
    }

    private function mapRatePlan(): void
    {
        AiosellRatePlanMap::query()->create([
            'room_type_id' => $this->roomType->id,
            'rate_plan_id' => $this->dayPlan->id,
            'room_code' => 'deluxe',
            'rateplan_code' => 'deluxe-d-ep',
            'occupancy_letter' => 'd',
            'meal_code' => 'EP',
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload)
    {
        return $this->withHeaders(['Authorization' => 'Basic '.base64_encode('user:pass')])
            ->postJson('/api/aiosell/webhook', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookPayload(): array
    {
        return [
            'action' => 'book',
            'hotelCode' => 'HOTEL1',
            'channel' => 'booking.com',
            'bookingId' => 'BDC1',
            'cmBookingId' => 'CM1',
            'checkin' => $this->day(1),
            'checkout' => $this->day(3),
            'pah' => false,
            'specialRequests' => 'Late arrival',
            'amount' => [
                'amountAfterTax' => 4000,
                'amountBeforeTax' => 3600,
                'tax' => 400,
                'currency' => 'INR',
                'commission' => 200,
                'tcs' => 10,
                'tds' => 5,
            ],
            'guest' => [
                'firstName' => 'Test',
                'lastName' => 'Guest',
                'phone' => '9876543210',
            ],
            'rooms' => [[
                'roomCode' => 'deluxe',
                'rateplanCode' => 'deluxe-d-ep',
                'occupancy' => ['adults' => 2, 'children' => 0],
                'prices' => [
                    ['date' => $this->day(1), 'sellRate' => 2000],
                    ['date' => $this->day(2), 'sellRate' => 2000],
                ],
            ]],
            'creditCard' => ['number' => '4111111111111111'],
        ];
    }
}
