<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            InventoryTaxSeeder::class,
            InventoryUomSeeder::class,
            // ---
            CessSlabSeeder::class,
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            LocationSeeder::class,
            // --
            PaymentMethodSeeder::class,
            RestaurantTableSeeder::class,
            // FreshBiryaniTeaCoffeeSeeder::class,
            // BarSeeder::class,
            // --
            // BarInventoryOrganizedSeeder::class,
            // RestaurantInventoryCatalogSeeder::class,
            ChickenBiryaniSeeder::class,
            // BarOutletSeeder::class,
            // BarMenuConfigurationSeeder::class,
            // BarOrganizedItemPricingSeeder::class,
            // RestaurantMenuCatalogSeeder::class,
            // RestaurantMenuCatalogPricingSeeder::class,
            // RestaurantBarOpeningStockSeeder::class,
            // BarCocktailSeeder::class,
            // ----
            // RoomTypeRoomSeeder::class,
            // HotelInventoryCatalogSeeder::class,
            // RoomParTestTemplatesSeeder::class,
            // RoomParProcurementStockSeeder::class,
            // HousekeepingStoreRoomParStockSeeder::class, 
            // HotelMinibarMenuItemsSeeder::class,
            // HousekeepingChecklistSeeder::class,
            // RbacTestUsersSeeder::class,
            // BookingSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'admin@hotel.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('1'),
            ]
        )->assignRole('Admin');
    }
}
