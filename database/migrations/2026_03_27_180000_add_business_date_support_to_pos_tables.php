<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurant_masters', 'business_day_cutoff_time')) {
                $table->time('business_day_cutoff_time')->default('04:00:00')->after('bar_location_id');
            }
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_orders', 'business_date')) {
                $table->date('business_date')->nullable()->after('restaurant_id')->index();
            }
        });

        Schema::table('pos_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_payments', 'business_date')) {
                $table->date('business_date')->nullable()->after('order_id')->index();
            }
        });

        Schema::table('pos_order_refunds', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_order_refunds', 'business_date')) {
                $table->date('business_date')->nullable()->after('order_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_refunds', function (Blueprint $table) {
            if (Schema::hasColumn('pos_order_refunds', 'business_date')) {
                $table->dropColumn('business_date');
            }
        });

        Schema::table('pos_payments', function (Blueprint $table) {
            if (Schema::hasColumn('pos_payments', 'business_date')) {
                $table->dropColumn('business_date');
            }
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            if (Schema::hasColumn('pos_orders', 'business_date')) {
                $table->dropColumn('business_date');
            }
        });

        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_masters', 'business_day_cutoff_time')) {
                $table->dropColumn('business_day_cutoff_time');
            }
        });
    }
};
