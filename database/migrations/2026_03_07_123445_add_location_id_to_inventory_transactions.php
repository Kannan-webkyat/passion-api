<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->foreignId('inventory_location_id')->nullable()->after('inventory_item_id')->constrained('inventory_locations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['inventory_location_id']);
            $table->dropColumn('inventory_location_id');
        });
    }
};
