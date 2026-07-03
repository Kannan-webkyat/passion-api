<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_logs')) {
            return;
        }

        Schema::table('production_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('production_logs', 'business_date')) {
                $table->date('business_date')->nullable()->after('production_date');
                $table->index('business_date');
            }
        });

        DB::table('production_logs')
            ->whereNull('business_date')
            ->update([
                'business_date' => DB::raw('DATE(production_date)'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('production_logs')) {
            return;
        }

        Schema::table('production_logs', function (Blueprint $table) {
            if (Schema::hasColumn('production_logs', 'business_date')) {
                $table->dropIndex(['business_date']);
                $table->dropColumn('business_date');
            }
        });
    }
};
