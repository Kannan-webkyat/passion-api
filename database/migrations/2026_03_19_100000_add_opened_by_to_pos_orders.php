<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->foreignId('opened_by')->nullable()->after('waiter_id')->constrained('users')->nullOnDelete();
        });

        // Backfill: for existing orders, opened_by = waiter_id (who took it before we tracked)
        DB::table('pos_orders')->whereNull('opened_by')->update([
            'opened_by' => DB::raw('waiter_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropForeign(['opened_by']);
        });
    }
};
