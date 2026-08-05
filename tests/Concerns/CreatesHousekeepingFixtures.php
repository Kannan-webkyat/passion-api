<?php

namespace Tests\Concerns;

use App\Models\DailyRoomCleaning;
use App\Models\Room;
use App\Models\RoomCleaningRelease;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait CreatesHousekeepingFixtures
{
    use MigratesHousekeepingTestSchema;

    public function setUpCreatesHousekeepingFixtures(): void
    {
        $this->migrateHousekeepingTestSchema();
    }

    protected function resetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function ensurePermission(string $name): Permission
    {
        return Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    protected function createUserWithPermission(string $permission): User
    {
        $this->resetPermissionCache();
        $this->ensurePermission($permission);

        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    protected function createRoom(string $roomNumber = '101'): Room
    {
        $roomType = RoomType::query()->create([
            'name' => 'Standard',
            'description' => 'Test room type',
            'capacity' => 2,
        ]);

        return Room::query()->create([
            'room_number' => $roomNumber,
            'room_type_id' => $roomType->id,
            'status' => 'available',
        ]);
    }

    protected function createDailyCleaning(Room $room, array $overrides = []): DailyRoomCleaning
    {
        $today = Carbon::today();

        return DailyRoomCleaning::query()->create(array_merge([
            'room_id' => $room->id,
            'service_date' => $today->toDateString(),
            'status' => 'cleaned',
            'completed_at' => now(),
            'daily_cleaning_completed_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createActiveRelease(Room $room, array $overrides = []): RoomCleaningRelease
    {
        $today = Carbon::today();
        $start = $today->copy()->setTime(12, 0);
        $end = $today->copy()->setTime(14, 0);

        return RoomCleaningRelease::query()->create(array_merge([
            'room_id' => $room->id,
            'release_date' => $today->toDateString(),
            'window_start' => $start,
            'window_end' => $end,
            'status' => RoomCleaningRelease::STATUS_AVAILABLE,
            'priority' => 'normal',
            'service_type' => 'daily',
            'is_active' => true,
            'created_by' => null,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    protected function releaseWindowPayload(
        ?Carbon $date = null,
        string $priority = 'normal',
    ): array {
        $day = $date ?? Carbon::today();
        $start = $day->copy()->setTime(12, 0);
        $end = $day->copy()->setTime(14, 0);

        return [
            'release_date' => $day->toDateString(),
            'window_start' => $start->format('Y-m-d H:i:s'),
            'window_end' => $end->format('Y-m-d H:i:s'),
            'priority' => $priority,
        ];
    }
}
