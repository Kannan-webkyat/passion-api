<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('cancellation_fee_amount', 10, 2)->default(0)->after('refund_method');
            $table->string('cancellation_reason', 64)->nullable()->after('cancellation_fee_amount');
            $table->string('cancellation_notes', 500)->nullable()->after('cancellation_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_notes');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'cancellation_fee_amount',
                'cancellation_reason',
                'cancellation_notes',
                'cancelled_at',
            ]);
        });
    }
};
