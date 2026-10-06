<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_types') || Schema::hasColumn('room_types', 'child_age_from')) {
            return;
        }

        Schema::table('room_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('child_age_from')->default(0)->after('child_age_limit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_types') || ! Schema::hasColumn('room_types', 'child_age_from')) {
            return;
        }

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('child_age_from');
        });
    }
};
