<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class KitchenStaffSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'kitchen@gmail.com'],
            [
                'name' => 'Kitchen Chef',
                'password' => bcrypt('1'),
            ]
        );

        $role = Role::where('name', 'Kitchen Staff')->first();
        if ($role) {
            $user->assignRole($role);
        }

        // Link to OTTAAL outlet
        $ottaal = \App\Models\RestaurantMaster::where('name', 'OTTAAL')->first();
        if ($ottaal) {
            $user->restaurants()->syncWithoutDetaching([$ottaal->id]);
        }
    }
}
