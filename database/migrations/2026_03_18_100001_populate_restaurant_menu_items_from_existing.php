<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Populate restaurant_menu_items from existing menu_items.
     * Creates one link per restaurant per menu item using menu_items.price.
     */
    public function up(): void
    {
        $restaurants = DB::table('restaurant_masters')->where('is_active', true)->pluck('id');
        $items = DB::table('menu_items')->where('is_active', true)->get();

        foreach ($restaurants as $restaurantId) {
            foreach ($items as $item) {
                $exists = DB::table('restaurant_menu_items')
                    ->where('menu_item_id', $item->id)
                    ->where('restaurant_master_id', $restaurantId)
                    ->exists();

                if (!$exists) {
                    DB::table('restaurant_menu_items')->insert([
                        'menu_item_id' => $item->id,
                        'restaurant_master_id' => $restaurantId,
                        'price' => $item->price ?? 0,
                        'fixed_ept' => $item->fixed_ept,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optionally truncate - but we might want to keep manually added links
        // DB::table('restaurant_menu_items')->truncate();
    }
};
