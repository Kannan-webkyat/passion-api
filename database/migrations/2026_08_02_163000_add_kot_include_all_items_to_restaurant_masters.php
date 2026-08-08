<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->boolean('kot_include_all_items')->default(false)->after('auto_print_payment_receipt');
        });

        // Bars: every cart line (incl. bottled beer / direct sale) goes on BOT.
        DB::table('restaurant_masters')
            ->where(function ($q) {
                $q->where('name', 'like', '%bar%')
                    ->orWhere('name', 'like', '%brews%')
                    ->orWhere('name', 'like', '%bubbles%');
            })
            ->update(['kot_include_all_items' => true]);
    }

    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->dropColumn('kot_include_all_items');
        });
    }
};
