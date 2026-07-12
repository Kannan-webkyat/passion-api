<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();

            $table->string('event_type', 40);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->foreignId('inventory_cost_layer_id')->nullable()->constrained('inventory_cost_layers')->nullOnDelete();

            $table->decimal('quantity_delta', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();

            $table->decimal('wac_before', 15, 4)->nullable();
            $table->decimal('wac_after', 15, 4)->nullable();
            $table->decimal('stock_before', 15, 4)->nullable();
            $table->decimal('stock_after', 15, 4)->nullable();

            $table->json('cost_breakdown')->nullable();
            $table->json('meta')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['inventory_item_id', 'occurred_at']);
            $table->index(['event_type', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_audit_log');
    }
};
