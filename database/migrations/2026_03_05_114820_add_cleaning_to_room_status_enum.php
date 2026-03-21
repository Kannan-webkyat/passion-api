<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL to update ENUM as it's more reliable for ENUM changes in MySQL
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('available', 'occupied', 'maintenance', 'dirty', 'cleaning') DEFAULT 'available'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('available', 'occupied', 'maintenance', 'dirty') DEFAULT 'available'");
    }
};
