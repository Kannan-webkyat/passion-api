<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('inventory_taxes', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('name'); // e.g., GST 5%, Alcohol Tax 20%
            $blueprint->decimal('rate', 5, 2); // e.g., 5.00, 20.00
            $blueprint->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('inventory_taxes');
    }
};
