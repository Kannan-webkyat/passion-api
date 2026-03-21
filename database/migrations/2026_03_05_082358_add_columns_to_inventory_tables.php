<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_categories', 'name')) {
                $table->string('name')->unique()->after('id');
            }
            if (! Schema::hasColumn('inventory_categories', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
        });
    }
};
