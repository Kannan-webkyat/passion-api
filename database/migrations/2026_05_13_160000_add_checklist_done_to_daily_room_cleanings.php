<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_room_cleanings', function (Blueprint $table) {
            $table->json('checklist_done')->nullable()->after('maintenance_note');
        });
    }

    public function down(): void
    {
        Schema::table('daily_room_cleanings', function (Blueprint $table) {
            $table->dropColumn('checklist_done');
        });
    }
};
