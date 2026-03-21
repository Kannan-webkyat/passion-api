<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class BarStaffSeeder extends Seeder
{
    public function run(): void
    {
        $barCashier = User::firstOrCreate(
            ['email' => 'bar_cashier@gmail.com'],
            [
                'name' => 'Bar Cashier',
                'password' => bcrypt('password'),
            ]
        );

        $barWaiter = User::firstOrCreate(
            ['email' => 'bar_waiter@gmail.com'],
            [
                'name' => 'Bar Waiter',
                'password' => bcrypt('password'),
            ]
        );

        $cashierRole = Role::where('name', 'Cashier')->first();
        $waiterRole = Role::where('name', 'Waiter')->first();

        if ($cashierRole) {
            $barCashier->syncRoles([$cashierRole]);
        }
        if ($waiterRole) {
            $barWaiter->syncRoles([$waiterRole]);
        }

        // Link to BAR outlet
        $barOutlet = \App\Models\RestaurantMaster::where('name', 'BAR')->first();
        if ($barOutlet) {
            $barCashier->restaurants()->syncWithoutDetaching([$barOutlet->id]);
            $barWaiter->restaurants()->syncWithoutDetaching([$barOutlet->id]);
        }
    }
}
