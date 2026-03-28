<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurant_masters', 'bill_round_to_nearest_rupee')) {
                $table->boolean('bill_round_to_nearest_rupee')->default(false)->after('business_day_cutoff_time');
            }
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_orders', 'rounding_amount')) {
                $table->decimal('rounding_amount', 10, 2)->default(0)->after('tip_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            if (Schema::hasColumn('pos_orders', 'rounding_amount')) {
                $table->dropColumn('rounding_amount');
            }
        });

        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_masters', 'bill_round_to_nearest_rupee')) {
                $table->dropColumn('bill_round_to_nearest_rupee');
            }
        });
    }
};
