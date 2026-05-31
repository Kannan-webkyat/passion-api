<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_status_block_id')->constrained('room_status_blocks')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->enum('status', ['in_progress', 'inspected', 'completed'])->default('in_progress')->index();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->string('issues_summary', 500)->nullable();
            $table->timestamps();

            $table->index(['room_id', 'status'], 'hk_jobs_room_status');
        });

        Schema::create('housekeeping_job_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('housekeeping_job_id')->constrained('housekeeping_jobs')->cascadeOnDelete();
            $table->enum('kind', ['amenity', 'minibar', 'asset', 'checklist'])->index();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->decimal('qty', 15, 3)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['housekeeping_job_id', 'kind'], 'hk_job_lines_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_job_lines');
        Schema::dropIfExists('housekeeping_jobs');
    }
};
