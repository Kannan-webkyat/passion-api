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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('identity_type')->nullable()->after('phone');
            $table->string('identity_number')->nullable()->after('identity_type');
            $table->string('city')->nullable()->after('identity_number');
            $table->string('country')->nullable()->default('India')->after('city');
            $table->string('estimated_arrival_time')->nullable()->after('check_out');
            $table->string('source_reference')->nullable()->after('booking_source');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['identity_type', 'identity_number', 'city', 'country', 'estimated_arrival_time', 'source_reference']);
        });
    }
};
