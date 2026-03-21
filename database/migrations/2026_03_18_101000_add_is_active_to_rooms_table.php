<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('room_type_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            if (Schema::hasColumn('rooms', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
