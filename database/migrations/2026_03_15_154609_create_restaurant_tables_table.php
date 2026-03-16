<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_tables')) {
            Schema::create('restaurant_tables', function (Blueprint $table) {
                $table->id();
                $table->string('table_number');
                $table->foreignId('restaurant_master_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('table_categories')->cascadeOnDelete();
                $table->integer('capacity')->default(1);
                $table->enum('status', ['available', 'occupied', 'reserved', 'cleaning', 'inactive'])->default('available');
                $table->string('location')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
