<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->decimal('breakfast_price', 10, 2)->default(0)->after('base_price');
            $table->decimal('child_breakfast_price', 10, 2)->default(0)->after('breakfast_price');
            $table->foreignId('tax_id')->nullable()->after('child_sharing_limit')->constrained('inventory_taxes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn(['breakfast_price', 'child_breakfast_price', 'tax_id']);
        });
    }
};
