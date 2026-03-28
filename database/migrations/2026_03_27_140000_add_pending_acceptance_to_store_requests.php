<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_request_items', function (Blueprint $table) {
            $table->decimal('quantity_pending_acceptance', 15, 2)->default(0)->after('quantity_issued');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE store_requests MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'issued',
                'partially_issued',
                'rejected',
                'cancelled',
                'awaiting_acceptance'
            ) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("UPDATE store_requests SET status = 'approved' WHERE status = 'awaiting_acceptance'");

            DB::statement("ALTER TABLE store_requests MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'issued',
                'partially_issued',
                'rejected',
                'cancelled'
            ) NOT NULL DEFAULT 'pending'");
        }

        Schema::table('store_request_items', function (Blueprint $table) {
            $table->dropColumn('quantity_pending_acceptance');
        });
    }
};
