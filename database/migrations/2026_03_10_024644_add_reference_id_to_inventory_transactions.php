<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            // A shared UUID to group paired IN+OUT transactions (e.g., a requisition issue)
            $table->uuid('reference_id')->nullable()->after('notes')->index();
            // Optional: type of reference for context (requisition, purchase_order, etc.)
            $table->string('reference_type')->nullable()->after('reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex(['reference_id']);
            $table->dropColumn(['reference_id', 'reference_type']);
        });
    }
};
