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
            $table->integer('early_check_in_buffer_minutes')->default(0)->after('early_check_in_start_time');
            $table->integer('late_check_out_buffer_minutes')->default(0)->after('late_check_out_end_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['early_check_in_buffer_minutes', 'late_check_out_buffer_minutes']);
        });
    }
};
