<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_room_cleanings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->date('service_date')->index();
            $table->enum('status', ['pending_cleaning', 'in_progress', 'cleaned', 'inspection_pending'])->default('pending_cleaning')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->text('maintenance_note')->nullable();
            $table->timestamp('front_desk_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'service_date'], 'daily_room_cleanings_room_date_unique');
            $table->index(['service_date', 'status'], 'daily_room_cleanings_date_status');
        });

        Schema::create('daily_room_cleaning_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_room_cleaning_id')->constrained('daily_room_cleanings')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty', 15, 3);
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['daily_room_cleaning_id', 'inventory_item_id'], 'daily_clean_cons_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_room_cleaning_consumptions');
        Schema::dropIfExists('daily_room_cleanings');
    }
};
