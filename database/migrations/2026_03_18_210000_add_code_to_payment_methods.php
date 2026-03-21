<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
        });

        // Map existing methods to POS codes (backend expects: cash, card, upi, room_charge)
        $mappings = [
            ['Cash Payment', 'cash'],
            ['Company Card', 'card'],
            ['UPI / GPay / PayTM', 'upi'],
        ];
        foreach ($mappings as [$name, $code]) {
            DB::table('payment_methods')->where('name', $name)->update(['code' => $code]);
        }

        // Add Room Charge if not exists (for room service)
        if (! DB::table('payment_methods')->where('name', 'Room Charge')->exists()) {
            DB::table('payment_methods')->insert([
                'name' => 'Room Charge',
                'code' => 'room_charge',
                'is_active' => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('payment_methods')->where('name', 'Room Charge')->update(['code' => 'room_charge']);
        }
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
