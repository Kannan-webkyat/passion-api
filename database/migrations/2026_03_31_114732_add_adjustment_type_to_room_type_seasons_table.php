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
        Schema::table('room_type_seasons', function (Blueprint $table) {
            $table->string('adjustment_type')->default('override')->after('end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_type_seasons', function (Blueprint $table) {
            $table->dropColumn('adjustment_type');
        });
    }
};
