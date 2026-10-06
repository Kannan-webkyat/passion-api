<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            if (! Schema::hasColumn('room_types', 'bedrooms')) {
                $table->unsignedTinyInteger('bedrooms')->default(1)->after('capacity');
            }
            if (! Schema::hasColumn('room_types', 'washrooms')) {
                $table->unsignedTinyInteger('washrooms')->default(1)->after('bedrooms');
            }
            if (! Schema::hasColumn('room_types', 'weekday_price')) {
                $table->decimal('weekday_price', 10, 2)->default(0)->after('washrooms');
            }
            if (! Schema::hasColumn('room_types', 'weekend_price')) {
                $table->decimal('weekend_price', 10, 2)->default(0)->after('weekday_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['bedrooms', 'washrooms', 'weekday_price', 'weekend_price']);
        });
    }
};
