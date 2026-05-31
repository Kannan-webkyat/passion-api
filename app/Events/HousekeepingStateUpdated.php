<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * Reception + housekeeping UIs refresh when room status blocks / daily cleaning change.
 *
 * ShouldBroadcastNow is used so Laravel does not queue BroadcastEvent (which was causing HTTP 500s
 * when the queue/sync broadcast pipeline failed mid-request). Delivery is deferred with
 * App::terminating() so Reverb still cannot delay JSON responses.
 */
class HousekeepingStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int> $roomIds */
    public function __construct(
        public array $roomIds,
        public ?string $reason = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('reception.housekeeping')];
    }

    public function broadcastAs(): string
    {
        return 'housekeeping.state_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_ids' => array_values(array_unique(array_map('intval', $this->roomIds))),
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<int|string|null> $roomIds
     */
    public static function dispatchIfEnabled(array $roomIds, ?string $reason = null): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $ids = [];
        foreach ($roomIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[] = $n;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        App::terminating(function () use ($ids, $reason) {
            try {
                event(new self($ids, $reason));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
