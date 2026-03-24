<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->string('void_reason', 100)->nullable()->after('closed_at');
            $table->string('void_notes', 500)->nullable()->after('void_reason');
            $table->foreignId('voided_by')->nullable()->after('void_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['void_reason', 'void_notes', 'voided_by', 'voided_at']);
        });
    }
};
