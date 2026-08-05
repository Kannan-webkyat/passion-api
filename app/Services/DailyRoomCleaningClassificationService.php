<?php

namespace App\Services;

use App\Models\DailyRoomCleaning;
use App\Models\RoomCleaningRelease;
use App\Support\CleaningServiceClassification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CleaningServiceClassificationResult
{
    public function __construct(
        public readonly string $serviceType,
        public readonly ?string $serviceSubtype,
        public readonly bool $reclassified,
        public readonly ?string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_type' => $this->serviceType,
            'service_subtype' => $this->serviceSubtype,
            'reclassified' => $this->reclassified,
            'reason' => $this->reason,
        ];
    }
}

class DailyRoomCleaningClassificationService
{
    /**
     * Decide whether a new release should be recorded as daily or re-classified as other.
     *
     * @param  array<string, mixed>  $context
     */
    public function classifyRelease(int $roomId, string $serviceDate, array $context = []): CleaningServiceClassificationResult
    {
        $explicitType = isset($context['service_type']) ? (string) $context['service_type'] : null;
        $explicitSubtype = isset($context['service_subtype']) ? (string) $context['service_subtype'] : null;
        $isRerelease = (bool) ($context['is_rerelease'] ?? false);

        if ($explicitType === CleaningServiceClassification::TYPE_OTHER) {
            return new CleaningServiceClassificationResult(
                CleaningServiceClassification::TYPE_OTHER,
                CleaningServiceClassification::validateOtherSubtype($explicitSubtype),
                false,
                null,
            );
        }

        if ($this->dailyCleaningAlreadyCompleted($roomId, $serviceDate)) {
            $subtype = $explicitSubtype
                ? CleaningServiceClassification::validateOtherSubtype($explicitSubtype)
                : ($isRerelease
                    ? CleaningServiceClassification::SUBTYPE_RERELEASE
                    : CleaningServiceClassification::SUBTYPE_REQUESTED);

            return new CleaningServiceClassificationResult(
                CleaningServiceClassification::TYPE_OTHER,
                $subtype,
                $explicitType !== CleaningServiceClassification::TYPE_OTHER,
                'daily cleaning already completed',
            );
        }

        if ($explicitType === CleaningServiceClassification::TYPE_DAILY) {
            return new CleaningServiceClassificationResult(
                CleaningServiceClassification::TYPE_DAILY,
                null,
                false,
                null,
            );
        }

        return new CleaningServiceClassificationResult(
            CleaningServiceClassification::TYPE_DAILY,
            null,
            false,
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordCleaning(int $roomId, string $serviceDate, array $context = []): CleaningServiceClassificationResult
    {
        return DB::transaction(function () use ($roomId, $serviceDate, $context) {
            DailyRoomCleaning::query()
                ->where('room_id', $roomId)
                ->whereDate('service_date', $serviceDate)
                ->lockForUpdate()
                ->first();

            $result = $this->classifyRelease($roomId, $serviceDate, $context);

            if ($result->reclassified) {
                Log::info('Daily room cleaning re-classified as other service.', [
                    'room_id' => $roomId,
                    'service_date' => $serviceDate,
                    'service_type' => $result->serviceType,
                    'service_subtype' => $result->serviceSubtype,
                    'reason' => $result->reason,
                    'request' => $context,
                ]);
            }

            return $result;
        });
    }

    public function dailyCleaningAlreadyCompleted(int $roomId, string $serviceDate): bool
    {
        $date = Carbon::parse($serviceDate)->toDateString();

        $cleaning = DailyRoomCleaning::query()
            ->where('room_id', $roomId)
            ->whereDate('service_date', $date)
            ->first(['id', 'daily_cleaning_completed_at', 'status', 'completed_at']);

        if ($cleaning?->daily_cleaning_completed_at) {
            return true;
        }

        if ($cleaning?->status === 'cleaned' && $cleaning->completed_at) {
            return true;
        }

        return RoomCleaningRelease::query()
            ->where('room_id', $roomId)
            ->whereDate('release_date', $date)
            ->where('service_type', CleaningServiceClassification::TYPE_DAILY)
            ->where('status', RoomCleaningRelease::STATUS_READY)
            ->exists();
    }

    public function markDailyCleaningCompleted(DailyRoomCleaning $cleaning, RoomCleaningRelease $release): void
    {
        if ($release->service_type !== CleaningServiceClassification::TYPE_DAILY) {
            return;
        }

        if ($cleaning->daily_cleaning_completed_at) {
            return;
        }

        $cleaning->daily_cleaning_completed_at = $cleaning->completed_at ?? now();
        $cleaning->save();
    }

    public function prepareDailyCleaningForRelease(DailyRoomCleaning $cleaning, CleaningServiceClassificationResult $classification): void
    {
        if ($classification->serviceType !== CleaningServiceClassification::TYPE_OTHER) {
            return;
        }

        $cleaning->status = 'pending_cleaning';
        $cleaning->started_at = null;
        $cleaning->started_by = null;
        $cleaning->completed_at = null;
        $cleaning->completed_by = null;
        $cleaning->checklist_done = null;
        $cleaning->save();
    }
}
