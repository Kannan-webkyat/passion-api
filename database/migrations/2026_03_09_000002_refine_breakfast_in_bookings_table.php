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
            $table->dropColumn('include_breakfast');
            $table->integer('adult_breakfast_count')->default(0)->after('extra_beds_count');
            $table->integer('child_breakfast_count')->default(0)->after('adult_breakfast_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['adult_breakfast_count', 'child_breakfast_count']);
            $table->boolean('include_breakfast')->default(false)->after('extra_beds_count');
        });
    }
};
