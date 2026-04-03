<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class ReceptionistSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'reception@gmail.com'],
            [
                'name' => 'Receptionist User',
                'password' => bcrypt('1'),
            ]
        );

        $role = Role::where('name', 'Receptionist')->first();
        if ($role) {
            $user->assignRole($role);
        }
    }
}
