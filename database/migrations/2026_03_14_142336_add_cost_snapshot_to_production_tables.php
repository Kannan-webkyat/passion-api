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
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('quantity');
            $table->decimal('total_cost', 15, 4)->nullable()->after('unit_cost');
        });

        Schema::table('production_logs', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('quantity_produced');
            $table->decimal('total_cost', 15, 4)->nullable()->after('unit_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });

        Schema::table('production_logs', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });
    }
};
