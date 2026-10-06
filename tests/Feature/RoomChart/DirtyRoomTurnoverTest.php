<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\Department;
use App\Models\HousekeepingJob;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\RestaurantMaster;
use App\Models\Room;
use App\Models\RoomCleaningRelease;
use App\Models\RoomStatusBlock;
use App\Models\User;
use App\Support\BookingPaymentLedger;
use App\Support\BookingSplitStayRoomMove;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Checkout → Dirty Rooms board → assign → start cleaning → job draft → finish, plus the manual
 * dirty/cleaning blocks from the Room Chart and the guards around them.
 */
class DirtyRoomTurnoverTest extends RoomChartTestCase
{
    private const HK_OPERATOR = ['housekeeping-dirty-rooms', 'housekeeping-cleaning-tasks'];

    private User $frontDesk;

    private User $supervisor;

    private User $housekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateTurnoverTables();

        $this->frontDesk = $this->userWith(['reservation-edit', 'reservation-view'], 'Front Desk');
        $this->supervisor = $this->userWith(
            [...self::HK_OPERATOR, 'housekeeping-assignable', 'housekeeping-clean-rooms'],
            'HK Supervisor',
        );
        $this->housekeeper = $this->userWith([], 'Meera Housekeeper');

        $hk = Department::query()->create(['name' => 'Housekeeping', 'code' => 'HKP', 'is_active' => true, 'is_housekeeping' => true]);
        $hk->users()->attach($this->housekeeper->id);
        $fo = Department::query()->create(['name' => 'Front Office', 'code' => 'FO', 'is_active' => true, 'is_housekeeping' => false]);
        $fo->users()->attach($this->frontDesk->id);
    }

    private function migrateTurnoverTables(): void
    {
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_housekeeping')->default(false);
                $table->timestamps();
            });
            Schema::create('department_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('department_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('housekeeping_jobs')) {
            Schema::create('housekeeping_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_status_block_id');
                $table->unsignedBigInteger('room_id');
                $table->string('status')->default('in_progress');
                $table->unsignedBigInteger('started_by')->nullable();
                $table->unsignedBigInteger('finished_by')->nullable();
                $table->text('remarks')->nullable();
                $table->string('issues_summary', 500)->nullable();
                $table->timestamps();
            });
            Schema::create('housekeeping_job_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('housekeeping_job_id');
                $table->string('kind');
                $table->unsignedBigInteger('inventory_item_id')->nullable();
                $table->unsignedBigInteger('menu_item_id')->nullable();
                $table->decimal('qty', 15, 3)->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('inventory_transactions')) {
            Schema::create('inventory_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('inventory_item_id');
                $table->unsignedBigInteger('inventory_location_id')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->string('type');
                $table->decimal('quantity', 14, 4);
                $table->decimal('unit_cost', 12, 4)->nullable();
                $table->decimal('total_cost', 12, 2)->nullable();
                $table->string('department')->nullable();
                $table->string('reason')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('reference_id')->nullable();
                $table->string('reference_type')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('room_cleaning_release_audits')) {
            Schema::create('room_cleaning_release_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_cleaning_release_id');
                $table->string('action', 64);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('remarks')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasColumn('inventory_locations', 'kind')) {
            Schema::table('inventory_locations', fn (Blueprint $table) => $table->string('kind')->nullable());
        }
        if (Schema::hasTable('pos_orders') && ! Schema::hasColumn('pos_orders', 'subtotal')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->decimal('subtotal', 10, 2)->default(0);
                $table->decimal('tax_amount', 10, 2)->default(0);
                $table->unsignedInteger('covers')->default(1);
                $table->date('business_date')->nullable();
                $table->unsignedBigInteger('opened_by')->nullable();
                $table->string('customer_name')->nullable();
                $table->string('customer_phone')->nullable();
            });
        }
        if (! Schema::hasTable('pos_order_items')) {
            Schema::create('pos_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('menu_item_id')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->boolean('price_tax_inclusive')->default(true);
                $table->decimal('line_total', 10, 2)->default(0);
                $table->boolean('kot_sent')->default(false);
                $table->string('status')->nullable();
                $table->boolean('inventory_deducted')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('pos_day_closings')) {
            Schema::create('pos_day_closings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurant_id');
                $table->date('closed_date');
                $table->timestamps();
            });
        }
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    /** In-house guest departing today, fully paid, checked out through the real endpoint. */
    private function checkOutGuest(Room $room, int $nights = 2): Booking
    {
        $booking = $this->makeBooking($room, $this->day(-$nights), $this->day(0), ['status' => 'checked_in']);
        BookingPaymentLedger::recordPayment($booking, [
            'amount' => (float) $booking->total_price,
            'method' => 'cash',
            'source' => 'deposit',
            'bill_total' => (float) $booking->total_price,
        ]);
        $this->completeCheckoutInspection($booking);
        $this->as($this->frontDesk);
        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();

        return $booking->fresh();
    }

    private function dirtyBlock(Room $room): RoomStatusBlock
    {
        return RoomStatusBlock::query()
            ->where('room_id', $room->id)
            ->where('is_active', true)
            ->whereIn('status', ['dirty', 'cleaning'])
            ->sole();
    }

    private function stockRoom(Room $room, string $name, float $qty, float $cost = 10): InventoryItem
    {
        $item = InventoryItem::query()->create(['name' => $name, 'sku' => strtoupper($name), 'cost_price' => $cost, 'conversion_factor' => 1]);
        $location = InventoryLocation::query()->firstOrCreate(
            ['room_id' => $room->id],
            ['name' => 'Room ' . $room->room_number, 'type' => 'satellite', 'kind' => 'room', 'is_active' => true],
        );
        DB::table('inventory_item_locations')->insert([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'quantity' => $qty,
        ]);

        return $item;
    }

    private function roomQty(Room $room, InventoryItem $item): float
    {
        $locationId = InventoryLocation::query()->where('room_id', $room->id)->value('id');

        return (float) DB::table('inventory_item_locations')
            ->where('inventory_location_id', $locationId)
            ->where('inventory_item_id', $item->id)
            ->value('quantity');
    }

    private function boardFor(Room $room): ?array
    {
        $res = $this->getJson('/api/housekeeping/dirty-rooms-board')->assertOk();

        return collect($res->json('blocks'))->firstWhere('room_id', $room->id);
    }

    // ── Checkout → board ────────────────────────────────────────────────────

    public function test_checkout_puts_room_on_dirty_board_unassigned_and_not_startable(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);

        $this->as($this->supervisor);
        $res = $this->getJson('/api/housekeeping/dirty-rooms-board')->assertOk();

        $res->assertJsonPath('stats.dirty', 1)
            ->assertJsonPath('stats.cleaning', 0)
            ->assertJsonPath('blocks.0.room_id', $room->id)
            ->assertJsonPath('blocks.0.status', 'dirty')
            ->assertJsonPath('blocks.0.assigned_to', null)
            ->assertJsonPath('blocks.0.can_start_cleaning', false);
        $this->assertNotNull($res->json('blocks.0.checkout_at'), 'Board shows when the guest departed.');
        $this->assertSame([['id' => $this->housekeeper->id, 'name' => 'Meera Housekeeper']], $res->json('staff'));
        $this->assertSame('dirty', $room->fresh()->status);
    }

    public function test_board_flags_same_day_arrival_as_priority(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $this->makeBooking($room, $this->day(0), $this->day(2), ['first_name' => 'Next', 'last_name' => 'Guest']);

        $this->as($this->supervisor);
        $row = $this->boardFor($room);

        $this->assertSame('Next Guest', $row['next_arrival_guest']);
        $this->assertContains($row['priority'], ['urgent', 'soon']);
    }

    public function test_board_requires_a_housekeeping_menu_permission(): void
    {
        $this->as($this->frontDesk);
        $this->getJson('/api/housekeeping/dirty-rooms-board')->assertForbidden();
    }

    // ── Assignment ─────────────────────────────────────────────────────────

    public function test_start_cleaning_requires_an_assignee(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Assign a housekeeping staff member before starting cleaning.');
        $this->assertSame('dirty', $block->fresh()->status);
    }

    public function test_assign_rejects_non_housekeeping_staff_and_users_without_assign_permission(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->frontDesk->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected user is not assignable housekeeping staff.');

        $this->as($this->userWith(self::HK_OPERATOR, 'Room Attendant'));
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->housekeeper->id])
            ->assertForbidden();

        $this->assertNull($block->fresh()->assigned_to);
    }

    public function test_assign_then_unassign_is_reflected_on_the_board(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->housekeeper->id])
            ->assertOk()
            ->assertJsonPath('assigned_user.name', 'Meera Housekeeper');
        $row = $this->boardFor($room);
        $this->assertSame('Meera Housekeeper', $row['assigned_staff_name']);
        $this->assertTrue((bool) $row['can_start_cleaning']);

        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => null])->assertOk();
        $this->assertFalse((bool) $this->boardFor($room)['can_start_cleaning']);
    }

    // ── Full turnover ───────────────────────────────────────────────────────

    public function test_full_turnover_consumes_room_stock_and_releases_room(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);
        $soap = $this->stockRoom($room, 'Soap', 4, 12);
        $arrival = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->as($this->frontDesk);
        $this->patchJson("/api/bookings/{$arrival->id}", ['status' => 'checked_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #101 is currently marked Dirty. Complete housekeeping service or assign another clean room before check-in.');

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->housekeeper->id])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")
            ->assertOk()
            ->assertJsonPath('status', 'cleaning');
        $this->assertSame('cleaning', $room->fresh()->status);
        $this->getJson('/api/housekeeping/dirty-rooms-board')
            ->assertJsonPath('stats.dirty', 0)
            ->assertJsonPath('stats.cleaning', 1);

        $this->as($this->frontDesk);
        $this->patchJson("/api/bookings/{$arrival->id}", ['status' => 'checked_in'])->assertStatus(422);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/job", [
            'remarks' => 'Linen changed',
            'checklist' => [['key' => 'bed', 'label' => 'Make bed', 'done' => true]],
            'amenities' => [['inventory_item_id' => $soap->id, 'qty' => 2]],
            'assets' => [['key' => 'tv', 'label' => 'TV', 'status' => 'ok']],
        ])->assertOk();

        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")
            ->assertOk()
            ->assertJsonPath('message', 'Cleaning complete. Room is available.');

        $block->refresh();
        $this->assertFalse($block->is_active);
        $this->assertSame('inspected', $block->status);
        $this->assertSame('available', $room->fresh()->status);
        $this->assertSame(2.0, $this->roomQty($room, $soap));
        $this->assertSame(2.0, (float) $soap->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $soap->id,
            'type' => 'out',
            'reference_type' => 'housekeeping',
            'total_cost' => 24,
        ]);
        $job = HousekeepingJob::query()->where('room_status_block_id', $block->id)->sole();
        $this->assertSame('completed', $job->status);
        $this->assertSame((int) $this->supervisor->id, (int) $job->finished_by);
        $this->assertNull($this->boardFor($room));

        $this->as($this->frontDesk);
        $this->patchJson("/api/bookings/{$arrival->id}", ['status' => 'checked_in'])->assertOk();
    }

    public function test_finish_rejects_consumption_above_room_stock_and_rolls_back(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);
        $soap = $this->stockRoom($room, 'Soap', 1);
        $block->update(['assigned_to' => $this->housekeeper->id]);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/job", [
            'amenities' => [['inventory_item_id' => $soap->id, 'qty' => 3]],
        ])->assertOk();

        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Quantity exceeds on-hand stock in the room for "Soap" (max 1).');

        $this->assertSame('cleaning', $block->fresh()->status);
        $this->assertTrue($block->fresh()->is_active);
        $this->assertSame(1.0, $this->roomQty($room, $soap));
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    public function test_finish_requires_cleaning_to_have_started(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Start cleaning before finishing.');
        $this->postJson("/api/housekeeping/blocks/{$block->id}/mark-cleaned")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Start cleaning before marking the room as cleaned.');
    }

    public function test_turnover_actions_require_dirty_or_cleaning_permission(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);
        $block->update(['assigned_to' => $this->housekeeper->id]);

        $this->as($this->userWith(['housekeeping-laundry'], 'Laundry'));
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertForbidden();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/job", [])->assertForbidden();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")->assertForbidden();
        $this->assertSame('dirty', $block->fresh()->status);
    }

    public function test_finished_block_cannot_be_acted_on_again(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);
        $block->update(['assigned_to' => $this->housekeeper->id]);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")->assertOk();

        foreach (['start-cleaning', 'finish', 'mark-cleaned', 'mark-inspected'] as $action) {
            $this->postJson("/api/housekeeping/blocks/{$block->id}/{$action}")
                ->assertStatus(422)
                ->assertJsonPath('message', 'This status block is no longer active.');
        }
    }

    public function test_asset_issue_sends_room_to_maintenance_and_cleaning_can_be_finished_after_repair(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);
        $block->update(['assigned_to' => $this->housekeeper->id]);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/job", [
            'assets' => [['key' => 'tv', 'label' => 'TV', 'status' => 'needs_repair', 'note' => 'No signal']],
        ])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")
            ->assertOk()
            ->assertJsonPath('message', 'Cleaning finished.');

        $this->assertSame('maintenance', $room->fresh()->status);
        $maintenance = RoomStatusBlock::query()->where('room_id', $room->id)->where('status', 'maintenance')->sole();
        $this->assertTrue($maintenance->is_active);
        $this->assertStringContainsString('TV: needs_repair', (string) $maintenance->note);

        $this->assertSame('cleaning', $block->fresh()->status, 'Turnover stays open until the asset is fixed.');
        $this->assertTrue($block->fresh()->is_active);

        $this->as($this->userWith(['reservation-maintenance-room'], 'Engineer'));
        $this->deleteJson("/api/room-status-blocks/{$maintenance->id}")->assertNoContent();
        $this->assertSame('cleaning', $room->fresh()->status);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/job", [
            'assets' => [['key' => 'tv', 'label' => 'TV', 'status' => 'ok']],
        ])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")
            ->assertOk()
            ->assertJsonPath('message', 'Cleaning complete. Room is available.');
        $this->assertSame('available', $room->fresh()->status);
        $this->assertSame(0, RoomStatusBlock::query()->where('room_id', $room->id)->where('is_active', true)->count());
    }

    public function test_turnover_finished_via_mark_cleaned_releases_room(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);
        $block->update(['assigned_to' => $this->housekeeper->id]);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/mark-cleaned")
            ->assertOk()
            ->assertJsonPath('message', 'Room marked as cleaned.');

        $this->assertFalse($block->fresh()->is_active);
        $this->assertSame('available', $room->fresh()->status);
        $this->assertNull($this->boardFor($room));
    }

    // ── Manual blocks from the Room Chart ──────────────────────────────────

    public function test_manual_dirty_block_from_room_chart_blocks_check_in_until_removed(): void
    {
        $room = $this->makeRoom('101');
        $arrival = $this->makeBooking($room, $this->day(1), $this->day(3));
        $this->as($this->supervisor);

        $block = $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id,
            'status' => 'dirty',
            'start_date' => $this->day(0),
            'end_date' => $this->day(1),
        ])->assertCreated()->json();

        $this->assertSame('dirty', $room->fresh()->status);
        $this->assertSame('dirty', $this->boardFor($room)['status']);

        $this->deleteJson("/api/room-status-blocks/{$block['id']}")->assertNoContent();
        $this->assertSame('available', $room->fresh()->status);
        $this->assertNull($this->boardFor($room));
        $this->assertSame('confirmed', $arrival->fresh()->status);
    }

    public function test_manual_dirty_block_rejected_over_an_in_house_stay(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-1), $this->day(2), ['status' => 'checked_in']);
        $this->as($this->supervisor);

        $this->postJson('/api/room-status-blocks', [
            'room_id' => $room->id,
            'status' => 'dirty',
            'start_date' => $this->day(0),
            'end_date' => $this->day(1),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot mark Room #101 as dirty because it already has a reservation in this date range.');
        $this->assertSame('occupied', $room->fresh()->status);
    }

    // ── Cleaning release linkage ───────────────────────────────────────────

    public function test_finish_marks_linked_cleaning_release_ready(): void
    {
        $room = $this->makeRoom('101');
        $this->checkOutGuest($room);
        $block = $this->dirtyBlock($room);
        $block->update(['assigned_to' => $this->housekeeper->id]);
        $release = RoomCleaningRelease::query()->create([
            'room_id' => $room->id,
            'room_status_block_id' => $block->id,
            'release_date' => $this->day(0),
            'window_start' => $this->day(0) . ' 09:00:00',
            'window_end' => $this->day(0) . ' 18:00:00',
            'status' => RoomCleaningRelease::STATUS_AVAILABLE,
            'is_active' => true,
        ]);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertOk();
        $this->assertSame(RoomCleaningRelease::STATUS_IN_PROGRESS, $release->fresh()->status);

        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")->assertOk();
        $this->assertSame(RoomCleaningRelease::STATUS_READY, $release->fresh()->status);
        $this->assertFalse((bool) $release->fresh()->is_active);
    }

    // ── Handoffs that must not free an occupied room ───────────────────────

    public function test_pre_checkout_inspection_ready_card_does_not_free_an_in_house_room(): void
    {
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $inspector = $this->userWith(['housekeeping-checkout-inspection'], 'Inspector');
        $pending = RoomStatusBlock::query()->create([
            'room_id' => $room->id,
            'status' => 'pending_inspection',
            'start_date' => $this->day(0),
            'end_date' => $this->day(1),
            'is_active' => true,
            'assigned_to' => $inspector->id,
        ]);
        $this->as($inspector);
        $this->postJson("/api/housekeeping/blocks/{$pending->id}/checkout-inspection/clear")->assertOk();

        $handoff = RoomStatusBlock::query()->where('room_id', $room->id)->where('is_active', true)->sole();
        $this->assertSame('inspected', $handoff->status);

        $this->as($this->supervisor);
        $this->assertNull($this->boardFor($room), 'Handoff belongs to the Checkout Inspection board while the guest is in-house.');
        $this->postJson("/api/housekeeping/blocks/{$handoff->id}/mark-inspected")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room #101 still has a checked-in guest. It is handed to housekeeping at checkout.');

        $this->assertSame('inspected', $room->fresh()->status);
        $this->assertTrue($handoff->fresh()->is_active);
    }

    public function test_uncleaned_room_stays_dirty_after_departure_day_until_housekeeping_finishes(): void
    {
        $room = $this->makeRoom('101');
        $stale = $this->makeBlock($room, 'dirty', $this->day(-1), $this->day(0), ['note' => 'Auto: checkout']);
        $room->update(['status' => 'dirty']);
        $arrival = $this->makeBooking($room, $this->day(0), $this->day(2));

        $this->as($this->frontDesk);
        $this->patchJson("/api/bookings/{$arrival->id}", ['status' => 'checked_in'])->assertStatus(422);
        $this->assertSame($this->day(1), $stale->fresh()->end_date->toDateString(), 'Block now covers today on the chart.');

        $chart = $this->getJson('/api/bookings/chart?start=' . $this->day(0) . '&end=' . $this->day(1))->assertOk()->json();
        $chartRoom = collect($chart['rooms'] ?? $chart)->firstWhere('id', $room->id);
        $this->assertSame('dirty', collect($chartRoom['status_blocks'] ?? $chartRoom['statusBlocks'] ?? [])->first()['status'] ?? null);

        $this->as($this->supervisor);
        $this->assertSame('dirty', $this->boardFor($room)['status']);
        $this->postJson("/api/housekeeping/blocks/{$stale->id}/assign-staff", ['assigned_to' => $this->housekeeper->id])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$stale->id}/start-cleaning")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$stale->id}/finish")->assertOk();

        $this->as($this->frontDesk);
        $this->patchJson("/api/bookings/{$arrival->id}", ['status' => 'checked_in'])->assertOk();
    }

    public function test_stale_turnover_on_a_room_already_checked_into_is_closed(): void
    {
        $room = $this->makeRoom('101');
        $stale = $this->makeBlock($room, 'dirty', $this->day(-2), $this->day(-1), ['note' => 'Auto: checkout']);
        $this->makeBooking($room, $this->day(-1), $this->day(2), ['status' => 'checked_in']);

        $this->as($this->supervisor);
        $this->assertNull(
            $this->boardFor($room),
            'An in-house room must not show as a dirty turnover task (Start cleaning would flip it to "cleaning").',
        );
        $this->assertFalse($stale->fresh()->is_active);
        $this->assertSame($this->day(-1), $stale->fresh()->end_date->toDateString());
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_finish_posts_unpriced_minibar_at_issue_unit_cost_while_guest_is_in_the_room(): void
    {
        $room = $this->makeRoom('301');
        $booking = $this->makeBooking($room, $this->day(-1), $this->day(2), ['status' => 'checked_in']);
        $block = $this->makeBlock($room, 'dirty', $this->day(0), $this->day(1));
        $item = InventoryItem::query()->create([
            'name' => '7UP',
            'sku' => '7UP',
            'cost_price' => 480,
            'conversion_factor' => 24,
            'is_minibar' => true,
        ]);
        $location = InventoryLocation::query()->firstOrCreate(
            ['room_id' => $room->id],
            ['name' => 'Room 301', 'type' => 'satellite', 'kind' => 'room', 'is_active' => true],
        );
        DB::table('inventory_item_locations')->insert([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'quantity' => 2,
        ]);
        $menu = MenuItem::query()->create(['name' => '7UP (Minibar)', 'price' => 0, 'inventory_item_id' => $item->id]);
        RestaurantMaster::query()->create(['name' => 'OTTAAL']);

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->housekeeper->id])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/job", [
            'minibar' => [['inventory_item_id' => $item->id, 'menu_item_id' => $menu->id, 'qty' => 2]],
        ])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")->assertOk();

        $this->assertEqualsWithDelta(40.0, (float) $booking->fresh()->extra_charges, 0.01);
        $order = PosOrder::query()->where('booking_id', $booking->id)->sole();
        $this->assertEqualsWithDelta(40.0, (float) $order->total_amount, 0.01);
        $this->assertSame('room_charge', (string) $order->payments()->value('method'));
        $line = PosOrderItem::query()->where('order_id', $order->id)->sole();
        $this->assertEqualsWithDelta(20.0, (float) $line->unit_price, 0.01);
        $this->assertSame(0.0, $this->roomQty($room, $item));
    }

    public function test_finish_after_split_stay_move_does_not_charge_minibar_again(): void
    {
        $left = $this->makeRoom('101');
        $next = $this->makeRoom('102');
        $booking = $this->makeBooking($left, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $item = InventoryItem::query()->create([
            'name' => 'Pepsi',
            'sku' => 'PEPSI',
            'cost_price' => 240,
            'conversion_factor' => 12,
            'is_minibar' => true,
        ]);
        $location = InventoryLocation::query()->firstOrCreate(
            ['room_id' => $left->id],
            ['name' => 'Room 101', 'type' => 'satellite', 'kind' => 'room', 'is_active' => true],
        );
        DB::table('inventory_item_locations')->insert([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'quantity' => 2,
        ]);
        $menu = MenuItem::query()->create(['name' => 'Pepsi (Minibar)', 'price' => 40, 'inventory_item_id' => $item->id]);
        RestaurantMaster::query()->create(['name' => 'OTTAAL']);

        $this->as($this->frontDesk);
        $this->postJson("/api/bookings/{$booking->id}/split-stay", [
            'new_room_id' => $next->id,
            'new_check_out' => $this->day(2),
        ])->assertOk();
        BookingSplitStayRoomMove::sync((int) $booking->id);

        $block = $this->dirtyBlock($left);
        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->housekeeper->id])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/start-cleaning")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/job", [
            'minibar' => [['inventory_item_id' => $item->id, 'menu_item_id' => $menu->id, 'qty' => 1]],
        ])->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/finish")->assertOk();

        $this->assertEqualsWithDelta(0.0, (float) $booking->fresh()->extra_charges, 0.01);
        $this->assertSame(0, PosOrder::query()->count());
        $this->assertSame(1.0, $this->roomQty($left, $item));
    }
}
