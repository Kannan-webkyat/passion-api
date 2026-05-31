<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_locations', 'kind')) {
                $table->string('kind', 20)->default('store')->after('type'); // store|outlet|room
                $table->index('kind');
            }
            if (! Schema::hasColumn('inventory_locations', 'room_id')) {
                $table->foreignId('room_id')->nullable()->after('department_id')->constrained('rooms')->nullOnDelete();
                $table->unique('room_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_locations', 'room_id')) {
                $table->dropUnique(['room_id']);
                $table->dropForeign(['room_id']);
                $table->dropColumn('room_id');
            }
            if (Schema::hasColumn('inventory_locations', 'kind')) {
                $table->dropIndex(['kind']);
                $table->dropColumn('kind');
            }
        });
    }
};
