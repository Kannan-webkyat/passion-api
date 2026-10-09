<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('housekeeping_jobs') && ! Schema::hasColumn('housekeeping_jobs', 'approved_by')) {
            Schema::table('housekeeping_jobs', function (Blueprint $table) {
                $table->timestamp('finished_at')->nullable()->after('finished_by');
                $table->foreignId('approved_by')->nullable()->after('finished_at')->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('housekeeping_jobs') && Schema::hasColumn('housekeeping_jobs', 'approved_by')) {
            Schema::table('housekeeping_jobs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('approved_by');
                $table->dropColumn(['finished_at', 'approved_at']);
            });
        }
    }
};
