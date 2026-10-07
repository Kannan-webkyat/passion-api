<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_notifications') || Schema::hasColumn('portal_notifications', 'audience')) {
            return;
        }

        Schema::table('portal_notifications', function (Blueprint $table) {
            $table->string('audience', 32)->nullable()->after('kind');
            $table->index('audience');
        });

        DB::table('portal_notifications')->whereNull('audience')->update([
            'audience' => 'front_desk',
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('portal_notifications') || ! Schema::hasColumn('portal_notifications', 'audience')) {
            return;
        }

        Schema::table('portal_notifications', function (Blueprint $table) {
            $table->dropIndex(['audience']);
            $table->dropColumn('audience');
        });
    }
};
