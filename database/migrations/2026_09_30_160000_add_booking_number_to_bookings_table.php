<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'booking_number')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('booking_number', 32)->nullable()->unique()->after('id');
            });
        }

        DB::table('bookings')
            ->whereNull('booking_number')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('bookings')
                        ->where('id', $row->id)
                        ->update(['booking_number' => 'RES-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'booking_number')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique(['booking_number']);
                $table->dropColumn('booking_number');
            });
        }
    }
};
