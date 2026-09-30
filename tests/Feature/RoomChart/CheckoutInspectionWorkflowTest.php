<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingExtraCharge;
use App\Models\Department;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use App\Models\User;
use App\Support\BookingPaymentLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Reception requests inspection → supervisor assigns → inspector clears or applies charges → reception
 * settles the folio and checks out → room lands on the Dirty Rooms board.
 */
class CheckoutInspectionWorkflowTest extends RoomChartTestCase
{
    private const HK_CHECKOUT = 'housekeeping-checkout-inspection';

    private const HK_CHECKOUT_ASSIGN = 'housekeeping-checkout-inspection-assign';

    private User $frontDesk;

    private User $supervisor;

    private User $inspector;

    private User $otherInspector;

    private Room $room;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateInspectionTables();

        $this->frontDesk = $this->userWith(['reservation-edit', 'reservation-view'], 'Front Desk');
        $this->supervisor = $this->userWith(
            [self::HK_CHECKOUT, self::HK_CHECKOUT_ASSIGN, 'housekeeping-dirty-rooms'],
            'HK Supervisor',
        );
        $this->inspector = $this->userWith([self::HK_CHECKOUT], 'Meera Inspector');
        $this->otherInspector = $this->userWith([self::HK_CHECKOUT], 'Ravi Inspector');

        $hk = Department::query()->create(['name' => 'Housekeeping', 'code' => 'HKP', 'is_active' => true, 'is_housekeeping' => true]);
        $hk->users()->attach([$this->inspector->id, $this->otherInspector->id]);
        $fo = Department::query()->create(['name' => 'Front Office', 'code' => 'FO', 'is_active' => true, 'is_housekeeping' => false]);
        $fo->users()->attach($this->frontDesk->id);

        $this->setting('checkout_inspection_penalties', json_encode([
            'tv_damage' => ['amount' => 1500, 'label' => 'TV screen damage'],
        ]));

        $this->room = $this->makeRoom('207');
        $this->booking = $this->makeBooking($this->room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        BookingPaymentLedger::recordPayment($this->booking, [
            'amount' => (float) $this->booking->total_price,
            'method' => 'cash',
            'source' => 'deposit',
            'bill_total' => (float) $this->booking->total_price,
        ]);
    }

    private function migrateInspectionTables(): void
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
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function requestInspection(): RoomStatusBlock
    {
        $this->as($this->frontDesk);
        $id = $this->postJson("/api/bookings/{$this->booking->id}/request-inspection")->assertOk()->json('block.id');

        return RoomStatusBlock::query()->findOrFail($id);
    }

