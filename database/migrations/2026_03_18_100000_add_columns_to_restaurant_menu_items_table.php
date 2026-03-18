<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds menu_item_id, restaurant_master_id, price, fixed_ept (cost), is_active
     * to support one menu item across multiple restaurants with per-restaurant pricing.
     */
    public function up(): void
    {
        Schema::table('restaurant_menu_items', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_master_id')->after('menu_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0)->after('restaurant_master_id');
            $table->integer('fixed_ept')->nullable()->after('price');
            $table->boolean('is_active')->default(true)->after('fixed_ept');

            $table->unique(['menu_item_id', 'restaurant_master_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_menu_items', function (Blueprint $table) {
            $table->dropUnique(['menu_item_id', 'restaurant_master_id']);
            $table->dropForeign(['menu_item_id']);
            $table->dropForeign(['restaurant_master_id']);
            $table->dropColumn(['menu_item_id', 'restaurant_master_id', 'price', 'fixed_ept', 'is_active']);
        });
    }
};
