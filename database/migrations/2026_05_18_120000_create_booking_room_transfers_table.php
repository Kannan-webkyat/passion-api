<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_room_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('booking_segment_id')->nullable()->constrained('booking_segments')->nullOnDelete();
            $table->foreignId('from_room_id')->constrained('rooms');
            $table->foreignId('to_room_id')->constrained('rooms');
            $table->foreignId('from_room_type_id')->constrained('room_types');
            $table->foreignId('to_room_type_id')->constrained('room_types');
            $table->string('transfer_reason', 64);
            $table->text('internal_notes')->nullable();
            $table->string('rate_mode', 32);
            $table->boolean('is_complimentary_upgrade')->default(false);
            $table->decimal('old_total_price', 12, 2)->default(0);
            $table->decimal('new_total_price', 12, 2)->default(0);
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->decimal('segment_price', 12, 2)->default(0);
            $table->timestamp('transferred_at');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'transferred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_room_transfers');
    }
};
