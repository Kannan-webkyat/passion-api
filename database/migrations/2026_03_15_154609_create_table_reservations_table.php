<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('table_reservations')) {
            Schema::create('table_reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('table_id')->constrained('restaurant_tables')->cascadeOnDelete();
                $table->string('guest_name');
                $table->string('guest_phone')->nullable();
                $table->string('guest_email')->nullable();
                $table->integer('party_size')->default(1);
                $table->date('reservation_date');
                $table->time('reservation_time');
                $table->enum('status', ['confirmed', 'seated', 'completed', 'cancelled', 'no_show'])->default('confirmed');
                $table->timestamp('checked_in_at')->nullable();
                $table->string('special_requests')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('table_reservations');
    }
};
