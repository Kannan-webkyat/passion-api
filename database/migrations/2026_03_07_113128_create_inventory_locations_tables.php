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
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type')->default('department'); // main_store, department, satellite
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_item_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_location_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('reorder_level', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['inventory_item_id', 'inventory_location_id'], 'item_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_locations');
        Schema::dropIfExists('inventory_locations');
    }
};
