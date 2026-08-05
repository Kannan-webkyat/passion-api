<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_day_closing_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id')->nullable();
            $table->foreignId('restaurant_id')->constrained('restaurant_masters')->cascadeOnDelete();
            $table->date('closed_date');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            // Full financial snapshot of the sealed Z-report at the moment it was unlocked.
            $table->json('snapshot')->nullable();

            // Audit of the unlock action that archived this row.
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->string('unlock_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['restaurant_id', 'closed_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_day_closing_archives');
    }
};
