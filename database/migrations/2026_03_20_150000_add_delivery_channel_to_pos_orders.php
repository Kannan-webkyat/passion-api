<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_orders', 'delivery_channel')) {
                $table->string('delivery_channel', 30)->nullable()->after('delivery_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_channel');
        });
    }
};
