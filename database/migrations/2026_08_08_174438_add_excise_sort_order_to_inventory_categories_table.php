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
            $table->unsignedInteger('excise_sort_order')->nullable()->after('parent_id');
        });

        // Sensible Kerala-style defaults for common alcohol types (rarely change).
        $defaults = [
            'Whisky' => 10,
            'Brandy' => 20,
            'Rum' => 30,
            'Vodka' => 40,
            'Gin' => 50,
            'Wine' => 60,
            'Beer' => 70,
            'Alcohol' => 5,
        ];
        foreach ($defaults as $name => $order) {
            DB::table('inventory_categories')
                ->where('name', $name)
                ->update(['excise_sort_order' => $order]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->dropColumn('excise_sort_order');
        });
    }
};
