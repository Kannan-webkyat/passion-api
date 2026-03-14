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
        Schema::table('combos', function (Blueprint $table) {
            $table->dropForeign(['menu_sub_category_id']);
            $table->dropColumn('menu_sub_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combos', function (Blueprint $table) {
            $table->foreignId('menu_sub_category_id')->nullable()->constrained('menu_sub_categories')->onDelete('set null');
        });
    }
};
