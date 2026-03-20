<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->boolean('is_direct_sale')->default(false)->after('current_stock');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('is_direct_sale')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('is_direct_sale');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('is_direct_sale');
        });
    }
};
