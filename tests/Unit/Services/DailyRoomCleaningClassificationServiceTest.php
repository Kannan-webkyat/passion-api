<?php

namespace Tests\Unit\Services;

use App\Models\DailyRoomCleaning;
use App\Models\RoomCleaningRelease;
use App\Services\DailyRoomCleaningClassificationService;
use App\Support\CleaningServiceClassification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class DailyRoomCleaningClassificationServiceTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    private DailyRoomCleaningClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DailyRoomCleaningClassificationService::class);
    }

    public function test_first_release_on_date_is_classified_as_daily(): void
    {
        $room = $this->createRoom();
        $date = Carbon::today()->toDateString();

        $result = $this->service->classifyRelease((int) $room->id, $date);

        $this->assertSame(CleaningServiceClassification::TYPE_DAILY, $result->serviceType);
        $this->assertNull($result->serviceSubtype);
        $this->assertFalse($result->reclassified);
    }

    public function test_subsequent_release_after_daily_completed_is_reclassified_as_other(): void
    {
        $room = $this->createRoom();
        $date = Carbon::today()->toDateString();
        $this->createDailyCleaning($room, ['service_date' => $date]);

        $result = $this->service->recordCleaning((int) $room->id, $date, [
            'is_rerelease' => true,
        ]);

        $this->assertSame(CleaningServiceClassification::TYPE_OTHER, $result->serviceType);
        $this->assertSame(CleaningServiceClassification::SUBTYPE_RERELEASE, $result->serviceSubtype);
        $this->assertTrue($result->reclassified);
        $this->assertSame('daily cleaning already completed', $result->reason);
    }

    public function test_explicit_other_type_is_accepted_without_reclassification_flag(): void
    {
        $room = $this->createRoom();
        $date = Carbon::today()->toDateString();

        $result = $this->service->classifyRelease((int) $room->id, $date, [
            'service_type' => CleaningServiceClassification::TYPE_OTHER,
            'service_subtype' => CleaningServiceClassification::SUBTYPE_COMPLAINT,
        ]);

        $this->assertSame(CleaningServiceClassification::TYPE_OTHER, $result->serviceType);
        $this->assertSame(CleaningServiceClassification::SUBTYPE_COMPLAINT, $result->serviceSubtype);
        $this->assertFalse($result->reclassified);
    }

    public function test_prepare_daily_cleaning_resets_operational_fields_for_other_service(): void
    {
        $room = $this->createRoom();
        $cleaning = $this->createDailyCleaning($room, [
            'status' => 'cleaned',
            'remarks' => 'Done',
            'checklist_done' => ['change_sheets' => true],
        ]);

        $classification = $this->service->classifyRelease((int) $room->id, $cleaning->service_date->toDateString(), [
            'is_rerelease' => true,
        ]);

        $this->service->prepareDailyCleaningForRelease($cleaning, $classification);
        $cleaning->refresh();

        $this->assertSame('pending_cleaning', $cleaning->status);
        $this->assertNull($cleaning->started_at);
        $this->assertNull($cleaning->completed_at);
        $this->assertNotNull($cleaning->daily_cleaning_completed_at);
        $this->assertNull($cleaning->checklist_done);
    }

    public function test_mark_daily_cleaning_completed_sets_timestamp_once(): void
    {
        $room = $this->createRoom();
        $cleaning = DailyRoomCleaning::query()->create([
            'room_id' => $room->id,
            'service_date' => Carbon::today()->toDateString(),
            'status' => 'cleaned',
            'completed_at' => now(),
        ]);
        $release = $this->createActiveRelease($room, [
            'service_type' => CleaningServiceClassification::TYPE_DAILY,
            'daily_room_cleaning_id' => $cleaning->id,
        ]);

        $this->service->markDailyCleaningCompleted($cleaning, $release);
        $first = $cleaning->fresh()->daily_cleaning_completed_at;
        $this->assertNotNull($first);

        $this->service->markDailyCleaningCompleted($cleaning->fresh(), $release);
        $this->assertTrue($cleaning->fresh()->daily_cleaning_completed_at->equalTo($first));
    }

    public function test_concurrent_record_cleaning_requests_last_classification_wins(): void
    {
        Log::spy();
        $room = $this->createRoom();
        $date = Carbon::today()->toDateString();
        $this->createDailyCleaning($room, ['service_date' => $date]);

        $first = $this->service->recordCleaning((int) $room->id, $date, ['is_rerelease' => true]);
        $second = $this->service->recordCleaning((int) $room->id, $date, [
            'service_type' => CleaningServiceClassification::TYPE_OTHER,
            'service_subtype' => CleaningServiceClassification::SUBTYPE_COMPLAINT,
        ]);

        $this->assertSame(CleaningServiceClassification::TYPE_OTHER, $first->serviceType);
        $this->assertSame(CleaningServiceClassification::SUBTYPE_COMPLAINT, $second->serviceSubtype);
        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_timezone_boundary_uses_service_date_not_utc_day(): void
    {
        $room = $this->createRoom();
        $appTz = config('app.timezone', 'UTC');
        $localDay = Carbon::now($appTz)->toDateString();
        $previousDay = Carbon::parse($localDay, $appTz)->subDay()->toDateString();

        $this->createDailyCleaning($room, [
            'service_date' => $previousDay,
            'daily_cleaning_completed_at' => Carbon::parse($previousDay, $appTz)->setTime(23, 30),
        ]);

        $result = $this->service->classifyRelease((int) $room->id, $localDay);

        $this->assertSame(CleaningServiceClassification::TYPE_DAILY, $result->serviceType);
    }
}
