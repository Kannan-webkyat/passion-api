<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_orders', 'delivery_charge')) {
                $table->decimal('delivery_charge', 10, 2)->default(0)->after('delivery_channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_charge');
        });
    }
};
