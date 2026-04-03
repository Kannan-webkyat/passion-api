<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class WaiterSeeder extends Seeder
{
    public function run(): void
    {
        $waiter1 = User::firstOrCreate(
            ['email' => 'waiter1@gmail.com'],
            [
                'name' => 'Waiter One',
                'password' => bcrypt('1'),
            ]
        );

        $waiter2 = User::firstOrCreate(
            ['email' => 'waiter2@gmail.com'],
            [
                'name' => 'Waiter Two',
                'password' => bcrypt('1'),
            ]
        );

        $role = Role::where('name', 'Waiter')->first();
        if ($role) {
            $waiter1->syncRoles([$role]);
            $waiter2->syncRoles([$role]);
        }

        // Link to OTTAAL outlet
        $ottaal = \App\Models\RestaurantMaster::where('name', 'OTTAAL')->first();
        if ($ottaal) {
            $waiter1->restaurants()->syncWithoutDetaching([$ottaal->id]);
            $waiter2->restaurants()->syncWithoutDetaching([$ottaal->id]);
        }
    }
}
