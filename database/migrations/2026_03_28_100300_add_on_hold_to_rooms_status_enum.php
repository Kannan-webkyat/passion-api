<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('available','occupied','maintenance','dirty','cleaning','on_hold') DEFAULT 'available'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('available','occupied','maintenance','dirty','cleaning') DEFAULT 'available'");
    }
};