    private function assignedPendingBlock(?User $to = null): RoomStatusBlock
    {
        $block = $this->requestInspection();
        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", [
            'assigned_to' => ($to ?? $this->inspector)->id,
        ])->assertOk();

        return $block->fresh();
    }

    private function stockMinibar(string $name, float $qty, float $cost): InventoryItem
    {
        $item = InventoryItem::query()->create(['name' => $name, 'sku' => strtoupper($name), 'cost_price' => $cost, 'conversion_factor' => 1, 'is_minibar' => true]);
        $location = InventoryLocation::query()->firstOrCreate(
            ['room_id' => $this->room->id],
            ['name' => 'Room ' . $this->room->room_number, 'type' => 'room', 'is_active' => true],
        );
        DB::table('inventory_item_locations')->insert([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'quantity' => $qty,
        ]);

        return $item;
    }

    private function roomQty(InventoryItem $item): float
    {
        $locationId = InventoryLocation::query()->where('room_id', $this->room->id)->value('id');

        return (float) DB::table('inventory_item_locations')
            ->where('inventory_location_id', $locationId)
            ->where('inventory_item_id', $item->id)
            ->value('quantity');
    }

    /** @return array<string, mixed> */
    private function chargesPayload(InventoryItem $minibar): array
    {
        return [
            'remarks' => 'TV cracked, two sodas used',
            'minibar' => [['inventory_item_id' => $minibar->id, 'qty' => 2]],
            'assets' => [[
                'key' => 'television',
                'label' => 'Television',
                'status' => 'damaged',
                'penalty_key' => 'tv_damage',
                'qty' => 1,
            ]],
        ];
    }

    private function activeBlock(): ?RoomStatusBlock
    {
        return RoomStatusBlock::query()->where('room_id', $this->room->id)->where('is_active', true)->first();
    }

    /** @return list<int> */
    private function scopeBlockIds(string $scope): array
    {
        $this->as($this->supervisor);

        return collect($this->getJson("/api/housekeeping?checkout_scope={$scope}")->assertOk()->json('blocks'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function checkOut(): \Illuminate\Testing\TestResponse
    {
        $this->as($this->frontDesk);

        return $this->patchJson("/api/bookings/{$this->booking->id}", ['status' => 'checked_out']);
    }

    // ── Request inspection (reception) ──────────────────────────────────────

    public function test_request_inspection_moves_room_to_pending_inspection_for_this_booking(): void
    {
        $block = $this->requestInspection();

        $this->assertSame('pending_inspection', $block->status);
        $this->assertTrue((bool) $block->is_active);
        $this->assertNull($block->assigned_to);
        $this->assertSame($this->booking->id, (int) $block->inspection_snapshot['booking_id']);
        $this->assertSame('pending_inspection', $this->room->fresh()->status);
        $this->assertSame('checked_in', $this->booking->fresh()->status);
    }

    public function test_request_inspection_requires_reservation_edit(): void
    {
        $this->as($this->supervisor);
        $this->postJson("/api/bookings/{$this->booking->id}/request-inspection")->assertForbidden();
        $this->assertNull($this->activeBlock());
    }

    public function test_request_inspection_rejected_before_the_checkout_day(): void
    {
        $room = $this->makeRoom('208');
        $staying = $this->makeBooking($room, $this->day(-1), $this->day(1), ['status' => 'checked_in']);

        $this->as($this->frontDesk);
        $this->postJson("/api/bookings/{$staying->id}/request-inspection")
            ->assertStatus(422)
            ->assertJsonPath('checkout_date', $this->day(1));
    }

    public function test_request_inspection_rejected_for_a_guest_who_is_not_checked_in(): void
    {
        $room = $this->makeRoom('209');
        $arrival = $this->makeBooking($room, $this->day(0), $this->day(1));

        $this->as($this->frontDesk);
        $this->postJson("/api/bookings/{$arrival->id}/request-inspection")->assertStatus(422);
    }

    public function test_requesting_again_keeps_a_single_pending_task(): void
    {
        $first = $this->requestInspection();
        $second = $this->requestInspection();

        $this->assertFalse((bool) $first->fresh()->is_active);
        $this->assertSame([$second->id], $this->scopeBlockIds('pending'));
    }

    // ── Board + assignment ──────────────────────────────────────────────────

    public function test_pending_board_lists_the_task_with_assignable_housekeeping_staff(): void
    {
        $block = $this->requestInspection();

        $this->as($this->supervisor);
        $res = $this->getJson('/api/housekeeping?checkout_scope=pending')->assertOk();

        $this->assertSame([$block->id], collect($res->json('blocks'))->pluck('id')->all());
        $this->assertNull($res->json('blocks.0.assigned_staff_name'));
        $this->assertEqualsCanonicalizing(
            [$this->inspector->id, $this->otherInspector->id],
            collect($res->json('staff'))->pluck('id')->all(),
        );
    }

    public function test_checkout_inspection_board_requires_the_checkout_inspection_permission(): void
    {
        $this->requestInspection();
        $this->as($this->userWith(['housekeeping-dirty-rooms'], 'Dirty Only'));

        $this->getJson('/api/housekeeping?checkout_scope=pending')->assertForbidden();
    }

    public function test_only_checkout_inspection_assigners_can_assign(): void
    {
        $block = $this->requestInspection();

        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->inspector->id])
            ->assertForbidden();
        $this->assertNull($block->fresh()->assigned_to);
    }

    public function test_assignee_must_be_housekeeping_staff(): void
    {
        $block = $this->requestInspection();

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->frontDesk->id])
            ->assertStatus(422);

        $this->postJson("/api/housekeeping/blocks/{$block->id}/assign-staff", ['assigned_to' => $this->inspector->id])
            ->assertOk()
            ->assertJsonPath('assigned_user.name', 'Meera Inspector');
    }

    public function test_inspection_cannot_start_before_it_is_assigned(): void
    {
        $block = $this->requestInspection();

        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")->assertStatus(422);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/validate", [])->assertStatus(422);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/apply", [])->assertStatus(422);
        $this->assertSame('pending_inspection', $block->fresh()->status);
        $this->assertTrue((bool) $block->fresh()->is_active);
    }

    public function test_another_inspector_cannot_perform_someone_elses_inspection(): void
    {
        $block = $this->assignedPendingBlock();

        $this->as($this->otherInspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")->assertForbidden();
        $this->assertTrue((bool) $block->fresh()->is_active);
    }

    public function test_supervisor_with_assign_permission_can_perform_any_inspection(): void
    {
        $block = $this->assignedPendingBlock();

        $this->as($this->supervisor);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")->assertOk();
    }

    // ── Inspection context ──────────────────────────────────────────────────

    public function test_context_returns_in_house_guest_inspector_and_penalty_map(): void
    {
        $this->assignedPendingBlock();

        $this->as($this->inspector);
        $this->getJson("/api/housekeeping/rooms/{$this->room->id}/checkout-inspection-context")
            ->assertOk()
            ->assertJsonPath('booking.id', $this->booking->id)
            ->assertJsonPath('inspector.name', 'Meera Inspector')
            ->assertJsonPath('penalties.tv_damage.amount', 1500);
    }

    // ── Clear (no charges) ──────────────────────────────────────────────────

    public function test_clear_hands_room_to_inspected_with_a_cleared_snapshot(): void
    {
        $block = $this->assignedPendingBlock();

        $this->as($this->inspector);
        $newId = $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")
            ->assertOk()
            ->json('block.id');

        $new = RoomStatusBlock::query()->findOrFail($newId);
        $this->assertFalse((bool) $block->fresh()->is_active);
        $this->assertSame('inspected', $new->status);
        $this->assertTrue((bool) $new->is_active);
        $this->assertTrue($new->inspection_snapshot['cleared']);
        $this->assertSame($this->booking->id, (int) $new->inspection_snapshot['booking_id']);
        $this->assertSame($this->inspector->id, (int) $new->inspection_snapshot['inspector_user_id']);
        $this->assertSame('inspected', $this->room->fresh()->status);
        $this->assertSame(0.0, (float) $this->booking->fresh()->extra_charges);

        $this->assertSame([], $this->scopeBlockIds('pending'));
        $this->assertSame([$newId], $this->scopeBlockIds('inspected'));
    }

    public function test_clear_twice_is_rejected(): void
    {
        $block = $this->assignedPendingBlock();

        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")->assertOk();
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")->assertStatus(422);
        $this->assertSame(1, RoomStatusBlock::query()->where('room_id', $this->room->id)->where('status', 'inspected')->count());
    }

    // ── Validate (preview) ──────────────────────────────────────────────────

    public function test_validate_previews_minibar_and_asset_charges_without_writing(): void
    {
        $soda = $this->stockMinibar('Soda', 3, 20);
        $block = $this->assignedPendingBlock();

        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/validate", $this->chargesPayload($soda))
            ->assertOk()
            ->assertJsonPath('booking_id', $this->booking->id)
            ->assertJsonPath('preview.minibar_total', 40)
            ->assertJsonPath('preview.asset_total', 1500)
            ->assertJsonPath('preview.grand_total', 1540)
            ->assertJsonPath('preview.asset_lines.0.label', 'TV screen damage');

        $this->assertSame(3.0, $this->roomQty($soda));
        $this->assertSame(0, BookingExtraCharge::query()->count());
        $this->assertTrue((bool) $block->fresh()->is_active);
    }

    public function test_validate_rejects_minibar_above_room_stock(): void
    {
        $soda = $this->stockMinibar('Soda', 1, 20);
        $block = $this->assignedPendingBlock();

        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/validate", [
            'minibar' => [['inventory_item_id' => $soda->id, 'qty' => 2]],
        ])->assertStatus(422);
    }

    public function test_validate_rejects_an_asset_key_not_allowed_for_the_room(): void
    {
        $this->stockMinibar('Soda', 1, 20);
        $block = $this->assignedPendingBlock();

        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/validate", [
            'assets' => [['key' => 'jacuzzi', 'label' => 'Jacuzzi', 'status' => 'damaged']],
        ])->assertStatus(422);
    }

    // ── Apply (charges) ─────────────────────────────────────────────────────

    public function test_apply_posts_charges_to_folio_deducts_minibar_and_marks_inspected(): void
    {
        $soda = $this->stockMinibar('Soda', 3, 20);
        $block = $this->assignedPendingBlock();

        $this->as($this->inspector);
        $res = $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/apply", $this->chargesPayload($soda))
            ->assertOk()
            ->assertJsonPath('added_amount', 1540)
            ->assertJsonPath('extra_charges', 1540);

        $booking = $this->booking->fresh();
        $this->assertSame(1540.0, (float) $booking->extra_charges);
        $this->assertStringContainsString('[Inspection: TV cracked, two sodas used', (string) $booking->notes);

        $lines = BookingExtraCharge::query()->where('booking_id', $booking->id)->orderBy('id')->get();
        $this->assertSame(['inspection', 'inspection'], $lines->pluck('source')->all());
        $this->assertSame(['minibar', 'asset_penalty'], $lines->pluck('kind')->all());
        $this->assertEquals([40, 1500], $lines->pluck('total_amount')->map(fn ($v) => (float) $v)->all());

        $this->assertSame(1.0, $this->roomQty($soda));
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $soda->id,
            'type' => 'out',
            'quantity' => 2,
            'reference_type' => 'checkout_inspection',
        ]);

        $new = RoomStatusBlock::query()->findOrFail($res->json('block.id'));
        $this->assertFalse((bool) $block->fresh()->is_active);
        $this->assertSame('inspected', $new->status);
        $this->assertFalse($new->inspection_snapshot['cleared']);
        $this->assertSame('Soda', $new->inspection_snapshot['minibar'][0]['name']);
        $this->assertSame('inspected', $this->room->fresh()->status);
    }

    public function test_reception_sees_itemised_inspection_charges_and_inspector(): void
    {
        $soda = $this->stockMinibar('Soda', 3, 20);
        $block = $this->assignedPendingBlock();
        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/apply", $this->chargesPayload($soda))->assertOk();

        $this->as($this->frontDesk);
        $res = $this->getJson("/api/bookings/{$this->booking->id}/inspection-charges")->assertOk();

        $this->assertCount(2, $res->json('lines'));
        $this->assertSame('Meera Inspector', $res->json('inspector.name'));
    }

    public function test_apply_rejected_above_room_stock_leaves_everything_untouched(): void
    {
        $soda = $this->stockMinibar('Soda', 1, 20);
        $block = $this->assignedPendingBlock();

        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/apply", $this->chargesPayload($soda))
            ->assertStatus(422);

        $this->assertSame(1.0, $this->roomQty($soda));
        $this->assertSame(0, BookingExtraCharge::query()->count());
        $this->assertSame(0.0, (float) $this->booking->fresh()->extra_charges);
        $this->assertTrue((bool) $block->fresh()->is_active);
    }

    // ── Checkout after inspection ───────────────────────────────────────────

    public function test_checkout_waits_for_inspection_charges_to_be_paid(): void
    {
        $soda = $this->stockMinibar('Soda', 3, 20);
        $block = $this->assignedPendingBlock();
        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/apply", $this->chargesPayload($soda))->assertOk();

        $this->assertSame('paid', $this->booking->fresh()->payment_status);
        $this->checkOut()->assertStatus(422);
        $this->patchJson("/api/bookings/{$this->booking->id}", ['status' => 'checked_out', 'payment_status' => 'paid'])
            ->assertStatus(422);
        $this->postJson("/api/bookings/{$this->booking->id}/preview-checkout")
            ->assertOk()
            ->assertJsonPath('can_checkout', false)
            ->assertJsonPath('balance_due', 1540);
        $this->assertSame('checked_in', $this->booking->fresh()->status);

        BookingPaymentLedger::recordPayment($this->booking->fresh(), [
            'amount' => 1540,
            'method' => 'cash',
            'source' => 'checkout',
            'bill_total' => (float) $this->booking->total_price + 1540,
        ]);
        $this->checkOut()->assertOk();

        $this->assertSame('checked_out', $this->booking->fresh()->status);
    }

    public function test_checkout_closes_the_handoff_moves_it_to_history_and_creates_the_dirty_task(): void
    {
        $block = $this->assignedPendingBlock();
        $this->as($this->inspector);
        $inspectedId = $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")->json('block.id');

        $this->checkOut()->assertOk();

        $this->assertFalse((bool) RoomStatusBlock::query()->findOrFail($inspectedId)->is_active);
        $dirty = $this->activeBlock();
        $this->assertSame('dirty', $dirty->status);
        $this->assertSame($this->day(0), substr((string) $dirty->start_date, 0, 10));
        $this->assertSame('dirty', $this->room->fresh()->status);

        $this->assertSame([], $this->scopeBlockIds('inspected'));
        $this->assertContains($inspectedId, $this->scopeBlockIds('history'));

        $this->as($this->supervisor);
        $board = collect($this->getJson('/api/housekeeping/dirty-rooms-board')->assertOk()->json('blocks'));
        $this->assertSame('dirty', $board->firstWhere('room_id', $this->room->id)['status'] ?? null);
    }

    public function test_inspected_handoff_stays_off_the_dirty_board_while_the_guest_is_in_house(): void
    {
        $block = $this->assignedPendingBlock();
        $this->as($this->inspector);
        $this->postJson("/api/housekeeping/blocks/{$block->id}/checkout-inspection/clear")->assertOk();

        $this->as($this->supervisor);
        $board = collect($this->getJson('/api/housekeeping/dirty-rooms-board')->assertOk()->json('blocks'));
        $this->assertNull($board->firstWhere('room_id', $this->room->id));
    }
}
