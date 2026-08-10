<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_day_closings')) {
            return;
        }

        Schema::table('pos_day_closings', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_day_closings', 'total_packing_charge')) {
                $table->decimal('total_packing_charge', 12, 2)->default(0)->after('total_tip');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_day_closings')) {
            return;
        }

        Schema::table('pos_day_closings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_day_closings', 'total_packing_charge')) {
                $table->dropColumn('total_packing_charge');
            }
        });
    }
};
