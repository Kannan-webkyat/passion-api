<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_orders', 'kitchen_status')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->enum('kitchen_status', ['pending', 'preparing', 'ready', 'served'])
                      ->default('pending')
                      ->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_orders', 'kitchen_status')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->dropColumn('kitchen_status');
            });
        }
    }
};
