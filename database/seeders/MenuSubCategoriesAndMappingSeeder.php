<?php

namespace Database\Seeders;

use App\Models\MenuCategory;
use App\Models\MenuSubCategory;
use App\Models\MenuItem;
use App\Models\DietaryType;
use Illuminate\Database\Seeder;

class MenuSubCategoriesAndMappingSeeder extends Seeder
{
    public function run(): void
    {
        $biryani = MenuCategory::where('name', 'Biryani')->first();
        $beverages = MenuCategory::where('name', 'Beverages')->first();
        $breakfast = MenuCategory::where('name', 'Breakfast')->first();

        $gst5 = \App\Models\InventoryTax::where('rate', 5)->first();

        // ── Dietary types ──────────────────────────────────────────────────────

        DietaryType::updateOrCreate(['name' => 'Veg'], ['is_active' => true]);
        DietaryType::updateOrCreate(['name' => 'Non-Veg'], ['is_active' => true]);
        DietaryType::updateOrCreate(['name' => 'Egg'], ['is_active' => true]);

        // ── Subcategories ────────────────────────────────────────────────────

        // Biryani subcategories
        if ($biryani) {
            MenuSubCategory::firstOrCreate(
                ['menu_category_id' => $biryani->id, 'name' => 'Chicken'],
                ['name' => 'Chicken']
            );
            MenuSubCategory::firstOrCreate(
                ['menu_category_id' => $biryani->id, 'name' => 'Mutton'],
                ['name' => 'Mutton']
            );
            MenuSubCategory::firstOrCreate(
                ['menu_category_id' => $biryani->id, 'name' => 'Veg'],
                ['name' => 'Veg']
            );
        }

        // Beverages subcategories
        if ($beverages) {
            MenuSubCategory::firstOrCreate(
                ['menu_category_id' => $beverages->id, 'name' => 'Hot Drinks'],
                ['name' => 'Hot Drinks']
            );
            MenuSubCategory::firstOrCreate(
                ['menu_category_id' => $beverages->id, 'name' => 'Cold Drinks'],
                ['name' => 'Cold Drinks']
            );
        }

        // Breakfast subcategories
        if ($breakfast) {
            MenuSubCategory::firstOrCreate(
                ['menu_category_id' => $breakfast->id, 'name' => 'Eggs'],
                ['name' => 'Eggs']
            );
            MenuSubCategory::firstOrCreate(
                ['menu_category_id' => $breakfast->id, 'name' => 'Continental'],
                ['name' => 'Continental']
            );
        }

        // ── Map items: subcategory, tax, dietary type, EPT ──────────────────────

        $hotDrinks = $beverages ? MenuSubCategory::where('menu_category_id', $beverages->id)->where('name', 'Hot Drinks')->first() : null;
        $eggs = $breakfast ? MenuSubCategory::where('menu_category_id', $breakfast->id)->where('name', 'Eggs')->first() : null;
        $chicken = $biryani ? MenuSubCategory::where('menu_category_id', $biryani->id)->where('name', 'Chicken')->first() : null;

        // Tea → Hot Drinks, GST 5%, Veg, EPT 5 mins
        MenuItem::where('name', 'Tea')->update([
            'menu_sub_category_id' => $hotDrinks?->id,
            'tax_id' => $gst5?->id,
            'type' => 'Veg',
            'fixed_ept' => 5,
        ]);

        // Coffee → Hot Drinks, GST 5%, Veg, EPT 5 mins
        MenuItem::where('name', 'Coffee')->update([
            'menu_sub_category_id' => $hotDrinks?->id,
            'tax_id' => $gst5?->id,
            'type' => 'Veg',
            'fixed_ept' => 5,
        ]);

        // Omelette → Eggs, GST 5%, Egg, EPT 10 mins
        MenuItem::where('name', 'Omelette')->update([
            'menu_sub_category_id' => $eggs?->id,
            'tax_id' => $gst5?->id,
            'type' => 'Egg',
            'fixed_ept' => 10,
        ]);

        // Chicken Biryani → Chicken sub, GST 5%, Non-Veg, EPT 15 mins
        MenuItem::where('name', 'Chicken Biryani')->update([
            'menu_sub_category_id' => $chicken?->id,
            'tax_id' => $gst5?->id,
            'type' => 'Non-Veg',
            'fixed_ept' => 15,
        ]);
    }
}
