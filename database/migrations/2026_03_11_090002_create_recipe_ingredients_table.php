<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->onDelete('cascade');
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->onDelete('set null');
            $table->decimal('quantity', 10, 3);           // usable quantity needed (after yield)
            $table->decimal('yield_percentage', 5, 2)->default(100.00); // e.g. 70 means 30% waste
            // raw_quantity = quantity / (yield_percentage / 100) — auto-calculated in app
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
    }
};
