<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aiosell_integrations') && ! Schema::hasColumn('aiosell_integrations', 'rates_pending_room_type_ids')) {
            Schema::table('aiosell_integrations', function (Blueprint $table) {
                $table->json('rates_pending_room_type_ids')->nullable()->after('inventory_dirty');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('aiosell_integrations', 'rates_pending_room_type_ids')) {
            Schema::table('aiosell_integrations', function (Blueprint $table) {
                $table->dropColumn('rates_pending_room_type_ids');
            });
        }
    }
};
