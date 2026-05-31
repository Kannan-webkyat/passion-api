<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('daily_room_cleanings')
            ->where('status', '=', 'inspection_pending')
            ->update(['status' => 'cleaned']);

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE daily_room_cleanings MODIFY COLUMN status ENUM('pending_cleaning','in_progress','cleaned') NOT NULL DEFAULT 'pending_cleaning'");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE daily_room_cleanings MODIFY COLUMN status ENUM('pending_cleaning','in_progress','cleaned','inspection_pending') NOT NULL DEFAULT 'pending_cleaning'");
        }
    }
};
