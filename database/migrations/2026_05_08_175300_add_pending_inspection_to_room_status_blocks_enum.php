<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // room_status_blocks.status currently includes: maintenance, dirty, cleaning, inspected, on_hold
        DB::statement(
            "ALTER TABLE room_status_blocks MODIFY COLUMN status " .
                "ENUM('maintenance','dirty','cleaning','pending_inspection','inspected','on_hold') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE room_status_blocks MODIFY COLUMN status " .
                "ENUM('maintenance','dirty','cleaning','inspected','on_hold') NOT NULL"
        );
    }
};
