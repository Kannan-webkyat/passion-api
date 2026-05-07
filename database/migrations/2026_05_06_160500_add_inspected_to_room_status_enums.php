<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // rooms.status currently: available, occupied, maintenance, dirty, cleaning, on_hold
        DB::statement(
            "ALTER TABLE rooms MODIFY COLUMN status " .
                "ENUM('available','occupied','maintenance','dirty','cleaning','inspected','on_hold') DEFAULT 'available'"
        );

        // room_status_blocks.status currently: maintenance, dirty, cleaning, on_hold
        DB::statement(
            "ALTER TABLE room_status_blocks MODIFY COLUMN status " .
                "ENUM('maintenance','dirty','cleaning','inspected','on_hold') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE rooms MODIFY COLUMN status " .
                "ENUM('available','occupied','maintenance','dirty','cleaning','on_hold') DEFAULT 'available'"
        );
        DB::statement(
            "ALTER TABLE room_status_blocks MODIFY COLUMN status " .
                "ENUM('maintenance','dirty','cleaning','on_hold') NOT NULL"
        );
    }
};
