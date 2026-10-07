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
        ];
    }

    public static function dispatchIfEnabled(PortalNotification $notification): void
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

        App::terminating(function () use ($id, $audience, $kind, $title, $message, $recipientUserId) {
            try {
                event(new self($id, $audience, $kind, $title, $message, $recipientUserId));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
