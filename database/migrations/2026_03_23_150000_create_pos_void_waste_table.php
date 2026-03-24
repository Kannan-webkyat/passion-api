<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_void_waste', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->onDelete('cascade');
            $table->foreignId('pos_order_item_id')->nullable()->constrained('pos_order_items')->onDelete('set null');
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->onDelete('cascade');
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('voided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_void_waste');
    }
};
