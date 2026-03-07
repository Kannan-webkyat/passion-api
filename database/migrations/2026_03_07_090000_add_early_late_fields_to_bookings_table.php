<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->time('early_checkin_time')->nullable()->after('estimated_arrival_time');
            $table->time('late_checkout_time')->nullable()->after('early_checkin_time');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['early_checkin_time', 'late_checkout_time']);
        });
    }
};
