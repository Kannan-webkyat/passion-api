<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_orders', 'order_type')) {
                $table->string('order_type', 20)->default('dine_in')->after('id');
            }
            if (!Schema::hasColumn('pos_orders', 'room_id')) {
                $table->foreignId('room_id')->nullable()->after('table_id')->constrained('rooms')->nullOnDelete();
            }
            if (!Schema::hasColumn('pos_orders', 'booking_id')) {
                $table->foreignId('booking_id')->nullable()->after('room_id')->constrained('bookings')->nullOnDelete();
            }
            if (!Schema::hasColumn('pos_orders', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('booking_id');
            }
            if (!Schema::hasColumn('pos_orders', 'customer_phone')) {
                $table->string('customer_phone')->nullable()->after('customer_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn([
                'order_type', 'room_id', 'booking_id', 'customer_name', 'customer_phone',
            ]);
        });
    }
};
