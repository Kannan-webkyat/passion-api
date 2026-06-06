<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 100);
            $table->string('task_name');
            $table->string('category', 64)->index();
            $table->string('section', 64)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->timestamps();

            $table->unique(['category', 'task_key']);
        });

        Schema::create('service_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('service_type', 64);
            $table->unsignedBigInteger('service_id');
            $table->string('task_key', 100);
            $table->string('task_name');
            $table->string('section', 64)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('required')->default(true);
            $table->boolean('completed')->default(false);
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->timestamps();

            $table->index(['service_type', 'service_id']);
            $table->unique(['service_type', 'service_id', 'task_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_checklist_items');
        Schema::dropIfExists('housekeeping_checklist_items');
    }
};
