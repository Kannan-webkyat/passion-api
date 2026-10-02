<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_payments') || Schema::hasIndex('booking_payments', ['paid_at'])) {
            return;
        }

        Schema::table('booking_payments', function (Blueprint $table) {
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_payments') || ! Schema::hasIndex('booking_payments', ['paid_at'])) {
            return;
        }

        Schema::table('booking_payments', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
        });
    }
};
