<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portal_notification_reads') && ! Schema::hasColumn('portal_notification_reads', 'cleared_at')) {
            Schema::table('portal_notification_reads', function (Blueprint $table) {
                $table->timestamp('cleared_at')->nullable()->after('read_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portal_notification_reads') && Schema::hasColumn('portal_notification_reads', 'cleared_at')) {
            Schema::table('portal_notification_reads', function (Blueprint $table) {
                $table->dropColumn('cleared_at');
            });
        }
    }
};
