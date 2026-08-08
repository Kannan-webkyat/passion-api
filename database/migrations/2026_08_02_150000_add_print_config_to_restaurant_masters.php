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
            $table->boolean('auto_print_kot')->default(true)->after('receipt_show_tax_breakdown');
            $table->string('kot_ticket_label', 16)->default('KOT')->after('auto_print_kot');
            $table->boolean('auto_print_payment_receipt')->default(false)->after('kot_ticket_label');
        });

        // Bar outlets: label tickets as BOT (Champions / Brews / Premium / Bar in name).
        DB::table('restaurant_masters')
            ->where(function ($q) {
                $q->where('name', 'like', '%bar%')
                    ->orWhere('name', 'like', '%brews%')
                    ->orWhere('name', 'like', '%bubbles%');
            })
            ->update([
                'kot_ticket_label' => 'BOT',
                'auto_print_kot' => true,
                'auto_print_payment_receipt' => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->dropColumn(['auto_print_kot', 'kot_ticket_label', 'auto_print_payment_receipt']);
        });
    }
};
