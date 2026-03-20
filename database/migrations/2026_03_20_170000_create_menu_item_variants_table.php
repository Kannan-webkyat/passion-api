<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_item_variants')) {
        Schema::create('menu_item_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->onDelete('cascade');
            $table->string('size_label', 50); // e.g. "30ml", "60ml", "Full bottle"
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('ml_quantity', 10, 2)->nullable(); // optional, for inventory tracking
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
        }

        if (!Schema::hasTable('restaurant_menu_item_variants')) {
        Schema::create('restaurant_menu_item_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_menu_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('menu_item_variant_id')->constrained()->onDelete('cascade');
            $table->decimal('price', 10, 2);
            $table->timestamps();
            $table->unique(['restaurant_menu_item_id', 'menu_item_variant_id'], 'rmi_variants_unique');
        });
        }

        if (!Schema::hasColumn('pos_order_items', 'menu_item_variant_id')) {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->foreignId('menu_item_variant_id')->nullable()->after('menu_item_id')->constrained()->onDelete('set null');
        });
        }
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropForeign(['menu_item_variant_id']);
        });
        Schema::dropIfExists('restaurant_menu_item_variants');
        Schema::dropIfExists('menu_item_variants');
    }
};
