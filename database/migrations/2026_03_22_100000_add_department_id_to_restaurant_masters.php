<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('is_active')->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });
    }
};
