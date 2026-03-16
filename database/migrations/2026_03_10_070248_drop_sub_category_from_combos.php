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
        if (Schema::hasColumn('combos', 'menu_sub_category_id')) {
            try {
                Schema::table('combos', function (Blueprint $table) {
                    $table->dropForeign(['menu_sub_category_id']);
                });
            } catch (\Exception $e) {
                // Foreign key might not exist, ignore
            }

            Schema::table('combos', function (Blueprint $table) {
                $table->dropColumn('menu_sub_category_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('combos', 'menu_sub_category_id')) {
            Schema::table('combos', function (Blueprint $table) {
                $table->foreignId('menu_sub_category_id')->nullable()->constrained('menu_sub_categories')->onDelete('set null');
            });
        }
    }
};
