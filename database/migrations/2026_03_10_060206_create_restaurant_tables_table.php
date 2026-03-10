<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
             $table->string('table_number');
    $table->foreignId('category_id')->constrained('table_categories');
    $table->integer('capacity');
    $table->enum('status', [
        'available',
        'occupied',
        'reserved',
        'cleaning',
        'inactive'
    ])->default('available');
    $table->string('location')->nullable();
    $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
