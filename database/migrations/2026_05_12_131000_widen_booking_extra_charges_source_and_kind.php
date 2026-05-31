<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy `booking_extra_charges` tables may have `source` / `kind` as ENUM or very short VARCHAR.
 * Values like `inspection` and `asset_penalty` then trigger MySQL 1265 (data truncated).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_extra_charges')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        $table = 'booking_extra_charges';

        if (Schema::hasColumn($table, 'source')) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `source` VARCHAR(50) NOT NULL DEFAULT 'inspection'");
        }
        if (Schema::hasColumn($table, 'kind')) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `kind` VARCHAR(50) NOT NULL DEFAULT 'other'");
        }
    }

    public function down(): void
    {
        // Intentionally no-op: reverting column types could break rows with long values.
    }
};
