<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            // Keep existing day-based flow intact: default is 'day'
            $table->string('billing_unit')->default('day')->after('name'); // day | hour_package

            // Hourly package configuration (e.g., 3h, 6h, 12h)
            $table->unsignedInteger('package_hours')->nullable()->after('billing_unit');
            $table->decimal('package_price', 10, 2)->nullable()->after('package_hours');

            // Optional overtime configuration (can be unused initially)
            $table->unsignedInteger('grace_minutes')->default(0)->after('package_price');
            $table->unsignedInteger('overtime_step_minutes')->default(60)->after('grace_minutes');
            $table->decimal('overtime_hour_price', 10, 2)->nullable()->after('overtime_step_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn([
                'billing_unit',
                'package_hours',
                'package_price',
                'grace_minutes',
                'overtime_step_minutes',
                'overtime_hour_price',
            ]);
        });
    }
};

