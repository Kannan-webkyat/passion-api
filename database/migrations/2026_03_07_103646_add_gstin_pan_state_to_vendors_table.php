<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('gstin', 15)->nullable()->after('address');
            $table->string('pan', 10)->nullable()->after('gstin');
            $table->string('state')->nullable()->after('pan');
            $table->boolean('is_registered_dealer')->default(true)->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['gstin', 'pan', 'state', 'is_registered_dealer']);
        });
    }
};
