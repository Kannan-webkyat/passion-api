<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill packing totals on existing day-close rows from settled orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_day_closings') || ! Schema::hasColumn('pos_day_closings', 'total_packing_charge')) {
            return;
        }
        if (! Schema::hasColumn('pos_orders', 'packing_charge')) {
            return;
        }

        $closings = DB::table('pos_day_closings')->select('id', 'restaurant_id', 'closed_date')->get();

        foreach ($closings as $c) {
            $closedDate = $c->closed_date instanceof \DateTimeInterface
                ? $c->closed_date->format('Y-m-d')
                : (string) $c->closed_date;

            $total = (float) DB::table('pos_orders')
                ->where('restaurant_id', $c->restaurant_id)
                ->whereIn('status', ['paid', 'refunded'])
                ->where(function ($q) use ($closedDate) {
                    $q->whereDate('business_date', $closedDate)
                        ->orWhere(function ($legacy) use ($closedDate) {
                            $legacy->whereNull('business_date')
                                ->whereDate('closed_at', $closedDate);
                        });
                })
                ->sum(DB::raw('COALESCE(packing_charge, 0)'));

            DB::table('pos_day_closings')
                ->where('id', $c->id)
                ->update(['total_packing_charge' => round($total, 2)]);
        }
    }

    public function down(): void
    {
        // Intentionally empty — historical backfill is not reversed.
    }
};
