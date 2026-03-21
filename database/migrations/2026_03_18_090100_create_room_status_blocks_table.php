<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_status_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['maintenance', 'dirty', 'cleaning'])->index();
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'is_active', 'start_date', 'end_date'], 'room_blocks_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_blocks');
    }
};
