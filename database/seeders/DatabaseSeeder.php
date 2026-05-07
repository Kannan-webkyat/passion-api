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
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            LocationSeeder::class,
            PaymentMethodSeeder::class,
            RestaurantTableSeeder::class,
            FreshBiryaniTeaCoffeeSeeder::class,
            BarSeeder::class,
            RoomTypeRoomSeeder::class,
            HotelInventoryCatalogSeeder::class,
            HotelInventoryOpeningStockSeeder::class,
            HotelMinibarMenuItemsSeeder::class,
            RbacTestUsersSeeder::class,
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
