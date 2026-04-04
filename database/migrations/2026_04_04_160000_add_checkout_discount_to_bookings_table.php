<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('checkout_discount_amount', 10, 2)->default(0)->after('extra_charges');
            $table->string('checkout_discount_reason', 500)->nullable()->after('checkout_discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['checkout_discount_amount', 'checkout_discount_reason']);
        });
    }
};
