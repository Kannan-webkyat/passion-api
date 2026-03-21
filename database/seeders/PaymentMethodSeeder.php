<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Ensure POS payment methods exist with correct codes (cash, card, upi, room_charge).
     */
    public function run(): void
    {
        $methods = [
            ['name' => 'Cash', 'code' => 'cash', 'is_default' => true],
            ['name' => 'Card', 'code' => 'card', 'is_default' => false],
            ['name' => 'UPI', 'code' => 'upi', 'is_default' => false],
            ['name' => 'Room Charge', 'code' => 'room_charge', 'is_default' => false],
        ];

        foreach ($methods as $m) {
            $existing = PaymentMethod::where('code', $m['code'])->first();
            if ($existing) {
                $existing->update(['is_active' => true, 'is_default' => $m['is_default']]);
            } else {
                PaymentMethod::firstOrCreate(
                    ['code' => $m['code']],
                    ['name' => $m['name'], 'is_active' => true, 'is_default' => $m['is_default']]
                );
            }
        }

        if (PaymentMethod::where('is_default', true)->count() > 1) {
            PaymentMethod::where('code', '!=', 'cash')->update(['is_default' => false]);
            PaymentMethod::where('code', 'cash')->update(['is_default' => true]);
        }
    }
}
