<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Models\PortalNotification;
use App\Support\PortalNotifications;
use Illuminate\Support\Facades\Auth;

class PortalNotificationController extends Controller
{
    use AuthorizesSpatiePermissions;

    public function index()
    {
        $this->allowView();

        return response()->json(PortalNotifications::feedFor(Auth::user()));
    }

    public function markRead(PortalNotification $notification)
    {
        $this->allowView();

        if (! PortalNotifications::visibleTo(Auth::user(), $notification)) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $readAt = PortalNotifications::markRead(Auth::user(), $notification);

        return response()->json([
            'message' => 'Notification marked read.',
            'id' => (int) $notification->id,
            'read_at' => $readAt,
            'unread_count' => PortalNotifications::unreadCount(Auth::user()),
        ]);
    }

    public function markAllRead()
    {
        $this->allowView();

        PortalNotifications::markAllRead(Auth::user());

        return response()->json([
            'message' => 'Notifications marked read.',
            'unread_count' => 0,
        ]);
    }

    public function clear(PortalNotification $notification)
    {
        $this->allowView();

        if (! PortalNotifications::visibleTo(Auth::user(), $notification)) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }
        if (! PortalNotifications::clearingEnabled()) {
            return response()->json(['message' => 'Clearing notifications is not available yet. Run the latest migrations.'], 422);
        }

        if (! PortalNotifications::clear(Auth::user(), $notification)) {
            return response()->json(['message' => 'Only read notifications can be cleared.'], 422);
        }

        return response()->json([
            'message' => 'Notification cleared.',
            'id' => (int) $notification->id,
            'unread_count' => PortalNotifications::unreadCount(Auth::user()),
        ]);
    }

    public function clearRead()
    {
        $this->allowView();

        if (! PortalNotifications::clearingEnabled()) {
            return response()->json(['message' => 'Clearing notifications is not available yet. Run the latest migrations.'], 422);
        }

        $cleared = PortalNotifications::clearRead(Auth::user());

        return response()->json([
            'message' => 'Read notifications cleared.',
            'cleared' => $cleared,
            'unread_count' => PortalNotifications::unreadCount(Auth::user()),
        ]);
    }

    private function allowView(): void
    {
        $this->authorizePermissions(PortalNotifications::permissionNames());
    }
}
