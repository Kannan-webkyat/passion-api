<?php

namespace Tests\Feature\RoomChart;

use App\Models\BookingExtraCharge;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\MenuItem;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\Room;
use App\Models\RoomStatusBlock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckoutInspectionMinibarPricingTest extends RoomChartTestCase
{
    private Room $room;

    private RoomStatusBlock $block;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('inventory_transactions')) {
            Schema::create('inventory_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('inventory_item_id');
                $table->unsignedBigInteger('inventory_location_id')->nullable();
                $table->string('type');
                $table->decimal('quantity', 14, 4);
                $table->decimal('unit_cost', 12, 4)->nullable();
                $table->decimal('total_cost', 12, 2)->nullable();
                $table->string('reason')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('reference_id')->nullable();
                $table->string('reference_type')->nullable();
                $table->timestamps();
            });
        }

        $user = $this->actingWith(['housekeeping-checkout-inspection']);
        $this->room = $this->makeRoom('207');
        $this->makeBooking($this->room, $this->day(-2), $this->day(1), ['status' => 'checked_in']);
        $this->block = RoomStatusBlock::query()->create([
            'room_id' => $this->room->id,
            'status' => 'pending_inspection',
            'start_date' => $this->day(0),
            'end_date' => $this->day(1),
            'is_active' => true,
            'assigned_to' => $user->id,
        ]);
    }

    private function stockMinibarItem(string $name, float $packCost, float $packSize): InventoryItem
    {
        $item = InventoryItem::query()->create([
            'name' => $name,
            'sku' => strtoupper($name),
            'cost_price' => $packCost,
            'conversion_factor' => $packSize,
            'is_minibar' => true,
        ]);
        $location = InventoryLocation::query()->firstOrCreate(
            ['room_id' => $this->room->id],
            ['name' => 'Room 207', 'type' => 'room', 'is_active' => true],
        );
        DB::table('inventory_item_locations')->insert([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'quantity' => 2,
        ]);

        return $item;
    }

    private function previewMinibar(InventoryItem $item, int $qty)
    {
        return $this->postJson("/api/housekeeping/blocks/{$this->block->id}/checkout-inspection/validate", [
            'minibar' => [['inventory_item_id' => $item->id, 'qty' => $qty]],
        ])->assertOk();
    }

    public function test_minibar_is_charged_at_menu_pricing_price_of_the_minibar_outlet(): void
    {
        $sevenUp = $this->stockMinibarItem('7UP', 480, 24);
        $bar = RestaurantMaster::query()->create(['name' => 'BAR']);
        $ottaal = RestaurantMaster::query()->create(['name' => 'OTTAAL']);
        $menu = MenuItem::query()->create(['name' => '7UP (Minibar)', 'price' => 0, 'inventory_item_id' => $sevenUp->id]);
        RestaurantMenuItem::query()->create(['menu_item_id' => $menu->id, 'restaurant_master_id' => $bar->id, 'price' => 70]);
        RestaurantMenuItem::query()->create(['menu_item_id' => $menu->id, 'restaurant_master_id' => $ottaal->id, 'price' => 60]);

        $this->previewMinibar($sevenUp, 2)
            ->assertJsonPath('preview.minibar_lines.0.unit_amount', 60)
            ->assertJsonPath('preview.minibar_total', 120);
    }

    public function test_minibar_item_without_outlets_is_charged_at_its_menu_item_price(): void
    {
        $sevenUp = $this->stockMinibarItem('7UP', 480, 24);
        RestaurantMaster::query()->create(['name' => 'OTTAAL']);
        MenuItem::query()->create(['name' => '7UP (Minibar)', 'price' => 55, 'inventory_item_id' => $sevenUp->id]);

        $this->previewMinibar($sevenUp, 2)
            ->assertJsonPath('preview.minibar_lines.0.unit_amount', 55)
            ->assertJsonPath('preview.minibar_total', 110);
    }

    public function test_minibar_falls_back_to_inventory_unit_cost_without_a_menu_price(): void
    {
        $sevenUp = $this->stockMinibarItem('7UP', 480, 24);
        MenuItem::query()->create(['name' => '7UP (Minibar)', 'price' => 0, 'inventory_item_id' => $sevenUp->id]);

        $this->previewMinibar($sevenUp, 2)
            ->assertJsonPath('preview.minibar_lines.0.unit_amount', 20)
            ->assertJsonPath('preview.minibar_total', 40);
    }

    public function test_apply_charges_unpriced_minibar_at_issue_unit_cost(): void
    {
        $sevenUp = $this->stockMinibarItem('7UP', 480, 24);
        MenuItem::query()->create(['name' => '7UP (Minibar)', 'price' => 0, 'inventory_item_id' => $sevenUp->id]);

        $this->postJson("/api/housekeeping/blocks/{$this->block->id}/checkout-inspection/apply", [
            'minibar' => [['inventory_item_id' => $sevenUp->id, 'qty' => 2]],
        ])->assertOk()
            ->assertJsonPath('added_amount', 40);

        $charge = BookingExtraCharge::query()->where('kind', 'minibar')->sole();
        $this->assertEqualsWithDelta(20.0, (float) $charge->unit_amount, 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $charge->total_amount, 0.01);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $sevenUp->id,
            'reference_type' => 'checkout_inspection',
            'total_cost' => 40,
        ]);
    }
}
