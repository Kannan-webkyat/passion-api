<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Department;

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
        $storeManager = User::where('email', 'storemanger@gmail.com')->first();
        if ($storeManager) {
            $storeManager->departments()->sync(Department::all()->pluck('id'));
        }
    }
}
