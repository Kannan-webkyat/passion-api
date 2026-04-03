<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class StoreManagerSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'storemanager@gmail.com'],
            [
                'name' => 'Store Manager',
                'password' => bcrypt('1'),
            ]
        );

        $role = Role::where('name', 'Inventory Manager')->first();
        if ($role) {
            $user->assignRole($role);
        }
    }
}
