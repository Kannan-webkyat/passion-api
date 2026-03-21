<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Split name
            $table->string('first_name')->after('room_id');
            $table->string('last_name')->after('first_name');

            // Guest counts
            $table->integer('adults_count')->default(1)->after('phone');
            $table->integer('children_count')->default(0)->after('adults_count');
            $table->integer('extra_beds_count')->default(0)->after('children_count');

            // Payment info
            $table->enum('payment_status', ['pending', 'partial', 'paid', 'refunded'])->default('pending')->after('total_price');
            $table->string('payment_method')->nullable()->after('payment_status');
            $table->decimal('deposit_amount', 10, 2)->default(0)->after('payment_method');

            // Source & Meta
            $table->string('booking_source')->default('walk-in')->after('status');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null')->after('notes');

            // Drop old name column
            $table->dropColumn('guest_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('guest_name')->after('room_id');
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'first_name',
                'last_name',
                'adults_count',
                'children_count',
                'extra_beds_count',
                'payment_status',
                'payment_method',
                'deposit_amount',
                'booking_source',
                'created_by',
            ]);
        });
    }
};
