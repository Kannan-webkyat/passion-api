<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryCategory;
use App\Models\Vendor;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Categories
        $cats = [
            'Linens' => 'Bedsheets, towels, and pillow covers',
            'Toiletries' => 'Soaps, shampoos, and dental kits',
            'Cleaning' => 'Chemicals and cleaning equipment',
            'F&B Supplies' => 'Napkins, cutlery, and kitchen disposables',
        ];

        foreach ($cats as $name => $desc) {
            InventoryCategory::firstOrCreate(['name' => $name], ['description' => $desc]);
        }

        // 2. Create Vendors
        $vendors = [
            [
                'name' => 'Global Linens Ltd',
                'contact_person' => 'John Doe',
                'phone' => '9876543210',
                'email' => 'sales@globallinens.com',
                'address' => 'Industrial Area, Phase 1, New Delhi'
            ],
            [
                'name' => 'Pure Care Supplies',
                'contact_person' => 'Sarah Smith',
                'phone' => '9123456780',
                'email' => 'info@purecare.in',
                'address' => 'SEZ Mall, Bangalore'
            ]
        ];

        foreach ($vendors as $v) {
            Vendor::firstOrCreate(['name' => $v['name']], $v);
        }

        // 3. Create Items
        $catLinens = InventoryCategory::where('name', 'Linens')->first();
        $catToiletries = InventoryCategory::where('name', 'Toiletries')->first();
        $vendor1 = Vendor::first();

        $items = [
            [
                'name' => 'Single Bed Sheet',
                'sku' => 'LIN-BS-S',
                'category_id' => $catLinens->id,
                'vendor_id' => $vendor1->id,
                'unit_of_measure' => 'pcs',
                'cost_price' => 450.00,
                'reorder_level' => 20,
                'current_stock' => 50,
            ],
            [
                'name' => 'Bath Towel (White)',
                'sku' => 'LIN-TW-B',
                'category_id' => $catLinens->id,
                'vendor_id' => $vendor1->id,
                'unit_of_measure' => 'pcs',
                'cost_price' => 280.00,
                'reorder_level' => 30,
                'current_stock' => 15, // Low stock!
            ],
            [
                'name' => 'Conditioner Bottle 30ml',
                'sku' => 'TOI-CON-30',
                'category_id' => $catToiletries->id,
                'vendor_id' => 2,
                'unit_of_measure' => 'bottles',
                'cost_price' => 12.50,
                'reorder_level' => 100,
                'current_stock' => 500,
            ],
        ];

        foreach ($items as $itemData) {
            $item = InventoryItem::updateOrCreate(['sku' => $itemData['sku']], $itemData);
            
            // Add an initial stock-in transaction if none exists
            InventoryTransaction::firstOrCreate(
                [
                    'inventory_item_id' => $item->id,
                    'reason' => 'Initial Seeding',
                ],
                [
                    'type' => 'in',
                    'quantity' => $itemData['current_stock'],
                    'notes' => 'Bulk opening balance',
                ]
            );
        }
    }
}
