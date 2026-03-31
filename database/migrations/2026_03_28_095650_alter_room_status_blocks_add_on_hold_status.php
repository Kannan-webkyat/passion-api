<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE room_status_blocks MODIFY COLUMN status ENUM('maintenance','dirty','cleaning','on_hold') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE room_status_blocks MODIFY COLUMN status ENUM('maintenance','dirty','cleaning') NOT NULL");
    }
};
