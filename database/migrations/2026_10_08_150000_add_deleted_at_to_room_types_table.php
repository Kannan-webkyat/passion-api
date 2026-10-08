<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('room_types', 'deleted_at')) {
            return;
        }

        Schema::table('room_types', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('room_types', 'deleted_at')) {
            return;
        }

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
