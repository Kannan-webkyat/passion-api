<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DailyRoomCleaningDeskNotify implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public string $roomNumber,
        public ?int $bookingId,
        public ?string $guestName,
        public string $serviceDate,
        public string $message,
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
        return 'daily_cleaning.desk_notify';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'room_number' => $this->roomNumber,
            'booking_id' => $this->bookingId,
            'guest_name' => $this->guestName,
            'service_date' => $this->serviceDate,
            'message' => $this->message,
        ];
    }
}
