<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PosRestaurantUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $restaurantId,
        public ?int $orderId = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('pos.restaurant.'.$this->restaurantId)];
    }

    public function broadcastAs(): string
    {
        return 'pos.updated';
    }

    /**
     * @return array{restaurant_id: int, order_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'restaurant_id' => $this->restaurantId,
            'order_id' => $this->orderId,
        ];
    }
}
