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
        Schema::create('store_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique(); // e.g., REQ-2024-001
            $table->foreignId('from_location_id')->constrained('inventory_locations'); // Department requesting
            $table->foreignId('to_location_id')->constrained('inventory_locations');   // Target (usually Main Store)
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->enum('status', ['pending', 'approved', 'issued', 'partially_issued', 'rejected', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_item_id')->constrained();
            $table->decimal('quantity_requested', 15, 2);
            $table->decimal('quantity_issued', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_request_items');
        Schema::dropIfExists('store_requests');
    }
};
