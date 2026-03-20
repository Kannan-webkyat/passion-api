<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_locations')
            ->where('name', 'Kitchen Store')
            ->update(['type' => 'kitchen_store']);
    }

    public function down(): void
    {
        DB::table('inventory_locations')
            ->where('name', 'Kitchen Store')
            ->update(['type' => 'sub_store']);
    }
};
