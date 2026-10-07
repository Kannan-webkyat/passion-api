<?php

namespace Tests\Feature;

use App\Models\LaundryRequest;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class LaundryChargeCountTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateLaundryTables();
    }

    public function test_charge_qty_cannot_exceed_the_collected_count(): void
    {
        $user = $this->createUserWithPermission('housekeeping-laundry');
        $room = $this->createRoom('207');
        $today = Carbon::today();
        $bookingId = \DB::table('bookings')->insertGetId([
            'room_id' => $room->id,
            'status' => 'checked_in',
            'first_name' => 'Anurag',
            'last_name' => 'Mohan',
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = LaundryRequest::query()->create([
            'booking_id' => $bookingId,
            'room_id' => $room->id,
            'guest_name' => 'Anurag Mohan',
            'pickup_at' => now(),
            'status' => LaundryRequest::STATUS_PICKED_UP,
            'pickup_items' => [
                ['label' => 'Shirt', 'qty' => 1],
                ['label' => 'pants', 'qty' => 2],
            ],
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/housekeeping/laundry/{$request->id}/lines", [
            'lines' => [
                ['item_type' => 'towel', 'service_type' => 'wash', 'qty' => 1, 'unit_price' => 10],
            ],
        ])->assertStatus(422)->assertJsonPath('message', 'Choose an item from the collection.');

        $this->postJson("/api/housekeeping/laundry/{$request->id}/lines", [
            'lines' => [
                ['item_type' => 'pants', 'service_type' => 'wash', 'qty' => 1, 'unit_price' => 20],
                ['item_type' => 'Pants', 'service_type' => 'iron_only', 'qty' => 2, 'unit_price' => 15],
            ],
        ])->assertStatus(422)->assertJsonPath('message', 'pants was collected as 2. The charge quantity is higher.');

        $this->postJson("/api/housekeeping/laundry/{$request->id}/lines", [
            'lines' => [
                ['item_type' => 'shirt', 'service_type' => 'wash', 'qty' => 1, 'unit_price' => 200],
                ['item_type' => 'pants', 'service_type' => 'wash', 'qty' => 2, 'unit_price' => 150],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('laundry_request_lines', [
            'laundry_request_id' => $request->id,
            'item_type' => 'Shirt',
            'qty' => 1,
        ]);
        $this->assertDatabaseHas('laundry_request_lines', [
            'laundry_request_id' => $request->id,
            'item_type' => 'pants',
            'qty' => 2,
        ]);
    }

    private function migrateLaundryTables(): void
    {
        if (! Schema::hasTable('laundry_requests')) {
            Schema::create('laundry_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('booking_id');
                $table->unsignedBigInteger('room_id');
                $table->string('guest_name');
                $table->dateTime('pickup_at');
                $table->text('notes')->nullable();
                $table->text('damage_notes')->nullable();
                $table->boolean('express')->default(false);
                $table->decimal('express_surcharge_amount', 10, 2)->default(0);
                $table->string('status', 32)->default('pending_pickup');
                $table->json('pickup_items')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->decimal('posted_amount', 10, 2)->nullable();
                $table->timestamp('picked_up_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('laundry_request_lines')) {
            Schema::create('laundry_request_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('laundry_request_id');
                $table->string('item_type');
                $table->string('service_type', 32);
                $table->decimal('qty', 10, 2);
                $table->decimal('unit_price', 10, 2);
                $table->decimal('line_total', 10, 2);
                $table->timestamps();
            });
        }
    }
}
