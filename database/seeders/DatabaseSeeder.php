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
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            LocationSeeder::class,
            ChickenBiryaniSeeder::class,
            QuickItemsSeeder::class,
            KitchenStaffSeeder::class,
            ReceptionistSeeder::class,
            StoreManagerSeeder::class,
            UserDepartmentSeeder::class,
            RoomSeeder::class,
            BookingSeeder::class,
            RestaurantTableSeeder::class,
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
