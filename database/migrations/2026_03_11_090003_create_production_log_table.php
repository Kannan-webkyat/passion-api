<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->onDelete('cascade');
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->onDelete('set null'); // which kitchen
            $table->decimal('quantity_produced', 10, 3);  // how many portions/batches made
            $table->foreignId('produced_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('production_date')->useCurrent();
            $table->text('notes')->nullable();
            $table->string('reference_id')->nullable()->index(); // links to inventory_transactions group
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_logs');
    }
};
