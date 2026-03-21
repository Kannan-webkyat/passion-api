<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserDepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Admin (Link to all)
        $admin = User::where('email', 'admin@hotel.com')->first();
        if ($admin) {
            $admin->departments()->sync(Department::all()->pluck('id'));
        }

        // 2. Kitchen Manager
        $chef = User::where('email', 'kitchen@gmail.com')->first();
        if ($chef) {
            $kitchen = Department::where('code', 'KTN')->first();
            if ($kitchen) {
                $chef->departments()->sync([$kitchen->id]);
            }
        }

        // 3. Receptionist
        $receptionist = User::where('email', 'reception@gmail.com')->first();
        if ($receptionist) {
            $fo = Department::where('code', 'FRO')->first();
            if ($fo) {
                $receptionist->departments()->sync([$fo->id]);
            }
        }

        // 4. Store Manager
        $storeManager = User::where('email', 'storemanager@gmail.com')->first();
        if ($storeManager) {
            $storeManager->departments()->sync(Department::all()->pluck('id'));
        }

        // 5. Waiters (F&B department)
        $fnb = Department::where('code', 'FNB')->first();
        if ($fnb) {
            User::whereIn('email', ['waiter1@gmail.com', 'waiter2@gmail.com'])
                ->each(fn ($u) => $u->departments()->sync([$fnb->id]));
        }

        // 6. Bar Cashier & Bar Waiter (Bar & Lounge department)
        $barDept = Department::where('code', 'BAR')->first();
        if ($barDept) {
            User::whereIn('email', ['bar_cashier@gmail.com', 'bar_waiter@gmail.com'])
                ->each(fn ($u) => $u->departments()->sync([$barDept->id]));
        }
    }
}
