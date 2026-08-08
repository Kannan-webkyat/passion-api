<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->timestamp('kot_sent_at')->nullable()->after('kot_sent');
        });

        // Best-effort backfill for open tickets already on KDS
        DB::table('pos_order_items')
            ->where('kot_sent', true)
            ->whereNull('kot_sent_at')
            ->update([
                'kot_sent_at' => DB::raw('COALESCE(kot_started_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropColumn('kot_sent_at');
        });
    }
};
