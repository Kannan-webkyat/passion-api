<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();

            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('grn_item_id')->nullable()->constrained('grn_items')->nullOnDelete();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();

            $table->decimal('quantity_received', 15, 4);
            $table->decimal('quantity_remaining', 15, 4);

            $table->decimal('landed_unit_cost', 15, 4);
            $table->decimal('merchandise_unit_cost', 15, 4)->default(0);
            $table->decimal('cess_unit_cost', 15, 4)->default(0);
            $table->decimal('freight_unit_cost', 15, 4)->default(0);
            $table->decimal('non_recoverable_tax_unit_cost', 15, 4)->default(0);

            $table->string('inventory_costing_mode', 20)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'quantity_remaining']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layers');
    }
};
