<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Housekeeping users, one or more per housekeeping role template (DefaultHotelRolesSeeder).
 *
 * Everyone joins the Housekeeping department (HKP, flagged is_housekeeping), which is what puts
 * them in the staff-assignment dropdowns. Room Attendants without assign permissions only act on
 * the tasks and checkout inspections assigned to them.
 *
 * Idempotent: users are matched by email; their role and department are re-synced, the password
 * is only set on create. Other users are never touched.
 *
 * Requires: RolePermissionSeeder, DefaultHotelRolesSeeder, DepartmentSeeder.
 */
class HousekeepingUsersSeeder extends Seeder
{
    private const PASSWORD = '1';

    /** @var list<array{0: string, 1: string, 2: string}> [email, name, role] */
    private const USERS = [
        ['hk.manager@passions.local', 'Executive Housekeeper', 'Executive Housekeeper'],
        ['hk.supervisor@passions.local', 'HK Supervisor', 'Housekeeping Supervisor'],
        ['hk1@passions.local', 'Room Attendant 1', 'Room Attendant'],
        ['hk2@passions.local', 'Room Attendant 2', 'Room Attendant'],
        ['hk3@passions.local', 'Room Attendant 3', 'Room Attendant'],
        ['hk.laundry@passions.local', 'Laundry Attendant', 'Laundry Attendant'],
    ];

    public function run(): void
    {
        $hkp = Department::where('code', 'HKP')->first();
        if (! $hkp) {
            $this->command?->warn('  Housekeeping department (HKP) missing — run DepartmentSeeder first; users not added to a department.');
        }

        $created = 0;
        $updated = 0;

        foreach (self::USERS as [$email, $name, $roleName]) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                $this->command?->warn("  Role \"{$roleName}\" missing — run DefaultHotelRolesSeeder first; {$email} skipped.");

                continue;
            }

            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => bcrypt(self::PASSWORD), 'is_active' => true]
            );
            $user->wasRecentlyCreated ? $created++ : $updated++;

            $user->syncRoles([$role]);
            if ($hkp) {
                $user->departments()->sync([$hkp->id]);
            }
        }

        $this->command?->info("Housekeeping users seeded: {$created} created, {$updated} re-synced (password: ".self::PASSWORD.').');
    }
}
