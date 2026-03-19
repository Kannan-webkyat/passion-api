<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->text('address')->nullable()->after('description');
            $table->string('email')->nullable()->after('address');
            $table->string('phone')->nullable()->after('email');
            $table->string('gstin')->nullable()->after('phone');
            $table->string('fssai')->nullable()->after('gstin');
            $table->string('logo_path')->nullable()->after('fssai');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->dropColumn(['address', 'email', 'phone', 'gstin', 'fssai', 'logo_path']);
        });
    }
};
