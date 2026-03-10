<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('inventory_taxes', function (Blueprint $table) {
            $table->string('type')->default('local'); // local, inter-state, vat
        });
    }

    public function down()
    {
        Schema::table('inventory_taxes', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
