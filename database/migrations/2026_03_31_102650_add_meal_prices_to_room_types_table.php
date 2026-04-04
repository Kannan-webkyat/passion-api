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
            $table->decimal('adult_lunch_price', 10, 2)->default(0)->after('child_breakfast_price');
            $table->decimal('child_lunch_price', 10, 2)->default(0)->after('adult_lunch_price');
            $table->decimal('adult_dinner_price', 10, 2)->default(0)->after('child_lunch_price');
            $table->decimal('child_dinner_price', 10, 2)->default(0)->after('adult_dinner_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn([
                'adult_lunch_price',
                'child_lunch_price',
                'adult_dinner_price',
                'child_dinner_price',
            ]);
        });
    }
};
