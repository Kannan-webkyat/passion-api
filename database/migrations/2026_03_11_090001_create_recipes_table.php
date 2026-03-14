<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->onDelete('cascade');
            $table->decimal('yield_quantity', 10, 3)->default(1);   // how many portions this makes
            $table->foreignId('yield_uom_id')->nullable()->constrained('inventory_uoms')->onDelete('set null');
            $table->decimal('food_cost_target', 5, 2)->nullable();  // target food cost % (e.g. 30.00)
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
