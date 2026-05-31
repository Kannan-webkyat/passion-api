<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('guest_name', 255);
            $table->dateTime('pickup_at');
            $table->text('notes')->nullable();
            $table->text('damage_notes')->nullable();
            $table->boolean('express')->default(false);
            $table->decimal('express_surcharge_amount', 10, 2)->default(0);
            $table->string('status', 32)->default('pending_pickup');
            $table->json('pickup_items')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->decimal('posted_amount', 10, 2)->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'pickup_at']);
            $table->index(['booking_id']);
            $table->index(['room_id']);
        });

        Schema::create('laundry_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_request_id')->constrained('laundry_requests')->cascadeOnDelete();
            $table->string('item_type', 255);
            $table->string('service_type', 32);
            $table->decimal('qty', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->timestamps();

            $table->index(['laundry_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_request_lines');
        Schema::dropIfExists('laundry_requests');
    }
};
