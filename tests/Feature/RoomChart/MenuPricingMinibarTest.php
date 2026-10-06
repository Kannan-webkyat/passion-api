<?php

namespace Tests\Feature\RoomChart;

use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\RestaurantMaster;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MenuPricingMinibarTest extends RoomChartTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['menu-pricing']);

        if (! Schema::hasTable('menu_item_variants')) {
            Schema::create('menu_item_variants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('menu_item_id');
                $table->string('size_label')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('restaurant_menu_item_variants')) {
            Schema::create('restaurant_menu_item_variants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurant_menu_item_id');
                $table->unsignedBigInteger('menu_item_variant_id');
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('recipes')) {
            Schema::create('recipes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('menu_item_id')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('recipe_ingredients')) {
            Schema::create('recipe_ingredients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipe_id');
                $table->unsignedBigInteger('inventory_item_id')->nullable();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('restaurant_menu_items') && ! Schema::hasColumn('restaurant_menu_items', 'fixed_ept')) {
            Schema::table('restaurant_menu_items', function (Blueprint $table) {
                $table->integer('fixed_ept')->nullable();
            });
        }
    }

    public function test_minibar_price_can_be_saved_without_outlets(): void
    {
        $item = InventoryItem::query()->create([
            'name' => '7UP',
            'sku' => 'HK-MIN-009',
            'is_minibar' => true,
        ]);
        $menu = MenuItem::query()->create([
            'name' => '7UP',
            'price' => 0,
            'inventory_item_id' => $item->id,
        ]);

        $this->putJson("/api/menu-pricing/{$menu->id}", [
            'restaurant_links' => [],
            'price' => 60,
        ])->assertOk();

        $this->assertSame('60.00', $menu->fresh()->price);
    }

    public function test_normal_item_cannot_be_priced_without_an_outlet(): void
    {
        $item = InventoryItem::query()->create([
            'name' => 'Tea',
            'sku' => 'TEA',
            'is_minibar' => false,
        ]);
        $menu = MenuItem::query()->create([
            'name' => 'Tea',
            'price' => 10,
            'inventory_item_id' => $item->id,
        ]);

        $this->putJson("/api/menu-pricing/{$menu->id}", [
            'restaurant_links' => [],
            'price' => 80,
        ])->assertStatus(422);

        $this->assertSame('10.00', $menu->fresh()->price);
    }

    public function test_price_on_a_normal_item_is_left_unchanged(): void
    {
        $item = InventoryItem::query()->create([
            'name' => 'Tea',
            'sku' => 'TEA-2',
            'is_minibar' => false,
        ]);
        $menu = MenuItem::query()->create([
            'name' => 'Tea',
            'price' => 10,
            'inventory_item_id' => $item->id,
        ]);
        $bar = RestaurantMaster::query()->create(['name' => 'BAR']);

        $this->putJson("/api/menu-pricing/{$menu->id}", [
            'restaurant_links' => [[
                'restaurant_master_id' => $bar->id,
                'price' => 40,
                'is_active' => true,
            ]],
            'price' => 99,
        ])->assertOk();

        $this->assertSame('10.00', $menu->fresh()->price);
    }

    public function test_pricing_index_marks_minibar_items(): void
    {
        $item = InventoryItem::query()->create([
            'name' => '7UP',
            'sku' => 'HK-MIN-010',
            'is_minibar' => true,
        ]);
        $menu = MenuItem::query()->create([
            'name' => '7UP',
            'price' => 55,
            'inventory_item_id' => $item->id,
        ]);

        $this->getJson('/api/menu-pricing')
            ->assertOk()
            ->assertJsonPath('0.id', $menu->id)
            ->assertJsonPath('0.is_minibar', true)
            ->assertJsonPath('0.base_price', 55);
    }
}
