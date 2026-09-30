<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'invoice_seq')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unsignedInteger('invoice_seq')->nullable()->unique()->after('booking_number');
                $table->string('invoice_number', 32)->nullable()->after('invoice_seq');
                $table->timestamp('invoice_issued_at')->nullable()->after('invoice_number');
            });
        }

        // Already checked-out stays get numbers in departure order; issue time = last update (the checkout).
        $prefix = trim((string) (DB::table('settings')->where('key', 'invoice_prefix')->value('value') ?? '')) ?: 'INV';
        $seq = (int) DB::table('bookings')->max('invoice_seq');
        DB::table('bookings')
            ->where('status', 'checked_out')
            ->whereNull('invoice_seq')
            ->orderByRaw('COALESCE(check_out_at, check_out)')
            ->orderBy('id')
            ->get(['id', 'updated_at', 'check_out_at'])
            ->each(function ($row) use (&$seq, $prefix) {
                $seq++;
                DB::table('bookings')->where('id', $row->id)->update([
                    'invoice_seq' => $seq,
                    'invoice_number' => $prefix.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                    'invoice_issued_at' => $row->updated_at ?? $row->check_out_at,
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'invoice_seq')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique(['invoice_seq']);
                $table->dropColumn(['invoice_seq', 'invoice_number', 'invoice_issued_at']);
            });
        }
    }
};
