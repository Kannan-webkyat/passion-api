<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Track which KOT round each item belongs to, and whether it was cancelled
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->enum('status', ['active', 'cancelled'])->default('active')->after('kot_sent');
            $table->unsignedTinyInteger('kot_batch')->nullable()->after('status')
                ->comment('Which KOT round this item was sent in (null = not yet sent)');
        });

        // Track the current KOT round number on the order
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_kot_batch')->default(0)->after('kitchen_status')
                ->comment('Increments with each KOT send');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'kot_batch']);
        });
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn('current_kot_batch');
        });
    }
};
