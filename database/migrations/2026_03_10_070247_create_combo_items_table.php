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
        if (! Schema::hasTable('combo_items')) {
            Schema::create('combo_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('combo_id')->constrained('combos')->onDelete('cascade');
                $table->foreignId('menu_item_id')->constrained('menu_items')->onDelete('cascade');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_items');
    }
};
