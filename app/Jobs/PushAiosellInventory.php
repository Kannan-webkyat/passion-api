<?php

namespace App\Jobs;

use App\Support\AiosellClient;
use App\Support\AiosellInventorySync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Pushes one room type's free-room counts to AioSell from the queue worker.
 * Counts are read when the job runs, so one waiting job covers every save made before it starts.
 */
class PushAiosellInventory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300];

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(public int $roomTypeId) {}

    public function uniqueId(): string
    {
        return (string) $this->roomTypeId;
    }

    public function handle(): void
    {
        if (! AiosellClient::ready()) {
            return;
        }
        $result = AiosellInventorySync::pushInventoryForRoomTypes([$this->roomTypeId]);
        if (! $result['ok']) {
            throw new RuntimeException($result['message'] ?: 'AioSell inventory push failed.');
        }
        AiosellInventorySync::clearErrorWhenQueueIsClear();
    }
}
