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
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('purchase_uom_id')->nullable()->constrained('inventory_uoms')->onDelete('set null');
            $table->foreignId('issue_uom_id')->nullable()->constrained('inventory_uoms')->onDelete('set null');
            $table->decimal('conversion_factor', 10, 2)->default(1.00); // 1 Purchase Unit = X Issue Units
            $table->dropColumn('unit_of_measure');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('unit_of_measure')->after('description')->nullable();
            $table->dropForeign(['purchase_uom_id']);
            $table->dropForeign(['issue_uom_id']);
            $table->dropColumn(['purchase_uom_id', 'issue_uom_id', 'conversion_factor']);
        });
    }
};
