<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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
            KitchenStaffSeeder::class,
            ReceptionistSeeder::class,
            WaiterSeeder::class,
            StoreManagerSeeder::class,
            UserDepartmentSeeder::class,
            RoomSeeder::class,
            // BookingSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'admin@hotel.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
            ]
        )->assignRole('Admin');
    }
}
