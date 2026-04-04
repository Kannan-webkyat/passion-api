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
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['includes_breakfast', 'includes_lunch', 'includes_dinner']);
            $table->string('meal_plan_type')->default('room_only')->after('overtime_hour_price');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('meal_plan_type');
            $table->boolean('includes_breakfast')->default(false);
            $table->boolean('includes_lunch')->default(false);
            $table->boolean('includes_dinner')->default(false);
        });
    }
};
