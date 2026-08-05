<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_room_cleanings', function (Blueprint $table) {
            $table->timestamp('daily_cleaning_completed_at')->nullable()->after('completed_at');
            $table->index(['room_id', 'service_date', 'daily_cleaning_completed_at'], 'drc_room_date_daily_done_idx');
        });

        Schema::table('room_cleaning_releases', function (Blueprint $table) {
            $table->enum('service_type', ['daily', 'other'])->default('daily')->after('priority');
            $table->string('service_subtype', 32)->nullable()->after('service_type');
            $table->index(['room_id', 'release_date', 'service_type'], 'rcr_room_date_service_type_idx');
        });

        DB::table('daily_room_cleanings')
            ->where('status', 'cleaned')
            ->whereNotNull('completed_at')
            ->whereNull('daily_cleaning_completed_at')
            ->update([
                'daily_cleaning_completed_at' => DB::raw('completed_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('room_cleaning_releases', function (Blueprint $table) {
            $table->dropIndex('rcr_room_date_service_type_idx');
            $table->dropColumn(['service_type', 'service_subtype']);
        });

        Schema::table('daily_room_cleanings', function (Blueprint $table) {
            $table->dropIndex('drc_room_date_daily_done_idx');
            $table->dropColumn('daily_cleaning_completed_at');
        });
    }
};
