<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['5200', 'Inventory Wastage & Shrinkage', 'expense'],
            ['5210', 'Staff Meals — Inventory', 'expense'],
        ];

        foreach ($rows as [$code, $name, $type]) {
            DB::table('chart_of_accounts')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'parent_code' => null,
                    'is_posting' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->whereIn('code', ['5200', '5210'])->delete();
    }
};
