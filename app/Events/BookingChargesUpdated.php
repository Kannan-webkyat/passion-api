<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingChargesUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $bookingId,
        public float $extraCharges,
        public float $addedAmount,
        public ?string $badge = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('reception.booking.' . $this->bookingId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.charges_updated';
    }

    /**
     * @return array{booking_id:int, extra_charges:float, added_amount:float, badge:?string}
     */
    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'extra_charges' => round($this->extraCharges, 2),
            'added_amount' => round($this->addedAmount, 2),
            'badge' => $this->badge,
        ];
    }
}
