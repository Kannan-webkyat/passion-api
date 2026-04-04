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
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            LocationSeeder::class,
            PaymentMethodSeeder::class,
            RestaurantTableSeeder::class,  // Must run before FreshBiryaniTeaCoffeeSeeder (creates restaurants)
            FreshBiryaniTeaCoffeeSeeder::class,
            BarSeeder::class,
            RoomTypeRoomSeeder::class, // After InventoryTaxSeeder (room type tax_id)
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
