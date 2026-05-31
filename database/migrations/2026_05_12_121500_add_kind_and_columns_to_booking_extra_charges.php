<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original create migration skips entirely when the table already exists
 * (`if (Schema::hasTable(...)) return`), so older DBs can have `booking_extra_charges`
 * without `kind`, `source`, etc. Align schema with BookingExtraCharge / HousekeepingController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_extra_charges')) {
            return;
        }

        Schema::table('booking_extra_charges', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_extra_charges', 'source')) {
                $table->string('source', 50)->default('inspection');
            }
            if (! Schema::hasColumn('booking_extra_charges', 'kind')) {
                $table->string('kind', 50)->default('other');
            }
            if (! Schema::hasColumn('booking_extra_charges', 'label')) {
                $table->string('label', 255)->default('');
            }
            if (! Schema::hasColumn('booking_extra_charges', 'qty')) {
                $table->decimal('qty', 10, 2)->default(1);
            }
            if (! Schema::hasColumn('booking_extra_charges', 'unit_amount')) {
                $table->decimal('unit_amount', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('booking_extra_charges', 'total_amount')) {
                $table->decimal('total_amount', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('booking_extra_charges', 'meta')) {
                $table->json('meta')->nullable();
            }
        });

        if (
            Schema::hasColumn('booking_extra_charges', 'booking_id')
            && Schema::hasColumn('booking_extra_charges', 'source')
            && Schema::hasColumn('booking_extra_charges', 'kind')
            && Schema::hasIndex('booking_extra_charges', ['booking_id', 'source', 'kind']) === false
        ) {
            Schema::table('booking_extra_charges', function (Blueprint $table) {
                $table->index(['booking_id', 'source', 'kind']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_extra_charges')) {
            return;
        }

        if (
            Schema::hasColumn('booking_extra_charges', 'source')
            && Schema::hasColumn('booking_extra_charges', 'kind')
        ) {
            try {
                Schema::table('booking_extra_charges', function (Blueprint $table) {
                    $table->dropIndex(['booking_id', 'source', 'kind']);
                });
            } catch (\Throwable) {
                /* index may not exist or name differs */
            }
        }

        Schema::table('booking_extra_charges', function (Blueprint $table) {
            foreach (['meta', 'total_amount', 'unit_amount', 'qty', 'label', 'kind', 'source'] as $col) {
                if (Schema::hasColumn('booking_extra_charges', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
