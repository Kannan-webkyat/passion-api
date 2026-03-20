<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->foreignId('combo_id')->nullable()->after('order_id')->constrained('combos')->nullOnDelete();
        });
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropForeign(['combo_id']);
        });
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_item_id')->nullable(false)->change();
        });
    }
};
