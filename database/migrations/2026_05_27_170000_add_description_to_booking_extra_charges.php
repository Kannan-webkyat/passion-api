<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_extra_charges')) {
            return;
        }

        if (! Schema::hasColumn('booking_extra_charges', 'description')) {
            Schema::table('booking_extra_charges', function (Blueprint $table) {
                $table->string('description', 500)->nullable()->after('label');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_extra_charges')) {
            return;
        }

        if (Schema::hasColumn('booking_extra_charges', 'description')) {
            Schema::table('booking_extra_charges', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
