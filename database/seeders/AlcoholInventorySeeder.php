<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryCategory;
use App\Models\InventoryUom;
use App\Models\InventoryTax;
use App\Models\InventoryItem;

class AlcoholInventorySeeder extends Seeder
{
    public function run(): void
    {
        $fnb     = InventoryCategory::where('name', 'F&B')->where('parent_id', null)->first();
        $spirits = InventoryCategory::where('name', 'Spirits & Wines')->first();

        // Create granular sub-categories DIRECTLY under F&B (not under Spirits & Wines)
        // This keeps the 2-level hierarchy the app supports: F&B → IMFL Whisky, Beer, Wine, etc.
        $subCats = [
            'IMFL Whisky'   => 'Indian Made Foreign Liquor — Whisky',
            'Scotch Whisky' => 'Imported Scotch and Blended Whiskies',
            'Rum'           => 'Domestic and imported rum',
            'Vodka'         => 'Domestic and imported vodka',
            'Gin'           => 'Domestic and imported gin',
            'Beer'          => 'Draught and bottled beer',
            'Wine'          => 'Red, white and sparkling wines',
            'Brandy'        => 'Brandy and cognac',
            'Cocktail Mixes'=> 'Ready-to-mix and mixing bases',
        ];

        $catMap = [];
        foreach ($subCats as $name => $desc) {
            $catMap[$name] = InventoryCategory::firstOrCreate(
                ['name' => $name, 'parent_id' => $fnb?->id],   // Direct child of F&B
                ['description' => $desc]
            );
        }

        // UOM shortcuts
        $uom = fn($code) => InventoryUom::where('short_name', $code)->first();

        // Tax shortcuts
        $vat   = InventoryTax::where('name', 'Liquor VAT')->first();
        $gst18 = InventoryTax::where('name', 'GST 18% (Local)')->first();
        $gst5  = InventoryTax::where('name', 'GST 5% (Local)')->first();

        // ─── ITEMS ─────────────────────────────────────────────────────────────
        // [name, sku, category, pUom, iUom, conv, cost_price, reorder, tax]
        $items = [

            // ╔══════╗
            // ║ IMFL WHISKY (most popular category in Indian hotels)
            // ╚══════╝
            ['Royal Stag Whisky 180ml',         'FB-IW-RS18',  'IMFL Whisky',   'Cse', 'Btl', 48,   320, 24, $vat],
            ['Royal Stag Whisky 375ml',         'FB-IW-RS37',  'IMFL Whisky',   'Cse', 'Btl', 24,   580, 24, $vat],
            ['Royal Stag Whisky 750ml',         'FB-IW-RS75',  'IMFL Whisky',   'Cse', 'Btl', 12,  1050, 12, $vat],
            ['Royal Stag Whisky 1L',            'FB-IW-RS1L',  'IMFL Whisky',   'Cse', 'Btl', 12,  1380, 12, $vat],
            ['McDowell\'s No.1 750ml',          'FB-IW-MC75',  'IMFL Whisky',   'Cse', 'Btl', 12,   980, 12, $vat],
            ['McDowell\'s No.1 1L',             'FB-IW-MC1L',  'IMFL Whisky',   'Cse', 'Btl', 12,  1280, 12, $vat],
            ['Officer\'s Choice 750ml',         'FB-IW-OC75',  'IMFL Whisky',   'Cse', 'Btl', 12,   850, 12, $vat],
            ['Imperial Blue 750ml',             'FB-IW-IB75',  'IMFL Whisky',   'Cse', 'Btl', 12,   920, 12, $vat],
            ['Imperial Blue 1L',                'FB-IW-IB1L',  'IMFL Whisky',   'Cse', 'Btl', 12,  1180, 12, $vat],
            ['Blenders Pride 750ml',            'FB-IW-BP75',  'IMFL Whisky',   'Cse', 'Btl', 12,  1950, 12, $vat],
            ['Royal Challenge 750ml',           'FB-IW-RC75',  'IMFL Whisky',   'Cse', 'Btl', 12,  1100, 12, $vat],
            ['Signature Whisky 750ml',          'FB-IW-SG75',  'IMFL Whisky',   'Cse', 'Btl', 12,  1650, 12, $vat],
            ['Antiquity Blue 750ml',            'FB-IW-AB75',  'IMFL Whisky',   'Cse', 'Btl', 12,  2100, 12, $vat],
            ['Paul John Brilliant 750ml',       'FB-IW-PJ75',  'IMFL Whisky',   'Cse', 'Btl', 12,  2800,  6, $vat],

            // ╔══════╗
            // ║ SCOTCH & IMPORTED WHISKY
            // ╚══════╝
            ['Johnnie Walker Black 750ml',      'FB-SW-JWB75', 'Scotch Whisky', 'Cse', 'Btl', 12,  4200,  6, $vat],
            ['Johnnie Walker Red 750ml',        'FB-SW-JWR75', 'Scotch Whisky', 'Cse', 'Btl', 12,  2600,  6, $vat],
            ['Johnnie Walker Double Black 750ml','FB-SW-JWD75','Scotch Whisky', 'Cse', 'Btl', 12,  5800,  6, $vat],
            ['Chivas Regal 12yr 750ml',         'FB-SW-CR75',  'Scotch Whisky', 'Cse', 'Btl', 12,  5500,  6, $vat],
            ['Glenfiddich 12yr 750ml',          'FB-SW-GF75',  'Scotch Whisky', 'Cse', 'Btl', 12,  6500,  3, $vat],
            ['The Glenlivet 12yr 750ml',        'FB-SW-GL75',  'Scotch Whisky', 'Cse', 'Btl', 12,  6200,  3, $vat],
            ['Jack Daniel\'s 750ml',            'FB-SW-JD75',  'Scotch Whisky', 'Cse', 'Btl', 12,  4800,  6, $vat],

            // ╔══════╗
            // ║ RUM
            // ╚══════╝
            ['Old Monk Rum 180ml',              'FB-RM-OM18',  'Rum',           'Cse', 'Btl', 48,    90, 24, $vat],
            ['Old Monk Rum 375ml',              'FB-RM-OM37',  'Rum',           'Cse', 'Btl', 24,   165, 24, $vat],
            ['Old Monk Rum 750ml',              'FB-RM-OM75',  'Rum',           'Cse', 'Btl', 12,   290, 12, $vat],
            ['Bacardi White 750ml',             'FB-RM-BW75',  'Rum',           'Cse', 'Btl', 12,  1050,  6, $vat],
            ['Bacardi Black 750ml',             'FB-RM-BB75',  'Rum',           'Cse', 'Btl', 12,  1050,  6, $vat],
            ['Captain Morgan Spiced 750ml',     'FB-RM-CM75',  'Rum',           'Cse', 'Btl', 12,  1650,  6, $vat],
            ['McDowell\'s Rum 750ml',           'FB-RM-MR75',  'Rum',           'Cse', 'Btl', 12,   780, 12, $vat],
            ['Contessa Rum 750ml',              'FB-RM-CO75',  'Rum',           'Cse', 'Btl', 12,   420, 12, $vat],

            // ╔══════╗
            // ║ VODKA
            // ╚══════╝
            ['Absolut Original 750ml',          'FB-VD-AO75',  'Vodka',         'Cse', 'Btl', 12,  2100,  6, $vat],
            ['Smirnoff Vodka 750ml',            'FB-VD-SM75',  'Vodka',         'Cse', 'Btl', 12,  1550,  6, $vat],
            ['Grey Goose 750ml',                'FB-VD-GG75',  'Vodka',         'Cse', 'Btl', 12,  5200,  3, $vat],
            ['Magic Moments 750ml',             'FB-VD-MM75',  'Vodka',         'Cse', 'Btl', 12,   780, 12, $vat],
            ['White Mischief 750ml',            'FB-VD-WM75',  'Vodka',         'Cse', 'Btl', 12,   680, 12, $vat],
            ['Romanov Vodka 750ml',             'FB-VD-RV75',  'Vodka',         'Cse', 'Btl', 12,   620, 12, $vat],

            // ╔══════╗
            // ║ GIN
            // ╚══════╝
            ['Bombay Sapphire 750ml',           'FB-GN-BS75',  'Gin',           'Cse', 'Btl', 12,  3200,  6, $vat],
            ['Tanqueray London Dry 750ml',      'FB-GN-TQ75',  'Gin',           'Cse', 'Btl', 12,  3400,  6, $vat],
            ['Gordon\'s Gin 750ml',             'FB-GN-GD75',  'Gin',           'Cse', 'Btl', 12,  2200,  6, $vat],
            ['Greater Than London Dry 750ml',   'FB-GN-GT75',  'Gin',           'Cse', 'Btl', 12,  2800,  6, $vat],
            ['Hapusa Himalayan Gin 750ml',      'FB-GN-HH75',  'Gin',           'Cse', 'Btl', 12,  3600,  3, $vat],
            ['Broker\'s Gin 750ml',             'FB-GN-BK75',  'Gin',           'Cse', 'Btl', 12,  1800,  6, $vat],

            // ╔══════╗
            // ║ BEER
            // ╚══════╝
            ['Kingfisher Lager 650ml',          'FB-BR-KL65',  'Beer',          'Cse', 'Btl', 24,   115, 48, $vat],
            ['Kingfisher Strong 650ml',         'FB-BR-KS65',  'Beer',          'Cse', 'Btl', 24,   120, 48, $vat],
            ['Kingfisher Ultra 330ml',          'FB-BR-KU33',  'Beer',          'Cse', 'Btl', 24,    95, 24, $vat],
            ['Heineken 330ml',                  'FB-BR-HN33',  'Beer',          'Cse', 'Btl', 24,   135, 24, $vat],
            ['Budweiser 330ml',                 'FB-BR-BD33',  'Beer',          'Cse', 'Btl', 24,   120, 24, $vat],
            ['Corona Extra 330ml',              'FB-BR-CO33',  'Beer',          'Cse', 'Btl', 24,   155, 24, $vat],
            ['Tuborg Strong 650ml',             'FB-BR-TG65',  'Beer',          'Cse', 'Btl', 24,   110, 24, $vat],
            ['Carlsberg Green 650ml',           'FB-BR-CG65',  'Beer',          'Cse', 'Btl', 24,   118, 24, $vat],
            ['Bira 91 White 330ml',             'FB-BR-BW33',  'Beer',          'Cse', 'Btl', 24,   140, 24, $vat],
            ['Bira 91 Strong 500ml',            'FB-BR-BS50',  'Beer',          'Cse', 'Btl', 24,   128, 24, $vat],
            ['Hoegaarden Wheat 330ml',          'FB-BR-HW33',  'Beer',          'Cse', 'Btl', 24,   195, 12, $vat],

            // ╔══════╗
            // ║ WINE (growing category in Indian hotels)
            // ╚══════╝
            ['Sula Chenin Blanc 750ml',         'FB-WN-SC75',  'Wine',          'Cse', 'Btl', 12,   450, 12, $vat],
            ['Sula Sauvignon Blanc 750ml',      'FB-WN-SS75',  'Wine',          'Cse', 'Btl', 12,   490, 12, $vat],
            ['Sula Shiraz Cab 750ml',           'FB-WN-SR75',  'Wine',          'Cse', 'Btl', 12,   490, 12, $vat],
            ['Jacob\'s Creek Shiraz 750ml',     'FB-WN-JC75',  'Wine',          'Cse', 'Btl', 12,   950,  6, $vat],
            ['Fratelli Sette 750ml',            'FB-WN-FS75',  'Wine',          'Cse', 'Btl', 12,   780,  6, $vat],
            ['Four Seasons Viognier 750ml',     'FB-WN-FV75',  'Wine',          'Cse', 'Btl', 12,   620,  6, $vat],
            ['Sula Brut Sparkling 750ml',       'FB-WN-SB75',  'Wine',          'Cse', 'Btl', 12,   750,  6, $vat],
            ['Moet & Chandon Brut 750ml',       'FB-WN-MC75',  'Wine',          'Cse', 'Btl', 12,  5200,  3, $vat],

            // ╔══════╗
            // ║ BRANDY
            // ╚══════╝
            ['Honey Bee Brandy 750ml',          'FB-BR2-HB75', 'Brandy',        'Cse', 'Btl', 12,   580, 12, $vat],
            ['McDowell\'s No.1 Brandy 750ml',   'FB-BR2-MB75', 'Brandy',        'Cse', 'Btl', 12,   620, 12, $vat],
            ['Dreher Brandy 750ml',             'FB-BR2-DR75', 'Brandy',        'Cse', 'Btl', 12,   540, 12, $vat],
            ['Rémy Martin VSOP 750ml',          'FB-BR2-RM75', 'Brandy',        'Cse', 'Btl', 12,  5800,  3, $vat],
            ['Hennessy VS 750ml',               'FB-BR2-HV75', 'Brandy',        'Cse', 'Btl', 12,  4500,  3, $vat],

            // ╔══════╗
            // ║ COCKTAIL MIXES & BAR ESSENTIALS
            // ╚══════╝
            ['Soda Water 300ml',                'FB-CM-SW30',  'Cocktail Mixes','Cse', 'Btl', 24,    18, 48, $gst5],
            ['Tonic Water 300ml',               'FB-CM-TW30',  'Cocktail Mixes','Cse', 'Btl', 24,    28, 36, $gst5],
            ['Ginger Ale 300ml',                'FB-CM-GA30',  'Cocktail Mixes','Cse', 'Btl', 24,    28, 24, $gst5],
            ['Lime Juice Cordial 750ml',        'FB-CM-LJ75',  'Cocktail Mixes','Btl', 'Btl',  1,   120, 12, $gst18],
            ['Triple Sec Cointreau 700ml',      'FB-CM-TS70',  'Cocktail Mixes','Btl', 'Btl',  1,  2800,  3, $vat],
            ['Blue Curacao Syrup 700ml',        'FB-CM-BC70',  'Cocktail Mixes','Btl', 'Btl',  1,   950,  3, $gst18],
            ['Grenadine Syrup 700ml',           'FB-CM-GS70',  'Cocktail Mixes','Btl', 'Btl',  1,   480,  6, $gst18],
            ['Angostura Bitters 200ml',         'FB-CM-AB20',  'Cocktail Mixes','Btl', 'Btl',  1,   850,  3, $gst18],
            ['Coconut Cream 400ml',             'FB-CM-CC40',  'Cocktail Mixes','Pcs', 'Pcs',  1,    95, 12, $gst5],
            ['Maraschino Cherries 400g',        'FB-CM-MC40',  'Cocktail Mixes','Pcs', 'Pcs',  1,   280,  6, $gst18],
        ];

        $uomMap = InventoryUom::pluck('id', 'short_name')->toArray();
        $count = 0;

        foreach ($items as [$name, $sku, $cat, $pUom, $iUom, $conv, $cost, $reorder, $tax]) {
            $pUomId = $uomMap[$pUom] ?? null;
            $iUomId = $uomMap[$iUom] ?? null;
            $catObj = $catMap[$cat] ?? null;

            if (!$pUomId || !$iUomId || !$catObj) continue;

            InventoryItem::updateOrCreate(
                ['sku' => $sku],
                [
                    'name'              => $name,
                    'sku'               => $sku,
                    'category_id'       => $catObj->id,
                    'purchase_uom_id'   => $pUomId,
                    'issue_uom_id'      => $iUomId,
                    'conversion_factor' => $conv,
                    'cost_price'        => $cost,
                    'reorder_level'     => $reorder,
                    'current_stock'     => rand($reorder, $reorder * 2),
                    'tax_id'            => $tax?->id,
                ]
            );
            $count++;
        }

        $this->command->info("✅ Alcohol catalog seeded — {$count} bar items added across " . count($catMap) . " sub-categories.");
    }
}
