<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RestaurantMaster;
use App\Models\MenuCategory;
use App\Models\MenuSubCategory;
use App\Models\MenuItem;
use App\Models\Combo;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create a Restaurant Master if none exist
        $restaurant = RestaurantMaster::firstOrCreate(
            ['name' => 'The Grand Dining'],
            ['floor' => 'Ground Floor', 'description' => 'Main Restaurant', 'is_active' => true]
        );

        // 2. Create Categories
        $catStarters = MenuCategory::firstOrCreate(['name' => 'Starters'], ['is_active' => true]);
        $catMainCourse = MenuCategory::firstOrCreate(['name' => 'Main Course'], ['is_active' => true]);
        $catBeverages = MenuCategory::firstOrCreate(['name' => 'Beverages'], ['is_active' => true]);

        // 3. Create Sub Categories
        $subVegStarters = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $catStarters->id, 'name' => 'Vegetarian Starters'],
            ['description' => 'Veg Starters', 'is_active' => true]
        );
        $subNonVegStarters = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $catStarters->id, 'name' => 'Non-Vegetarian Starters'],
            ['description' => 'Non-Veg Starters', 'is_active' => true]
        );

        $subVegMain = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $catMainCourse->id, 'name' => 'Vegetarian Main Course'],
            ['description' => 'Veg Main Course', 'is_active' => true]
        );
        
        $subColdBeverages = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $catBeverages->id, 'name' => 'Cold Beverages'],
            ['description' => 'Cold Drinks', 'is_active' => true]
        );

        // 4. Create Menu Items
        $itemPaneerTikka = MenuItem::firstOrCreate(
            ['item_code' => 'ST001'],
            [
                'restaurant_master_id' => $restaurant->id,
                'name' => 'Paneer Tikka',
                'menu_category_id' => $catStarters->id,
                'menu_sub_category_id' => $subVegStarters->id,
                'price' => 250,
                'fixed_ept' => 15,
                'type' => 'veg',
                'is_active' => true
            ]
        );

        $itemChickenTikka = MenuItem::firstOrCreate(
            ['item_code' => 'ST002'],
            [
                'restaurant_master_id' => $restaurant->id,
                'name' => 'Chicken Tikka',
                'menu_category_id' => $catStarters->id,
                'menu_sub_category_id' => $subNonVegStarters->id,
                'price' => 350,
                'fixed_ept' => 20,
                'type' => 'non-veg',
                'is_active' => true
            ]
        );

        $itemDalMakhani = MenuItem::firstOrCreate(
            ['item_code' => 'MC001'],
            [
                'restaurant_master_id' => $restaurant->id,
                'name' => 'Dal Makhani',
                'menu_category_id' => $catMainCourse->id,
                'menu_sub_category_id' => $subVegMain->id,
                'price' => 280,
                'fixed_ept' => 25,
                'type' => 'veg',
                'is_active' => true
            ]
        );

        $itemCola = MenuItem::firstOrCreate(
            ['item_code' => 'BV001'],
            [
                'restaurant_master_id' => $restaurant->id,
                'name' => 'Fresh Lime Soda',
                'menu_category_id' => $catBeverages->id,
                'menu_sub_category_id' => $subColdBeverages->id,
                'price' => 120,
                'fixed_ept' => 5,
                'type' => 'veg',
                'is_active' => true
            ]
        );

        // 5. Create Combos and Sync Combo Items
        $comboVegDelight = Combo::firstOrCreate(
            ['name' => 'Veg Delight Combo'],
            [
                'restaurant_master_id' => $restaurant->id,
                'price' => 599,
                'fixed_ept' => 30,
                'is_active' => true
            ]
        );
        $comboVegDelight->menuItems()->sync([$itemPaneerTikka->id, $itemDalMakhani->id, $itemCola->id]);

        $comboNonVegDelight = Combo::firstOrCreate(
            ['name' => 'Non-Veg Delight Combo'],
            [
                'restaurant_master_id' => $restaurant->id,
                'price' => 749,
                'fixed_ept' => 35,
                'is_active' => true
            ]
        );
        $comboNonVegDelight->menuItems()->sync([$itemChickenTikka->id, $itemDalMakhani->id, $itemCola->id]);
    }
}
