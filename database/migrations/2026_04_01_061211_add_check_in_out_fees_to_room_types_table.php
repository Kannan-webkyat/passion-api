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
            $table->decimal('early_check_in_fee', 10, 2)->nullable()->after('child_age_limit');
            $table->string('early_check_in_type')->nullable()->after('early_check_in_fee');
            $table->decimal('late_check_out_fee', 10, 2)->nullable()->after('early_check_in_type');
            $table->string('late_check_out_type')->nullable()->after('late_check_out_fee');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['early_check_in_fee', 'early_check_in_type', 'late_check_out_fee', 'late_check_out_type']);
        });
    }
};
