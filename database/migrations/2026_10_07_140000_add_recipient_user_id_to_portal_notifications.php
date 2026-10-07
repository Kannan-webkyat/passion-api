<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_notifications') || Schema::hasColumn('portal_notifications', 'recipient_user_id')) {
            return;
        }

        Schema::table('portal_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('recipient_user_id')->nullable()->after('audience');
            $table->index('recipient_user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portal_notifications') || ! Schema::hasColumn('portal_notifications', 'recipient_user_id')) {
            return;
        }

        Schema::table('portal_notifications', function (Blueprint $table) {
            $table->dropIndex(['recipient_user_id']);
            $table->dropColumn('recipient_user_id');
        });
    }
};
