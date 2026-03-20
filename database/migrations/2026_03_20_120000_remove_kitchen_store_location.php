<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $kitchenStore = DB::table('inventory_locations')->where('name', 'Kitchen Store')->first();
        if (!$kitchenStore) {
            return;
        }

        $fallbackKitchen = DB::table('inventory_locations')
            ->where('type', 'kitchen_store')
            ->where('id', '!=', $kitchenStore->id)
            ->first();

        // Reassign restaurants pointing to Kitchen Store to first other kitchen
        if ($fallbackKitchen) {
            DB::table('restaurant_masters')
                ->where('kitchen_location_id', $kitchenStore->id)
                ->update(['kitchen_location_id' => $fallbackKitchen->id]);
        } else {
            DB::table('restaurant_masters')
                ->where('kitchen_location_id', $kitchenStore->id)
                ->update(['kitchen_location_id' => null]);
        }

        // Delete Kitchen Store (inventory_item_locations cascades via FK)
        DB::table('inventory_locations')->where('name', 'Kitchen Store')->delete();
    }

    public function down(): void
    {
        // Cannot reliably restore; seeder will recreate on fresh migrate:fresh
    }
};
