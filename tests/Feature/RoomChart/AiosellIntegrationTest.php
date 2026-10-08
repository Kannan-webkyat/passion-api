<?php

namespace Tests\Feature\RoomChart;

use App\Models\AiosellBookingLink;
use App\Models\AiosellIntegration;
use App\Models\AiosellRatePlanMap;
use App\Models\AiosellRoomMap;
use App\Models\Booking;
use App\Jobs\PushAiosellInventory;
use App\Models\BookingPayment;
use App\Models\RoomStatusBlock;
use App\Support\AiosellInventorySync;
use App\Support\AiosellMapping;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class AiosellIntegrationTest extends RoomChartTestCase
{
    /** @var list<array<string, mixed>>|null */
    private ?array $fetchedReservations = null;

    private bool $inventoryFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function ($request) {
            if ($this->fetchedReservations !== null && str_contains($request->url(), '/data/')) {
                return Http::response($this->fetchedReservations, 200);
            }
            if ($this->inventoryFails && str_contains($request->url(), '/update/')) {
                return Http::response(['success' => false, 'message' => 'Service unavailable'], 503);
            }

            return Http::response(['success' => true, 'message' => 'Inventory Updated Successfully'], 200);
        });
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

    public function test_multi_room_prepaid_splits_the_total_and_payment_per_room(): void
    {
        $this->connect();
        $this->makeRoom('101');
        $this->makeRoom('102');
        $payload = $this->bookPayload();
        $payload['amount']['amountAfterTax'] = 11200;
        $payload['rooms'][] = [
            'roomCode' => 'deluxe',
            'occupancy' => ['adults' => 2, 'children' => 0],
            'prices' => [
                ['date' => $this->day(1), 'sellRate' => 3000],
                ['date' => $this->day(2), 'sellRate' => 3000],
            ],
        ];

        $this->postWebhook($payload)->assertOk();

        $bookings = Booking::query()->orderBy('id')->get();
        $this->assertCount(2, $bookings);
        $this->assertEquals([4480, 6720], $bookings->map(fn ($b) => (float) $b->total_price)->all());
        $this->assertEquals([4480, 6720], $bookings->map(fn ($b) => (float) $b->deposit_amount)->all());
        $this->assertEquals(11200, (float) BookingPayment::query()->sum('amount'));
    }

    public function test_modify_into_another_room_type_pushes_both_room_types(): void
    {
        $this->connect();
        $this->makeRoom('101');
        $suite = $this->makeRoomType(['name' => 'Suite']);
        $this->makeRoom('201', $suite);
        AiosellRoomMap::query()->create([
            'room_type_id' => $suite->id,
            'room_code' => 'suite',
            'room_name' => 'Suite',
            'active' => true,
        ]);
        $this->postWebhook($this->bookPayload())->assertOk();

        $payload = $this->bookPayload();
        $payload['action'] = 'modify';
        $payload['rooms'][0]['roomCode'] = 'suite';
        $before = count(Http::recorded());
        $this->postWebhook($payload)->assertOk();

        $codes = collect(Http::recorded())
            ->slice($before)
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), '/update/sample-pms'))
            ->flatMap(fn ($request) => collect($request['updates'])->flatMap(fn ($update) => array_column($update['rooms'], 'roomCode')))
            ->unique()->sort()->values()->all();
        $this->assertSame(['deluxe', 'suite'], $codes);
    }

    public function test_deleting_a_reservation_pushes_inventory(): void
    {
        $this->connect();
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(1), $this->day(3));
        $this->actingWith(['reservation-delete']);

        $this->deleteJson('/api/bookings/'.$booking->id)->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/update/sample-pms'));
    }

    public function test_catch_up_keeps_going_after_a_reservation_fails(): void
    {
        $this->connect();
        $this->makeRoom('101');
        $this->actingWith(['manage-settings']);
        $bad = $this->bookPayload();
        $bad['bookingId'] = 'BDC0';
        $bad['rooms'][0]['roomCode'] = 'unknown';
        unset($bad['creditCard']);
        $good = $this->bookPayload();
        unset($good['creditCard']);
        $this->fetchedReservations = [$bad, $good];

        $this->postJson('/api/aiosell/catch-up')
            ->assertStatus(422)
            ->assertJsonPath('applied', 1)
            ->assertJsonPath('failed.0.booking_id', 'BDC0');

        $this->assertSame('BDC1', Booking::query()->sole()->source_reference);
    }

    public function test_pushed_counts_match_the_per_night_availability_check(): void
    {
        $this->connect();
        $a = $this->makeRoom('101');
        $b = $this->makeRoom('102');
        $c = $this->makeRoom('103');
        $this->makeRoom('104');
        $this->makeBooking($a, $this->day(0), $this->day(3));
        $this->makeBooking($b, $this->day(5), $this->day(6), [
            'booking_unit' => 'hour_package',
            'check_in_at' => Carbon::parse($this->day(5).' 09:00'),
            'check_out_at' => Carbon::parse($this->day(5).' 12:00'),
        ]);
        $this->makeBooking($b, $this->day(8), $this->day(9), [
            'booking_unit' => 'hour_package',
            'check_in_at' => Carbon::parse($this->day(8).' 15:00'),
            'check_out_at' => Carbon::parse($this->day(8).' 18:00'),
        ]);
        $this->makeBooking($c, $this->day(2), $this->day(4), ['status' => 'cancelled']);
        $this->makeBooking($c, $this->day(360), $this->day(370));
        foreach ([['maintenance', true, 10, 12], ['on_hold', true, 20, 21], ['dirty', true, 0, 1], ['maintenance', false, 30, 31]] as [$status, $active, $from, $to]) {
            RoomStatusBlock::query()->create([
                'room_id' => $c->id,
                'status' => $status,
                'start_date' => $this->day($from),
                'end_date' => $this->day($to),
                'is_active' => $active,
            ]);
        }

        AiosellInventorySync::pushInventoryForRoomTypes([$this->roomType->id]);

        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), '/update/sample-pms'))->last();
        $pushed = [];
        foreach ($request['updates'] as $update) {
            for ($d = Carbon::parse($update['startDate']); $d->lte(Carbon::parse($update['endDate'])); $d->addDay()) {
                $pushed[$d->toDateString()] = $update['rooms'][0]['available'];
            }
        }
        $this->assertCount(AiosellInventorySync::WINDOW_DAYS, $pushed);
        foreach ($pushed as $date => $available) {
            $this->assertSame(AiosellInventorySync::availableCount($this->roomType->id, Carbon::parse($date)), $available, $date);
        }
        $this->assertSame(3, $pushed[$this->day(0)]);
        $this->assertSame(3, $pushed[$this->day(4)]);
        $this->assertSame(3, $pushed[$this->day(11)]);
        $this->assertSame(4, $pushed[$this->day(30)]);
    }

    public function test_stay_saves_queue_one_job_per_room_type_after_the_response(): void
    {
        Queue::fake();
        $this->connect();
        $suite = $this->makeRoomType(['name' => 'Suite']);
        $deluxeRoom = $this->makeRoom('101');
        $otherDeluxe = $this->makeRoom('102');
        $suiteRoom = $this->makeRoom('201', $suite);

        AiosellInventorySync::afterRooms([$deluxeRoom->id, $suiteRoom->id]);
        AiosellInventorySync::afterRooms([$otherDeluxe->id]);
        Queue::assertNothingPushed();

        $this->app->terminate();

        Queue::assertPushed(PushAiosellInventory::class, 2);
        Queue::assertPushed(PushAiosellInventory::class, fn ($job) => $job->roomTypeId === $suite->id);
        Http::assertNothingSent();
    }

    public function test_queued_push_throws_to_retry_and_clears_the_error_once_it_succeeds(): void
    {
        $this->connect();
        $this->makeRoom('101');
        $job = new PushAiosellInventory($this->roomType->id);
        $this->inventoryFails = true;

        try {
            $job->handle();
            $this->fail('A failed push must throw so the queue retries it.');
        } catch (\RuntimeException) {
        }
        $this->assertStringStartsWith('Inventory: ', (string) AiosellIntegration::current()->last_error);
        $this->assertTrue((bool) AiosellIntegration::current()->inventory_dirty);

        $this->inventoryFails = false;
        $job->handle();

        $this->assertNull(AiosellIntegration::current()->last_error);
        $this->assertFalse((bool) AiosellIntegration::current()->inventory_dirty);
        $this->assertSame(5, $job->tries);
    }

    public function test_stay_saves_push_once_after_the_response_when_the_queue_is_off(): void
    {
        config(['services.aiosell.queue' => false]);
        $this->connect();
        $first = $this->makeRoom('101');
        $second = $this->makeRoom('102');

        AiosellInventorySync::afterRooms([$first->id]);
        AiosellInventorySync::afterRooms([$second->id]);
        Http::assertNothingSent();

        $this->app->terminate();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/update/sample-pms'));
    }

    public function test_meal_code_comes_from_the_rate_plan_code_suffix(): void
    {
        $this->assertSame('CP', AiosellMapping::mealCodeFromRateplan('executive-d-cp'));
        $this->assertSame('MAP', AiosellMapping::mealCodeFromRateplan('suite-s-map'));
        $this->assertNull(AiosellMapping::mealCodeFromRateplan('990000801753'));
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
