<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_day_closings', function (Blueprint $table) {
            $table->decimal('total_service_charge', 12, 2)->default(0)->after('total_tax');
        });
    }

    public function down(): void
    {
        Schema::table('pos_day_closings', function (Blueprint $table) {
            $table->dropColumn('total_service_charge');
        });
    }
};
