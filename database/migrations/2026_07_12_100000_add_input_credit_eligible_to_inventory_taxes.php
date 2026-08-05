<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_taxes')) {
            return;
        }

        Schema::table('inventory_taxes', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_taxes', 'is_input_credit_eligible')) {
                $table->boolean('is_input_credit_eligible')
                    ->default(true)
                    ->after('type');
            }
        });

        // VAT = non-recoverable for liquor; GST types = recoverable input credit.
        if (Schema::hasColumn('inventory_taxes', 'is_input_credit_eligible')) {
            DB::table('inventory_taxes')
                ->where('type', 'vat')
                ->update(['is_input_credit_eligible' => false]);

            DB::table('inventory_taxes')
                ->whereIn('type', ['local', 'inter-state'])
                ->update(['is_input_credit_eligible' => true]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_taxes')) {
            return;
        }

        Schema::table('inventory_taxes', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_taxes', 'is_input_credit_eligible')) {
                $table->dropColumn('is_input_credit_eligible');
            }
        });
    }
};
