<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_par_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('name', 120)->default('Default');
            $table->timestamps();

            $table->unique(['room_type_id', 'name'], 'room_par_template_unique');
        });

        Schema::create('room_par_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('room_par_templates')->cascadeOnDelete();
            $table->enum('kind', ['amenity', 'minibar', 'asset'])->default('amenity')->index();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('par_qty', 15, 3)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'inventory_item_id', 'kind'], 'room_par_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_par_template_lines');
        Schema::dropIfExists('room_par_templates');
    }
};
