<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_day_closings', function (Blueprint $table) {
            $table->decimal('total_tip', 12, 2)->default(0)->after('total_service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('pos_day_closings', function (Blueprint $table) {
            $table->dropColumn('total_tip');
        });
    }
};
