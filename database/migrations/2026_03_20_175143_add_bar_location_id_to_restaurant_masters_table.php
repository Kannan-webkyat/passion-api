<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->foreignId('bar_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->dropForeign(['bar_location_id']);
            $table->dropColumn('bar_location_id');
        });
    }
};
