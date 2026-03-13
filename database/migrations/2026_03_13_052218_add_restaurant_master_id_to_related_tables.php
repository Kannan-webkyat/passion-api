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
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->foreignId('restaurant_master_id')->nullable()->constrained('restaurant_masters')->onDelete('cascade');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreignId('restaurant_master_id')->nullable()->constrained('restaurant_masters')->onDelete('cascade');
        });



        Schema::table('combos', function (Blueprint $table) {
            $table->foreignId('restaurant_master_id')->nullable()->constrained('restaurant_masters')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropForeign(['restaurant_master_id']);
            $table->dropColumn('restaurant_master_id');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['restaurant_master_id']);
            $table->dropColumn('restaurant_master_id');
        });



        Schema::table('combos', function (Blueprint $table) {
            $table->dropForeign(['restaurant_master_id']);
            $table->dropColumn('restaurant_master_id');
        });
    }
};
