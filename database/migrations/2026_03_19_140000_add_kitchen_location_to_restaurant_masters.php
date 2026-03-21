<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->foreignId('kitchen_location_id')
                ->nullable()
                ->after('is_active')
                ->constrained('inventory_locations')
                ->nullOnDelete();
        });

        // Default: link all existing restaurants to Kitchen Store (first kitchen_store)
        $kitchenStore = DB::table('inventory_locations')->where('type', 'kitchen_store')->first();
        if ($kitchenStore) {
            DB::table('restaurant_masters')->update(['kitchen_location_id' => $kitchenStore->id]);
        }
    }

    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->dropForeign(['kitchen_location_id']);
        });
    }
};
