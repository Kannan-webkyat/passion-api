<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\Concerns\AuthorizesHousekeepingPermissions;
use App\Models\RoomCleaningRelease;
use App\Services\RoomCleaningAvailabilityService;
use App\Support\CleaningReleasePriority;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class RoomCleaningAvailabilityServicePriorityTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    private RoomCleaningAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RoomCleaningAvailabilityService::class);
    }

    public function test_release_for_cleaning_persists_priority(): void
    {
        $user = $this->createUserWithPermission(AuthorizesHousekeepingPermissions::HK_CLEANING_AVAILABILITY);
        Auth::login($user);

        $room = $this->createRoom();

        $release = $this->service->releaseForCleaning([
            'room_id' => $room->id,
            ...$this->releaseWindowPayload(priority: CleaningReleasePriority::VIP),
        ]);

        $this->assertSame(CleaningReleasePriority::VIP, $release->priority);
        $this->assertDatabaseHas('room_cleaning_releases', [
            'id' => $release->id,
            'priority' => CleaningReleasePriority::VIP,
        ]);
    }

    public function test_reschedule_window_updates_priority(): void
    {
        $room = $this->createRoom();
        $release = $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::NORMAL,
        ]);

        $updated = $this->service->rescheduleWindow($release, [
            ...$this->releaseWindowPayload(priority: CleaningReleasePriority::URGENT),
            'remarks' => 'Guest waiting',
        ]);

        $this->assertSame(CleaningReleasePriority::URGENT, $updated->priority);
        $this->assertDatabaseHas('room_cleaning_releases', [
            'id' => $release->id,
            'priority' => CleaningReleasePriority::URGENT,
        ]);
    }

    public function test_extend_window_updates_priority_when_provided(): void
    {
        $room = $this->createRoom();
        $release = $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::NORMAL,
        ]);

        $newEnd = Carbon::parse($release->window_end)->addHour();

        $updated = $this->service->extendWindow($release, [
            'window_end' => $newEnd->toDateTimeString(),
            'priority' => CleaningReleasePriority::DEEP_CLEAN,
        ]);

        $this->assertSame(CleaningReleasePriority::DEEP_CLEAN, $updated->priority);
    }

    public function test_reschedule_window_rejects_invalid_priority(): void
    {
        $room = $this->createRoom();
        $release = $this->createActiveRelease($room);

        $this->expectException(InvalidArgumentException::class);

        $this->service->rescheduleWindow($release, [
            ...$this->releaseWindowPayload(priority: 'invalid'),
        ]);
    }

    public function test_reschedule_without_priority_keeps_existing_value(): void
    {
        $room = $this->createRoom();
        $release = $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::VIP,
        ]);

        $payload = $this->releaseWindowPayload();
        unset($payload['priority']);

        $updated = $this->service->rescheduleWindow($release, $payload);

        $this->assertSame(CleaningReleasePriority::VIP, $updated->priority);
    }

    public function test_concurrent_reschedule_requests_last_write_wins_without_corruption(): void
    {
        $room = $this->createRoom();
        $release = $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::NORMAL,
        ]);

        $this->service->rescheduleWindow($release, $this->releaseWindowPayload(priority: CleaningReleasePriority::URGENT));
        $final = $this->service->rescheduleWindow(
            $release->fresh(),
            $this->releaseWindowPayload(priority: CleaningReleasePriority::DEEP_CLEAN),
        );

        $this->assertSame(CleaningReleasePriority::DEEP_CLEAN, $final->priority);
        $this->assertSame(1, RoomCleaningRelease::query()->where('room_id', $room->id)->count());
        $this->assertContains(
            $final->priority,
            CleaningReleasePriority::values(),
        );
    }
}
