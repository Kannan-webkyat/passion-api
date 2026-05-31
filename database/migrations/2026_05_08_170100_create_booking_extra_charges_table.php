<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_extra_charges')) {
            Schema::create('booking_extra_charges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
                $table->string('source', 50)->default('inspection'); // inspection | other
                $table->string('kind', 50)->default('other');        // minibar | asset_penalty | other
                $table->string('label', 255);
                $table->decimal('qty', 10, 2)->default(1);
                $table->decimal('unit_amount', 10, 2)->default(0);
                $table->decimal('total_amount', 10, 2)->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['booking_id', 'source', 'kind']);
            });

            return;
        }

        // Legacy: table existed without full schema — add missing columns (see also
        // 2026_05_12_121500_add_kind_and_columns_to_booking_extra_charges).
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
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extra_charges');
    }
};
