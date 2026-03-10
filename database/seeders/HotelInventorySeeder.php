<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryCategory;
use App\Models\InventoryUom;
use App\Models\InventoryTax;
use App\Models\InventoryItem;

class HotelInventorySeeder extends Seeder
{
    public function run(): void
    {
        // ─── 1. UOMs ───────────────────────────────────────────────────────
        $uoms = [
            ['name' => 'Bottle',    'short_name' => 'Btl'],
            ['name' => 'Case',      'short_name' => 'Cse'],
            ['name' => 'Kg',        'short_name' => 'Kg'],
            ['name' => 'Gram',      'short_name' => 'Gm'],
            ['name' => 'Litre',     'short_name' => 'Ltr'],
            ['name' => 'Millilitre','short_name' => 'Ml'],
            ['name' => 'Piece',     'short_name' => 'Pcs'],
            ['name' => 'Dozen',     'short_name' => 'Doz'],
            ['name' => 'Packet',    'short_name' => 'Pkt'],
            ['name' => 'Roll',      'short_name' => 'Rol'],
            ['name' => 'Pair',      'short_name' => 'Pr'],
            ['name' => 'Set',       'short_name' => 'Set'],
            ['name' => 'Tin',       'short_name' => 'Tin'],
            ['name' => 'Sack',      'short_name' => 'Sck'],
            ['name' => 'Box',       'short_name' => 'Box'],
        ];
        $uomMap = [];
        foreach ($uoms as $u) {
            $uomMap[$u['short_name']] = InventoryUom::firstOrCreate(
                ['short_name' => $u['short_name']],
                $u
            );
        }
        // Fix existing "Bootles" record to "Bottle"
        InventoryUom::where('short_name', 'Btl')->update(['name' => 'Bottle', 'short_name' => 'Btl']);

        // ─── 2. CATEGORIES ─────────────────────────────────────────────────
        $mainCats = [
            'F&B'           => 'Food & Beverage — kitchen consumables, beverages and spirits',
            'Housekeeping'  => 'Rooms, laundry, and cleaning supplies',
            'Engineering'   => 'Maintenance, electrical and plumbing items',
            'Front Office'  => 'Stationery, printing and guest amenity items',
            'Laundry'       => 'Laundry chemicals and linen items',
        ];
        $mainMap = [];
        foreach ($mainCats as $name => $desc) {
            $mainMap[$name] = InventoryCategory::firstOrCreate(
                ['name' => $name, 'parent_id' => null],
                ['description' => $desc]
            );
        }

        // Sub-categories
        $subDefs = [
            'F&B' => [
                'Spirits & Wines'  => 'Liquor, beer, wine',
                'Dairy & Eggs'     => 'Milk, cream, butter, eggs',
                'Dry Provisions'   => 'Rice, flour, sugar, spices',
                'Oils & Fats'      => 'Cooking oils, ghee, butter',
                'Soft Drinks'      => 'Aerated drinks, juices, water',
                'Meat & Seafood'   => 'Chicken, mutton, fish',
                'Vegetables'       => 'Fresh vegetables',
                'Coffee & Tea'     => 'Coffee beans, tea bags',
                'Bakery'           => 'Bread, pastry items',
            ],
            'Housekeeping' => [
                'Guest Amenities'  => 'Soap, shampoo, mini toiletries',
                'Cleaning Chemicals' => 'Detergents, disinfectants',
                'Linen'            => 'Bed sheets, pillowcases, towels',
                'Paper Products'   => 'Tissue, toilet rolls, tissues',
            ],
            'Engineering' => [
                'Electrical'       => 'Bulbs, switches, cables',
                'Plumbing'         => 'Pipes, fittings, taps',
                'Tools'            => 'Hand tools and consumables',
            ],
            'Front Office' => [
                'Stationery'       => 'Pens, paper, folders',
                'Printing'         => 'Ink cartridges, paper reams',
                'Guest Gifts'      => 'Welcome goodies and gifts',
            ],
            'Laundry' => [
                'Laundry Chemicals' => 'Detergents and fabric softeners',
                'Laundry Linen'     => 'Laundry linen items',
            ],
        ];
        $subMap = [];
        foreach ($subDefs as $main => $subs) {
            foreach ($subs as $subName => $subDesc) {
                $subMap[$subName] = InventoryCategory::firstOrCreate(
                    ['name' => $subName, 'parent_id' => $mainMap[$main]->id],
                    ['description' => $subDesc]
                );
            }
        }

        // ─── 3. TAX IDs ────────────────────────────────────────────────────
        $gst5  = InventoryTax::where('name', 'GST 5% (Local)')->first();
        $gst12 = InventoryTax::where('name', 'GST 12% (Local)')->first();
        $gst18 = InventoryTax::where('name', 'GST 18% (Local)')->first();
        $vaT   = InventoryTax::where('name', 'Liquor VAT')->first();

        // ─── 4. ITEMS ───────────────────────────────────────────────────────
        // Helper: [name, sku, category, purchase_uom, issue_uom, conv, cost, reorder, tax_id]
        $items = [

            // ── Spirits & Wines ──
            ['Royal Stag Whisky 1L',       'FB-SP-RS1L',  'Spirits & Wines',   'Cse', 'Btl', 12,  1450, 24, $vaT?->id],
            ['Black Label Whisky 750ml',   'FB-SP-BL75',  'Spirits & Wines',   'Cse', 'Btl', 12,  3200, 12, $vaT?->id],
            ['Kingfisher Beer 650ml',      'FB-SP-KF65',  'Spirits & Wines',   'Cse', 'Btl', 24,   120, 48, $vaT?->id],
            ['Old Monk Rum 750ml',         'FB-SP-OM75',  'Spirits & Wines',   'Cse', 'Btl', 12,   280, 24, $vaT?->id],
            ['Sula Brut Sparkling 750ml',  'FB-SP-SB75',  'Spirits & Wines',   'Cse', 'Btl', 12,   750, 12, $vaT?->id],
            ['Breezer Cranberry 275ml',    'FB-SP-BC27',  'Spirits & Wines',   'Cse', 'Btl', 24,    95, 24, $vaT?->id],

            // ── Soft Drinks ──
            ['Pepsi 600ml PET',            'FB-SD-PP60',  'Soft Drinks',       'Cse', 'Btl', 24,    22, 48, $gst12?->id],
            ['Sprite 600ml PET',           'FB-SD-SP60',  'Soft Drinks',       'Cse', 'Btl', 24,    22, 48, $gst12?->id],
            ['Mineral Water 1L',           'FB-SD-MW1L',  'Soft Drinks',       'Cse', 'Btl', 12,    14, 60, $gst12?->id],
            ['Tropicana Orange 1L',        'FB-SD-TJ1L',  'Soft Drinks',       'Cse', 'Btl', 12,    65, 24, $gst12?->id],
            ['Red Bull 250ml',             'FB-SD-RB25',  'Soft Drinks',       'Box', 'Btl', 24,   105, 24, $gst12?->id],

            // ── Dairy & Eggs ──
            ['Full Cream Milk 1L',         'FB-DE-FM1L',  'Dairy & Eggs',      'Ltr', 'Ltr',  1,    60,  5, $gst5?->id],
            ['Fresh Cream 200ml',          'FB-DE-FC20',  'Dairy & Eggs',      'Pcs', 'Pcs',  1,    45, 20, $gst5?->id],
            ['Amul Butter 500g',           'FB-DE-AB50',  'Dairy & Eggs',      'Pcs', 'Pcs',  1,   260, 10, $gst12?->id],
            ['Eggs (Tray of 30)',          'FB-DE-EG30',  'Dairy & Eggs',      'Pcs', 'Pcs',  1,   195, 10, $gst5?->id],
            ['Cheddar Cheese 1kg',         'FB-DE-CC1K',  'Dairy & Eggs',      'Kg',  'Gm', 1000,  620,  5, $gst12?->id],

            // ── Dry Provisions ──
            ['Basmati Rice 25kg',          'FB-DP-BR25',  'Dry Provisions',    'Sck', 'Kg',   25, 1800,  5, $gst5?->id],
            ['Wheat Flour (Maida) 25kg',   'FB-DP-WF25',  'Dry Provisions',    'Sck', 'Kg',   25,  950,  5, $gst5?->id],
            ['Sugar 50kg',                 'FB-DP-SG50',  'Dry Provisions',    'Sck', 'Kg',   50, 1800,  5, $gst5?->id],
            ['Table Salt 1kg',             'FB-DP-TS1K',  'Dry Provisions',    'Pkt', 'Pkt',   1,   20, 15, $gst5?->id],
            ['Turmeric Powder 1kg',        'FB-DP-TP1K',  'Dry Provisions',    'Kg',  'Gm', 1000,  180, 10, $gst5?->id],
            ['Red Chilli Powder 1kg',      'FB-DP-RC1K',  'Dry Provisions',    'Kg',  'Gm', 1000,  200, 10, $gst5?->id],
            ['Garam Masala 500g',          'FB-DP-GM50',  'Dry Provisions',    'Pkt', 'Gm',  500,  160,  5, $gst5?->id],

            // ── Oils & Fats ──
            ['Sunflower Oil 15L',          'FB-OF-SO15',  'Oils & Fats',       'Tin', 'Ltr',  15, 1800,  3, $gst5?->id],
            ['Desi Ghee 15kg',             'FB-OF-DG15',  'Oils & Fats',       'Tin', 'Kg',   15, 7500,  2, $gst12?->id],
            ['Olive Oil 1L',               'FB-OF-OO1L',  'Oils & Fats',       'Btl', 'Btl',   1,  750,  6, $gst12?->id],

            // ── Coffee & Tea ──
            ['Nescafe Gold 200g',          'FB-CT-NG20',  'Coffee & Tea',      'Pcs', 'Gm',  200,  540, 10, $gst18?->id],
            ['Tata Tea Premium 500g',      'FB-CT-TT50',  'Coffee & Tea',      'Pkt', 'Gm',  500,  130, 15, $gst5?->id],
            ['Green Tea Bags 100s',        'FB-CT-GT10',  'Coffee & Tea',      'Box', 'Pcs', 100,  280, 10, $gst5?->id],

            // ── Guest Amenities ──
            ['Shampoo Sachet 10ml',        'HK-GA-SH10',  'Guest Amenities',   'Box', 'Pcs', 100,  320, 20, $gst18?->id],
            ['Soap Bar 25g',               'HK-GA-SB25',  'Guest Amenities',   'Box', 'Pcs', 100,  220, 30, $gst18?->id],
            ['Body Lotion 30ml',           'HK-GA-BL30',  'Guest Amenities',   'Box', 'Pcs', 100,  480, 20, $gst18?->id],
            ['Shower Gel 30ml',            'HK-GA-SG30',  'Guest Amenities',   'Box', 'Pcs', 100,  420, 20, $gst18?->id],
            ['Dental Kit',                 'HK-GA-DK01',  'Guest Amenities',   'Box', 'Pcs', 100,  290, 30, $gst18?->id],
            ['Shower Cap',                 'HK-GA-SC01',  'Guest Amenities',   'Box', 'Pcs', 100,   85, 50, $gst18?->id],
            ['Sewing Kit',                 'HK-GA-SK01',  'Guest Amenities',   'Box', 'Pcs', 100,  120, 20, $gst18?->id],

            // ── Cleaning Chemicals ──
            ['Floor Cleaner 5L',           'HK-CC-FC5L',  'Cleaning Chemicals','Btl', 'Ltr',   5,  380,  6, $gst18?->id],
            ['Glass Cleaner 5L',           'HK-CC-GC5L',  'Cleaning Chemicals','Btl', 'Ltr',   5,  360,  6, $gst18?->id],
            ['Toilet Bowl Cleaner 1L',     'HK-CC-TB1L',  'Cleaning Chemicals','Btl', 'Btl',   1,  140, 12, $gst18?->id],
            ['Disinfectant Spray 500ml',   'HK-CC-DS50',  'Cleaning Chemicals','Btl', 'Btl',   1,  210, 15, $gst18?->id],
            ['Hand Sanitizer 500ml',       'HK-CC-HS50',  'Cleaning Chemicals','Btl', 'Btl',   1,  180, 20, $gst12?->id],

            // ── Linen ──
            ['King Bed Sheet (Pair)',       'HK-LN-KBS1',  'Linen',             'Set', 'Set',   1, 1200,  5, $gst5?->id],
            ['Pillow Cover (Pair)',         'HK-LN-PC01',  'Linen',             'Pr',  'Pcs',   2,  380,  8, $gst5?->id],
            ['Bath Towel',                 'HK-LN-BT01',  'Linen',             'Pcs', 'Pcs',   1,  320, 10, $gst5?->id],
            ['Hand Towel',                 'HK-LN-HT01',  'Linen',             'Pcs', 'Pcs',   1,  160, 15, $gst5?->id],
            ['Face Towel',                 'HK-LN-FT01',  'Linen',             'Pcs', 'Pcs',   1,  120, 15, $gst5?->id],
            ['Bath Robe',                  'HK-LN-BR01',  'Linen',             'Pcs', 'Pcs',   1,  850,  5, $gst5?->id],

            // ── Paper Products ──
            ['Toilet Roll (Jumbo)',        'HK-PP-TR01',  'Paper Products',    'Box', 'Rol',   6,  480, 20, $gst12?->id],
            ['Facial Tissue Box 200s',     'HK-PP-FT01',  'Paper Products',    'Box', 'Box',   1,   85, 20, $gst12?->id],
            ['Kitchen Paper Roll',         'HK-PP-KP01',  'Paper Products',    'Box', 'Rol',   6,  240, 10, $gst12?->id],

            // ── Stationery ──
            ['Ballpoint Pen (Box of 12)',   'FO-ST-BP12',  'Stationery',        'Box', 'Pcs',  12,   95, 10, $gst12?->id],
            ['A4 Paper 500 Sheets',        'FO-ST-A4R1',  'Stationery',        'Box', 'Pkt',  10,  340, 10, $gst12?->id],
            ['Stapler Pins (Box)',          'FO-ST-SP01',  'Stationery',        'Box', 'Box',   1,   45, 10, $gst12?->id],

            // ── Electrical ──
            ['LED Bulb 9W (Box of 10)',    'EN-EL-LB9W',  'Electrical',        'Box', 'Pcs',  10,  580, 10, $gst12?->id],
            ['LED Tube 20W',               'EN-EL-LT20',  'Electrical',        'Box', 'Pcs',   5,  480,  5, $gst12?->id],
            ['Extension Cord 5m',          'EN-EL-EC5M',  'Electrical',        'Pcs', 'Pcs',   1,  350,  3, $gst18?->id],

            // ── Plumbing ──
            ['Flush Valve',                'EN-PL-FV01',  'Plumbing',          'Pcs', 'Pcs',   1,  280,  3, $gst18?->id],
            ['Tap Washer Set',             'EN-PL-TW01',  'Plumbing',          'Set', 'Set',   1,   45, 10, $gst18?->id],

            // ── Laundry Chemicals ──
            ['Detergent Powder 25kg',      'LN-LC-DP25',  'Laundry Chemicals', 'Sck', 'Kg',   25, 1650,  5, $gst18?->id],
            ['Fabric Softener 5L',         'LN-LC-FS5L',  'Laundry Chemicals', 'Btl', 'Ltr',   5,  620,  3, $gst18?->id],
            ['Bleach Solution 5L',         'LN-LC-BS5L',  'Laundry Chemicals', 'Btl', 'Ltr',   5,  280,  3, $gst18?->id],
        ];

        foreach ($items as [$name, $sku, $catName, $purUom, $issUom, $conv, $cost, $reorder, $taxId]) {
            $cat = $subMap[$catName] ?? $mainMap[$catName] ?? null;
            if (!$cat) continue;

            $pUom = $uomMap[$purUom] ?? null;
            $iUom = $uomMap[$issUom] ?? null;
            if (!$pUom || !$iUom) continue;

            InventoryItem::updateOrCreate(
                ['sku' => $sku],
                [
                    'name'              => $name,
                    'sku'               => $sku,
                    'category_id'       => $cat->id,
                    'purchase_uom_id'   => $pUom->id,
                    'issue_uom_id'      => $iUom->id,
                    'conversion_factor' => $conv,
                    'cost_price'        => $cost,
                    'reorder_level'     => $reorder,
                    'current_stock'     => rand($reorder - 5, $reorder * 3),
                    'tax_id'            => $taxId,
                ]
            );
        }

        $this->command->info('✅ Hotel inventory catalog seeded — ' . count($items) . ' items across ' . count($subMap) . ' sub-categories.');
    }
}
