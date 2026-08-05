<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['1110', 'Cash on Hand — POS', 'asset'],
            ['1120', 'Bank — Card Clearing', 'asset'],
            ['1121', 'Bank — UPI Clearing', 'asset'],
            ['1130', 'Guest Folio Receivable', 'asset'],
            ['1310', 'Inventory — Food & Supplies', 'asset'],
            ['1311', 'Inventory — Liquor', 'asset'],
            ['1360', 'Deferred Procurement Charges', 'asset'],
            ['1420', 'Input GST Recoverable', 'asset'],
            ['1421', 'Input VAT Recoverable', 'asset'],
            ['2110', 'GRNI — Goods Received Not Invoiced', 'liability'],
            ['2120', 'Accounts Payable — Vendors', 'liability'],
            ['2210', 'Output CGST Payable', 'liability'],
            ['2211', 'Output SGST Payable', 'liability'],
            ['2212', 'Output IGST Payable', 'liability'],
            ['2213', 'Output VAT Payable — Liquor', 'liability'],
            ['2310', 'Tips Payable to Staff', 'liability'],
            ['4100', 'Room Revenue', 'income'],
            ['4210', 'Restaurant Sales — Taxable (GST)', 'income'],
            ['4220', 'Bar Sales — Taxable (VAT)', 'income'],
            ['4300', 'Service Charge Income', 'income'],
            ['4310', 'Delivery Charge Income', 'income'],
            ['4900', 'Sales Discounts', 'contra_income'],
            ['5100', 'COGS — Restaurant', 'cogs'],
            ['5110', 'COGS — Bar / Liquor', 'cogs'],
            ['6100', 'General Operating Expenses', 'expense'],
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
        DB::table('chart_of_accounts')->whereIn('code', [
            '1110', '1120', '1121', '1130', '1310', '1311', '1360', '1420', '1421',
            '2110', '2120', '2210', '2211', '2212', '2213', '2310',
            '4100', '4210', '4220', '4300', '4310', '4900', '5100', '5110', '6100',
        ])->delete();
    }
};
