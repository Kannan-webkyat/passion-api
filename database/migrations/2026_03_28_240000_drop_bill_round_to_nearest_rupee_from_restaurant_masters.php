<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('restaurant_masters') && Schema::hasColumn('restaurant_masters', 'bill_round_to_nearest_rupee')) {
            Schema::table('restaurant_masters', function (Blueprint $table) {
                $table->dropColumn('bill_round_to_nearest_rupee');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('restaurant_masters') && ! Schema::hasColumn('restaurant_masters', 'bill_round_to_nearest_rupee')) {
            Schema::table('restaurant_masters', function (Blueprint $table) {
                $table->boolean('bill_round_to_nearest_rupee')->default(false)->after('business_day_cutoff_time');
            });
        }
    }
};
