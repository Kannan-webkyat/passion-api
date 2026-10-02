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
            DefaultHotelRolesSeeder::class,
            DepartmentSeeder::class,
            HousekeepingUsersSeeder::class,
            LocationSeeder::class,
            // --
            PaymentMethodSeeder::class,
            RestaurantTableSeeder::class,
            FreshBiryaniTeaCoffeeSeeder::class,
            BarSeeder::class,
            // --
            BarInventoryOrganizedSeeder::class,
            RestaurantInventoryCatalogSeeder::class,
            HousekeepingInventorySeeder::class,
            BarOutletSeeder::class,
            BarMenuConfigurationSeeder::class,
            BarOrganizedItemPricingSeeder::class,
            RestaurantMenuCatalogSeeder::class,
            MinibarMenuSeeder::class,
            // ----
            RoomTypeRoomSeeder::class,
            // HotelInventoryCatalogSeeder::class,
            RoomParTestTemplatesSeeder::class,
            HousekeepingMainStoreStockSeeder::class,
            HousekeepingChecklistSeeder::class,
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
