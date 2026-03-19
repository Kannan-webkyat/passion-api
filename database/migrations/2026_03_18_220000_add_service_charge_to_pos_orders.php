<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->string('service_charge_type', 20)->nullable()->after('discount_value');
            $table->decimal('service_charge_value', 10, 2)->default(0)->after('service_charge_type');
            $table->decimal('service_charge_amount', 10, 2)->default(0)->after('service_charge_value');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn(['service_charge_type', 'service_charge_value', 'service_charge_amount']);
        });
    }
};
