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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Seed defaults
        DB::table('payment_methods')->insert([
            ['name' => 'Bank Transfer / NEFT', 'is_active' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cash Payment', 'is_active' => true, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'UPI / GPay / PayTM', 'is_active' => true, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Company Card', 'is_active' => true, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
