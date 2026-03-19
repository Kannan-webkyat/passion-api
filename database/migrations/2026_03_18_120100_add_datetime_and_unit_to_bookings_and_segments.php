<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Backward compatible: keep existing date columns, add datetime columns.
            $table->dateTime('check_in_at')->nullable()->after('check_in');
            $table->dateTime('check_out_at')->nullable()->after('check_out');
            $table->string('booking_unit')->default('day')->after('check_out_at'); // day | hour_package
        });

        Schema::table('booking_segments', function (Blueprint $table) {
            $table->dateTime('check_in_at')->nullable()->after('check_in');
            $table->dateTime('check_out_at')->nullable()->after('check_out');
        });

        // Backfill existing records so day bookings retain identical behavior.
        // Use midnight timestamps based on existing date columns.
        DB::statement("UPDATE bookings SET check_in_at = CONCAT(check_in, ' 00:00:00') WHERE check_in_at IS NULL AND check_in IS NOT NULL");
        DB::statement("UPDATE bookings SET check_out_at = CONCAT(check_out, ' 00:00:00') WHERE check_out_at IS NULL AND check_out IS NOT NULL");

        DB::statement("UPDATE booking_segments SET check_in_at = CONCAT(check_in, ' 00:00:00') WHERE check_in_at IS NULL AND check_in IS NOT NULL");
        DB::statement("UPDATE booking_segments SET check_out_at = CONCAT(check_out, ' 00:00:00') WHERE check_out_at IS NULL AND check_out IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('booking_segments', function (Blueprint $table) {
            $table->dropColumn(['check_in_at', 'check_out_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['check_in_at', 'check_out_at', 'booking_unit']);
        });
    }
};

