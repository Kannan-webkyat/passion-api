<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_orders') && ! Schema::hasColumn('pos_orders', 'packing_charge')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->decimal('packing_charge', 10, 2)->default(0)->after('delivery_charge');
            });
        }

        if (Schema::hasTable('restaurant_masters') && ! Schema::hasColumn('restaurant_masters', 'default_packing_charge')) {
            Schema::table('restaurant_masters', function (Blueprint $table) {
                $table->decimal('default_packing_charge', 10, 2)->default(0)->after('kot_include_all_items');
            });
        }

        if (Schema::hasTable('chart_of_accounts')) {
            DB::table('chart_of_accounts')->updateOrInsert(
                ['code' => '4311'],
                [
                    'name' => 'Packing / Parcel Charge Income',
                    'type' => 'income',
                    'parent_code' => null,
                    'is_posting' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'packing_charge')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->dropColumn('packing_charge');
            });
        }

        if (Schema::hasTable('restaurant_masters') && Schema::hasColumn('restaurant_masters', 'default_packing_charge')) {
            Schema::table('restaurant_masters', function (Blueprint $table) {
                $table->dropColumn('default_packing_charge');
            });
        }
    }
};
