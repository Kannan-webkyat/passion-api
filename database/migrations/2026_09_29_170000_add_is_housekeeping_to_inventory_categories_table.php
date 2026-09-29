<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->boolean('is_housekeeping')->default(false)->after('excise_sort_order');
        });

        DB::table('inventory_categories')
            ->whereNull('parent_id')
            ->where('name', 'Housekeeping')
            ->update(['is_housekeeping' => true]);
    }

    public function down(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->dropColumn('is_housekeeping');
        });
    }
};
