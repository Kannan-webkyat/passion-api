<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->boolean('bot_sent')->default(false)->after('kot_sent_at');
            $table->timestamp('bot_sent_at')->nullable()->after('bot_sent');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropColumn(['bot_sent', 'bot_sent_at']);
        });
    }
};
