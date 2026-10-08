<?php

namespace App\Events;

use App\Models\PortalNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class PortalNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $notificationId,
        public string $audience,
        public string $kind,
        public string $title,
        public string $message,
        public ?int $recipientUserId = null,
        public ?int $actorUserId = null,
        public ?string $href = null,
        public ?int $bookingId = null,
        public ?int $roomId = null,
        public ?string $serviceDate = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('portal.notifications')];
    }

    public function broadcastAs(): string
    {
        return 'portal.notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'audience' => $this->audience,
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'recipient_user_id' => $this->recipientUserId,
            'actor_user_id' => $this->actorUserId,
            'href' => $this->href,
            'booking_id' => $this->bookingId,
            'room_id' => $this->roomId,
            'service_date' => $this->serviceDate,
        ];
    }

    public static function dispatchIfEnabled(PortalNotification $notification, ?int $actorUserId = null): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $id = (int) $notification->id;
        $audience = (string) ($notification->audience ?: PortalNotification::AUDIENCE_FRONT_DESK);
        $kind = (string) $notification->kind;
        $title = (string) $notification->title;
        $message = (string) $notification->message;
        $recipientUserId = $notification->recipient_user_id !== null
            ? (int) $notification->recipient_user_id
            : null;
        $payload = is_array($notification->payload) ? $notification->payload : [];
        $href = isset($payload['href']) && trim((string) $payload['href']) !== '' ? trim((string) $payload['href']) : null;
        $bookingId = isset($payload['booking_id']) ? (int) $payload['booking_id'] : null;
        $roomId = isset($payload['room_id']) ? (int) $payload['room_id'] : null;
        $serviceDate = isset($payload['service_date']) ? (string) $payload['service_date'] : null;
        $actorUserId = $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null;

        App::terminating(function () use ($id, $audience, $kind, $title, $message, $recipientUserId, $actorUserId, $href, $bookingId, $roomId, $serviceDate) {
            try {
                event(new self($id, $audience, $kind, $title, $message, $recipientUserId, $actorUserId, $href, $bookingId, $roomId, $serviceDate));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
