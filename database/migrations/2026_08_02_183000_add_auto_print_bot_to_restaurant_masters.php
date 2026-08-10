<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->boolean('auto_print_bot')->default(false)->after('auto_print_kot');
        });

        // Bars: BOT for bartender + KOT paper for waiters; kitchen uses KDS (not this flag).
        DB::table('restaurant_masters')
            ->where(function ($q) {
                $q->where('name', 'like', '%bar%')
                    ->orWhere('name', 'like', '%brews%')
                    ->orWhere('name', 'like', '%bubbles%');
            })
            ->update([
                'auto_print_bot' => true,
                'auto_print_kot' => true,
                'kot_ticket_label' => 'BOT',
                'kot_include_all_items' => true,
                'auto_print_payment_receipt' => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->dropColumn('auto_print_bot');
        });
    }
};
