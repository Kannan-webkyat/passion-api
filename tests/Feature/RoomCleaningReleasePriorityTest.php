<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\AuthorizesHousekeepingPermissions;
use App\Models\RoomCleaningRelease;
use App\Support\CleaningReleasePriority;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class RoomCleaningReleasePriorityTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    public function test_store_persists_priority_and_returns_it_in_response(): void
    {
        $user = $this->createUserWithPermission(AuthorizesHousekeepingPermissions::HK_CLEANING_AVAILABILITY);
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $payload = [
            'room_id' => $room->id,
            ...$this->releaseWindowPayload(priority: CleaningReleasePriority::URGENT),
        ];

        $response = $this->postJson('/api/housekeeping/cleaning-releases', $payload);

        $response->assertCreated()
            ->assertJsonPath('priority', CleaningReleasePriority::URGENT);

        $this->assertDatabaseHas('room_cleaning_releases', [
            'room_id' => $room->id,
            'priority' => CleaningReleasePriority::URGENT,
        ]);
    }

    public function test_reschedule_updates_priority(): void
    {
        $user = $this->createUserWithPermission(AuthorizesHousekeepingPermissions::HK_CLEANING_AVAILABILITY);
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $release = $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::NORMAL,
        ]);

        $response = $this->postJson(
            "/api/housekeeping/cleaning-releases/{$release->id}/reschedule",
            $this->releaseWindowPayload(priority: CleaningReleasePriority::VIP),
        );

        $response->assertOk()
            ->assertJsonPath('priority', CleaningReleasePriority::VIP);

        $this->assertDatabaseHas('room_cleaning_releases', [
            'id' => $release->id,
            'priority' => CleaningReleasePriority::VIP,
        ]);
    }

    public function test_room_context_returns_active_release_priority(): void
    {
        $user = $this->createUserWithPermission(AuthorizesHousekeepingPermissions::HK_CLEANING_AVAILABILITY);
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::DEEP_CLEAN,
        ]);

        $response = $this->getJson("/api/housekeeping/rooms/{$room->id}/cleaning-release-context");

        $response->assertOk()
            ->assertJsonPath('active_release.priority', CleaningReleasePriority::DEEP_CLEAN);
    }

    public function test_store_rejects_invalid_priority_with_validation_error(): void
    {
        $user = $this->createUserWithPermission(AuthorizesHousekeepingPermissions::HK_CLEANING_AVAILABILITY);
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $payload = [
            'room_id' => $room->id,
            ...$this->releaseWindowPayload(priority: 'high'),
        ];

        $response = $this->postJson('/api/housekeeping/cleaning-releases', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);

        $this->assertDatabaseCount('room_cleaning_releases', 0);
    }

    public function test_reschedule_rejects_invalid_priority_without_mutating_record(): void
    {
        $user = $this->createUserWithPermission(AuthorizesHousekeepingPermissions::HK_CLEANING_AVAILABILITY);
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $release = $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::NORMAL,
        ]);

        $response = $this->postJson(
            "/api/housekeeping/cleaning-releases/{$release->id}/reschedule",
            $this->releaseWindowPayload(priority: '5'),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);

        $this->assertDatabaseHas('room_cleaning_releases', [
            'id' => $release->id,
            'priority' => CleaningReleasePriority::NORMAL,
        ]);
    }

    public function test_parallel_reschedule_requests_last_successful_priority_wins(): void
    {
        $user = $this->createUserWithPermission(AuthorizesHousekeepingPermissions::HK_CLEANING_AVAILABILITY);
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $release = $this->createActiveRelease($room, [
            'priority' => CleaningReleasePriority::NORMAL,
        ]);

        $first = $this->postJson(
            "/api/housekeeping/cleaning-releases/{$release->id}/reschedule",
            $this->releaseWindowPayload(priority: CleaningReleasePriority::URGENT),
        );
        $second = $this->postJson(
            "/api/housekeeping/cleaning-releases/{$release->id}/reschedule",
            $this->releaseWindowPayload(priority: CleaningReleasePriority::VIP),
        );

        $first->assertOk();
        $second->assertOk()
            ->assertJsonPath('priority', CleaningReleasePriority::VIP);

        $this->assertSame(
            CleaningReleasePriority::VIP,
            RoomCleaningRelease::query()->findOrFail($release->id)->priority,
        );
        $this->assertSame(1, RoomCleaningRelease::query()->where('room_id', $room->id)->count());
    }
}
