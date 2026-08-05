<?php

namespace Tests\Feature;

use App\Models\RoomCleaningRelease;
use App\Models\RoomCleaningReleaseAudit;
use App\Support\CleaningServiceClassification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class RoomCleaningReleaseClassificationTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    public function test_rerelease_after_daily_completed_is_stored_as_other_service(): void
    {
        $user = $this->createUserWithPermission('housekeeping-cleaning-availability');
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $cleaning = $this->createDailyCleaning($room);
        $this->createActiveRelease($room, [
            'status' => RoomCleaningRelease::STATUS_READY,
            'is_active' => false,
            'service_type' => CleaningServiceClassification::TYPE_DAILY,
            'daily_room_cleaning_id' => $cleaning->id,
        ]);

        $response = $this->postJson('/api/housekeeping/cleaning-releases', [
            'room_id' => $room->id,
            'is_rerelease' => true,
            ...$this->releaseWindowPayload(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('service_type', CleaningServiceClassification::TYPE_OTHER)
            ->assertJsonPath('service_subtype', CleaningServiceClassification::SUBTYPE_RERELEASE)
            ->assertJsonPath('service_classification.reclassified', true);

        $this->assertDatabaseHas('room_cleaning_releases', [
            'room_id' => $room->id,
            'service_type' => CleaningServiceClassification::TYPE_OTHER,
            'service_subtype' => CleaningServiceClassification::SUBTYPE_RERELEASE,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('room_cleaning_release_audits', [
            'action' => RoomCleaningReleaseAudit::ACTION_SERVICE_RECLASSIFIED,
        ]);
    }

    public function test_cleaning_release_history_detail_is_available(): void
    {
        $user = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        Sanctum::actingAs($user);

        $room = $this->createRoom();
        $cleaning = $this->createDailyCleaning($room, ['status' => 'cleaned']);
        $release = $this->createActiveRelease($room, [
            'status' => RoomCleaningRelease::STATUS_READY,
            'is_active' => false,
            'service_type' => CleaningServiceClassification::TYPE_OTHER,
            'service_subtype' => CleaningServiceClassification::SUBTYPE_RERELEASE,
            'daily_room_cleaning_id' => $cleaning->id,
            'completed_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/housekeeping/rooms/{$room->id}/cleaning-history/detail?source=cleaning_release&id={$release->id}",
        );

        $response->assertOk()
            ->assertJsonPath('source', 'cleaning_release')
            ->assertJsonPath('service_subtype', CleaningServiceClassification::SUBTYPE_RERELEASE)
            ->assertJsonPath('service_label', 'Requested re-service');
    }
}
