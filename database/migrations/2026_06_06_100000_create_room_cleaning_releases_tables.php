<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_cleaning_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_status_block_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('daily_room_cleaning_id')->nullable()->constrained()->nullOnDelete();
            $table->date('release_date');
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->enum('status', [
                'available',
                'in_progress',
                'completed',
                'inspection_pending',
                'ready',
                'expired',
                'cancelled',
            ])->default('available');
            $table->enum('priority', ['normal', 'urgent', 'vip', 'deep_clean'])->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'is_active', 'status'], 'rcr_room_active_status_idx');
            $table->index(['release_date', 'status'], 'rcr_date_status_idx');
            $table->index(['window_start', 'window_end'], 'rcr_window_idx');
        });

        Schema::create('room_cleaning_release_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_cleaning_release_id')->constrained()->cascadeOnDelete();
            $table->string('action', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['room_cleaning_release_id', 'created_at'], 'rcr_audits_release_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_cleaning_release_audits');
        Schema::dropIfExists('room_cleaning_releases');
    }
};
