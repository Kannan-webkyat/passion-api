<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_master_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['combo_id', 'restaurant_master_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_combos');
    }
};
