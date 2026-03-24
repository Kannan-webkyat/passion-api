<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->string('cancel_reason', 100)->nullable()->after('notes');
            $table->string('cancel_notes', 500)->nullable()->after('cancel_reason');
            $table->foreignId('cancelled_by')->nullable()->after('cancel_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancel_reason', 'cancel_notes', 'cancelled_by', 'cancelled_at']);
        });
    }
};
