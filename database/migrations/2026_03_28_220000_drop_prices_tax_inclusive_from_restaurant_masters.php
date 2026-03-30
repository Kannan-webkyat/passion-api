<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_masters', 'prices_tax_inclusive')) {
                $table->dropColumn('prices_tax_inclusive');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurant_masters', 'prices_tax_inclusive')) {
                $table->boolean('prices_tax_inclusive')->default(false)->after('bill_round_to_nearest_rupee');
            }
        });
    }
};
