<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropForeign(['menu_item_id']);
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_item_id')->nullable()->change();
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->onDelete('cascade');
            $table->foreignId('output_inventory_item_id')
                ->nullable()
                ->after('menu_item_id')
                ->constrained('inventory_items')
                ->nullOnDelete();
            $table->string('recipe_kind', 32)->default('menu_item')->after('output_inventory_item_id');
        });

        DB::table('recipes')->whereNull('recipe_kind')->update(['recipe_kind' => 'menu_item']);
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropForeign(['output_inventory_item_id']);
            $table->dropColumn(['output_inventory_item_id', 'recipe_kind']);
            $table->dropForeign(['menu_item_id']);
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_item_id')->nullable(false)->change();
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->onDelete('cascade');
        });
    }
};
