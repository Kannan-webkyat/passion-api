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
                'password' => bcrypt('password'),
            ]
        );

        $role = Role::where('name', 'Kitchen Staff')->first();
        if ($role) {
            $user->assignRole($role);
        }
    }
}
