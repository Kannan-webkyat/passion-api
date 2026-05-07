<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE menu_items MODIFY item_code VARCHAR(255) NULL');
    }

    public function down(): void
    {
        foreach (DB::table('menu_items')->whereNull('item_code')->cursor() as $row) {
            DB::table('menu_items')->where('id', $row->id)->update(['item_code' => 'NO-CODE-'.$row->id]);
        }

        DB::statement('ALTER TABLE menu_items MODIFY item_code VARCHAR(255) NOT NULL');
    }
};
