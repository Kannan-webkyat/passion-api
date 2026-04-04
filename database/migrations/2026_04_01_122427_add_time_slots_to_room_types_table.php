<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->time('early_check_in_start_time')->nullable()->after('early_check_in_type');
            $table->time('late_check_out_end_time')->nullable()->after('late_check_out_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['early_check_in_start_time', 'late_check_out_end_time']);
        });
    }
};
