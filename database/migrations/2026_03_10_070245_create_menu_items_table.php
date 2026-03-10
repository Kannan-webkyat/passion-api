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
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code')->unique();
            $table->string('name');
            $table->foreignId('menu_category_id')->constrained()->onDelete('cascade');
            $table->foreignId('menu_sub_category_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('fixed_ept')->nullable();
            $table->string('type')->default('Veg');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
