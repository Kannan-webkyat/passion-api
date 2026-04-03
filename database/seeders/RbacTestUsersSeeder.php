<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\RestaurantMaster;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RbacTestUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure core roles exist (RolePermissionSeeder should already run).
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);
        $waiterRole = Role::firstOrCreate(['name' => 'Waiter']);
        $kitchenRole = Role::firstOrCreate(['name' => 'Kitchen Staff']);

        $admin = User::firstOrCreate(
            ['email' => 'admin@hotel.com'],
            ['name' => 'Admin User', 'password' => bcrypt('1')]
        );
        if (! $admin->hasRole($adminRole)) {
            $admin->assignRole($adminRole);
        }

        // Delete all non-admin users (fresh RBAC test dataset).
        $nonAdminUsers = User::where('id', '!=', $admin->id)->get();
        foreach ($nonAdminUsers as $u) {
            $u->roles()->detach();
            $u->permissions()->detach();
            $u->departments()->detach();
            $u->restaurants()->detach();
            $u->delete();
        }

        // Helpers
        $dept = fn (string $code) => Department::where('code', $code)->first();
        $outlet = fn (string $name) => RestaurantMaster::where('name', $name)->first();

        $ottaal = $outlet('OTTAAL');
        $bar = $outlet('BAR');

        // Admin: access to all departments/outlets (for testing).
        $admin->departments()->sync(Department::all()->pluck('id'));
        $adminOutletIds = collect([$ottaal?->id, $bar?->id])->filter()->values();
        if ($adminOutletIds->isNotEmpty()) {
            $admin->restaurants()->sync($adminOutletIds);
        }

        // Outlet Manager (high level)
        $manager = User::create([
            'name' => 'Outlet Manager',
            'email' => 'manager@passions.local',
            'password' => bcrypt('1'),
        ]);
        $manager->givePermissionTo([
            'pos-day-closing',
            'pos-discount',
            'pos-void-item',
            'pos-reopen-order',
            'report-sales',
            'report-day-closings',
            'report-refunds-adjustments',
            'report-voids-discounts',
            'report-order-type-mix',
            'report-menu-performance',
            'report-tax-gst-summary',
        ]);
        $fnb = $dept('FNB');
        if ($fnb) {
            $manager->departments()->sync([$fnb->id]);
        }
        if ($ottaal) {
            $manager->restaurants()->sync([$ottaal->id]);
        }

        // Cashier (low level)
        $cashier = User::create([
            'name' => 'Cashier',
            'email' => 'cashier@passions.local',
            'password' => bcrypt('1'),
        ]);
        $cashier->assignRole($cashierRole);
        if ($fnb) {
            $cashier->departments()->sync([$fnb->id]);
        }
        if ($ottaal) {
            $cashier->restaurants()->sync([$ottaal->id]);
        }

        // Waiter (low level)
        $waiter = User::create([
            'name' => 'Waiter',
            'email' => 'waiter@passions.local',
            'password' => bcrypt('1'),
        ]);
        $waiter->assignRole($waiterRole);
        if ($fnb) {
            $waiter->departments()->sync([$fnb->id]);
        }
        if ($ottaal) {
            $waiter->restaurants()->sync([$ottaal->id]);
        }

        // Kitchen Chief / Staff
        $kitchen = User::create([
            'name' => 'Kitchen Chief',
            'email' => 'kitchen@passions.local',
            'password' => bcrypt('1'),
        ]);
        $kitchen->assignRole($kitchenRole);
        $ktn = $dept('KTN');
        if ($ktn) {
            $kitchen->departments()->sync([$ktn->id]);
        }
        if ($ottaal) {
            $kitchen->restaurants()->sync([$ottaal->id]);
        }

        // Bar Cashier / Bar Waiter (optional, useful for testing BAR outlet restrictions)
        $barCashier = User::create([
            'name' => 'Bar Cashier',
            'email' => 'bar.cashier@passions.local',
            'password' => bcrypt('1'),
        ]);
        $barCashier->assignRole($cashierRole);
        $barDept = $dept('BAR');
        if ($barDept) {
            $barCashier->departments()->sync([$barDept->id]);
        }
        if ($bar) {
            $barCashier->restaurants()->sync([$bar->id]);
        }

        $barWaiter = User::create([
            'name' => 'Bar Waiter',
            'email' => 'bar.waiter@passions.local',
            'password' => bcrypt('1'),
        ]);
        $barWaiter->assignRole($waiterRole);
        if ($barDept) {
            $barWaiter->departments()->sync([$barDept->id]);
        }
        if ($bar) {
            $barWaiter->restaurants()->sync([$bar->id]);
        }
    }
}

