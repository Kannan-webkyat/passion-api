<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    private const LEGACY = [
        'housekeeping-view',
        'housekeeping-operate',
    ];

    public function up(): void
    {
        Permission::query()
            ->whereIn('name', self::LEGACY)
            ->where('guard_name', 'web')
            ->get()
            ->each(fn(Permission $permission) => $permission->delete());
    }

    public function down(): void
    {
        foreach (self::LEGACY as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }
};
