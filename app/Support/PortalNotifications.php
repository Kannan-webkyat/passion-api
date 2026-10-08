<?php

namespace App\Support;

use App\Events\PortalNotificationCreated;
use App\Models\PortalNotification;
use App\Models\PortalNotificationRead;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PortalNotifications
{
    public const HREF_DIRTY_ROOMS = '/reception/housekeeping/dirty-rooms';

    public const HREF_CHECKOUT_INSPECTION = '/reception/housekeeping/checkout-inspection';

    public const HREF_DAILY_CLEANING = '/reception/housekeeping/daily-room-cleaning';

    public const HREF_LAUNDRY_REQUESTS = '/reception/housekeeping/laundry/requests';

    public static function enabled(): bool
    {
        return Schema::hasTable('portal_notifications')
            && Schema::hasTable('portal_notification_reads');
    }

    /**
     * @return array<int, string>
     */
    public static function permissionNames(): array
    {
        $names = [];
        foreach (PortalNotification::AUDIENCE_PERMISSIONS as $permissions) {
            foreach ($permissions as $permission) {
                $names[] = $permission;
            }
        }
        foreach (PortalNotification::ALLOCATION_PERMISSIONS as $permissions) {
            foreach ($permissions as $permission) {
                $names[] = $permission;
            }
        }

        return array_values(array_unique($names));
    }

    public static function userCanView(mixed $user): bool
    {
        return $user instanceof User && (
            self::audiencesFor($user) !== [] || self::allocationAudiencesFor($user) !== []
        );
    }

    /**
     * @return array<int, string>
     */
    public static function audiencesFor(User $user): array
    {
        $allowed = [];
        foreach (PortalNotification::AUDIENCE_PERMISSIONS as $audience => $permissions) {
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    $allowed[] = $audience;
                    break;
                }
            }
        }

        return $allowed;
    }

    public static function visibleTo(User $user, PortalNotification $notification): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return self::visibleQuery($user)->whereKey($notification->id)->exists();
    }

    /**
     * @return array<int, string>
     */
    public static function allocationAudiencesFor(User $user): array
    {
        $allowed = [];
        foreach (PortalNotification::ALLOCATION_PERMISSIONS as $audience => $permissions) {
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    $allowed[] = $audience;
                    break;
                }
            }
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        string $audience,
        string $kind,
        string $title,
        string $message,
        array $payload = [],
        ?string $href = null,
        ?int $actorUserId = null,
        ?int $recipientUserId = null,
    ): ?int {
        if (! self::enabled() || ! Schema::hasColumn('portal_notifications', 'audience')) {
            return null;
        }

        try {
            if ($href !== null && $href !== '') {
                $payload['href'] = $href;
            }

            $attributes = [
                'audience' => $audience,
                'kind' => $kind,
                'title' => $title,
                'message' => $message,
                'payload' => $payload,
            ];
            if (
                $recipientUserId !== null
                && $recipientUserId > 0
                && Schema::hasColumn('portal_notifications', 'recipient_user_id')
            ) {
                $attributes['recipient_user_id'] = $recipientUserId;
            }

            $row = PortalNotification::query()->create($attributes);

            if ($actorUserId !== null && $actorUserId > 0) {
                $actor = User::query()->find($actorUserId);
                if ($actor) {
                    self::markRead($actor, $row);
                }
            }

            self::prune();

            DB::afterCommit(function () use ($row, $actorUserId) {
                PortalNotificationCreated::dispatchIfEnabled($row, $actorUserId);
            });

            return (int) $row->id;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function recordDailyCleaning(
        int $roomId,
        string $roomNumber,
        ?int $bookingId,
        ?string $guestName,
        string $serviceDate,
        string $message,
        ?int $actorUserId = null,
    ): ?int {
        $number = trim($roomNumber);
        $title = $number !== '' ? 'Room '.$number.' cleaned' : 'Room '.$roomId.' cleaned';

        return self::record(
            PortalNotification::AUDIENCE_FRONT_DESK,
            PortalNotification::KIND_DAILY_CLEANING,
            $title,
            $message,
            self::payload($roomId, $number, $bookingId, $guestName, $serviceDate),
            null,
            $actorUserId,
        );
    }

    public static function recordDirtyRoom(
        int $roomId,
        ?int $bookingId,
        ?string $guestName,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_DIRTY_ROOMS,
            PortalNotification::KIND_DIRTY_ROOM,
            $label.' needs cleaning',
            $label.' is dirty and waiting for housekeeping.',
            self::payload($roomId, self::roomNumber($roomId), $bookingId, $guestName, null),
            self::HREF_DIRTY_ROOMS,
            $actorUserId,
        );
    }

    public static function recordCheckoutInspectionRequested(
        int $roomId,
        ?int $bookingId,
        ?string $guestName,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_CHECKOUT_INSPECTION,
            PortalNotification::KIND_INSPECTION_REQUESTED,
            $label.' inspection requested',
            'Checkout inspection was requested for '.$label.'.',
            self::payload($roomId, self::roomNumber($roomId), $bookingId, $guestName, null),
            self::HREF_CHECKOUT_INSPECTION,
            $actorUserId,
        );
    }

    public static function recordCheckoutInspectionCompleted(
        int $roomId,
        ?int $bookingId,
        ?string $guestName,
        bool $chargesPosted,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);
        $message = $chargesPosted
            ? 'Checkout inspection for '.$label.' is complete. Charges were added to the folio.'
            : 'Checkout inspection for '.$label.' is complete. No extra charges.';

        return self::record(
            PortalNotification::AUDIENCE_FRONT_DESK,
            PortalNotification::KIND_INSPECTION_COMPLETED,
            $label.' inspection complete',
            $message,
            self::payload($roomId, self::roomNumber($roomId), $bookingId, $guestName, null),
            null,
            $actorUserId,
        );
    }

    public static function recordRoomReady(int $roomId, ?int $actorUserId): ?int
    {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_FRONT_DESK,
            PortalNotification::KIND_ROOM_READY,
            $label.' is ready',
            'Turnover cleaning is finished. '.$label.' is available.',
            self::payload($roomId, self::roomNumber($roomId), null, null, null),
            null,
            $actorUserId,
        );
    }

    public static function recordDailyCleaningReleased(
        int $roomId,
        ?int $bookingId,
        ?string $serviceDate,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_FRONT_DESK,
            PortalNotification::KIND_DAILY_RELEASED,
            $label.' released for cleaning',
            $label.' is released for daily cleaning.',
            self::payload($roomId, self::roomNumber($roomId), $bookingId, null, $serviceDate),
            null,
            $actorUserId,
        );
    }

    public static function recordDirtyRoomAssigned(
        int $roomId,
        ?int $bookingId,
        int $recipientUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::recordTaskAssigned(
            PortalNotification::AUDIENCE_DIRTY_ROOMS,
            PortalNotification::KIND_DIRTY_ASSIGNED,
            $label.' assigned to you',
            'You have been assigned to clean '.$label.'.',
            $roomId,
            $bookingId,
            null,
            $recipientUserId,
            self::HREF_DIRTY_ROOMS,
        );
    }

    public static function recordCheckoutInspectionAssigned(
        int $roomId,
        ?int $bookingId,
        int $recipientUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::recordTaskAssigned(
            PortalNotification::AUDIENCE_CHECKOUT_INSPECTION,
            PortalNotification::KIND_INSPECTION_ASSIGNED,
            $label.' assigned to you',
            'You have been assigned the checkout inspection for '.$label.'.',
            $roomId,
            $bookingId,
            null,
            $recipientUserId,
            self::HREF_CHECKOUT_INSPECTION,
        );
    }

    public static function recordDailyCleaningAssigned(
        int $roomId,
        ?int $bookingId,
        ?string $serviceDate,
        int $recipientUserId,
        bool $reService,
    ): ?int {
        $label = self::roomLabel($roomId);
        $message = $reService
            ? 'You have been assigned re-service for '.$label.'.'
            : 'You have been assigned daily cleaning for '.$label.'.';

        return self::recordTaskAssigned(
            PortalNotification::AUDIENCE_DAILY_CLEANING,
            PortalNotification::KIND_DAILY_ASSIGNED,
            $label.' assigned to you',
            $message,
            $roomId,
            $bookingId,
            $serviceDate,
            $recipientUserId,
            self::HREF_DAILY_CLEANING,
        );
    }

    public static function recordReserviceRequested(
        int $roomId,
        ?int $bookingId,
        ?string $serviceDate,
        ?string $serviceSubtype,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_DAILY_CLEANING,
            PortalNotification::KIND_RESERVICE_REQUESTED,
            'Re-service requested for '.$label,
            CleaningServiceClassification::label(
                CleaningServiceClassification::TYPE_OTHER,
                $serviceSubtype,
            ),
            self::payload($roomId, self::roomNumber($roomId), $bookingId, null, $serviceDate),
            self::HREF_DAILY_CLEANING,
            $actorUserId,
        );
    }

    public static function recordReserviceApproved(
        int $roomId,
        ?int $bookingId,
        ?string $serviceDate,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_FRONT_DESK,
            PortalNotification::KIND_RESERVICE_APPROVED,
            'Re-service approved for '.$label,
            'Supervisor approved the re-service for '.$label.'.',
            self::payload($roomId, self::roomNumber($roomId), $bookingId, null, $serviceDate),
            null,
            $actorUserId,
        );
    }

    public static function recordLaundryRequested(
        int $roomId,
        ?int $bookingId,
        ?string $guestName,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_LAUNDRY,
            PortalNotification::KIND_LAUNDRY_REQUESTED,
            'Laundry requested for '.$label,
            'A laundry pickup was requested for '.$label.'.',
            self::payload($roomId, self::roomNumber($roomId), $bookingId, $guestName, null),
            self::HREF_LAUNDRY_REQUESTS,
            $actorUserId,
        );
    }

    public static function recordLaundryReady(
        int $roomId,
        ?int $bookingId,
        ?string $guestName,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_FRONT_DESK,
            PortalNotification::KIND_LAUNDRY_READY,
            'Laundry ready for '.$label,
            'Laundry for '.$label.' is ready.',
            self::payload($roomId, self::roomNumber($roomId), $bookingId, $guestName, null),
            null,
            $actorUserId,
        );
    }

    public static function recordLaundryPosted(
        int $roomId,
        ?int $bookingId,
        ?string $guestName,
        ?int $actorUserId,
    ): ?int {
        $label = self::roomLabel($roomId);

        return self::record(
            PortalNotification::AUDIENCE_FRONT_DESK,
            PortalNotification::KIND_LAUNDRY_POSTED,
            'Laundry posted for '.$label,
            'Laundry charges for '.$label.' were posted to the folio.',
            self::payload($roomId, self::roomNumber($roomId), $bookingId, $guestName, null),
            null,
            $actorUserId,
        );
    }

    /**
     * @return array{unread_count: int, notifications: array<int, array<string, mixed>>}
     */
    public static function feedFor(User $user): array
    {
        if (! self::enabled()) {
            return [
                'unread_count' => 0,
                'notifications' => [],
            ];
        }

        $rows = self::feedQuery($user)->orderByDesc('id')->limit(40)->get();
        $reads = self::readsFor($user, $rows->pluck('id')->all());

        $notifications = [];
        $unread = 0;
        foreach ($rows as $row) {
            $presented = self::present($row, $reads->get($row->id));
            if ($presented['read_at'] === null) {
                $unread++;
            }
            $notifications[] = $presented;
        }

        return [
            'unread_count' => $unread,
            'notifications' => $notifications,
        ];
    }

    public static function markRead(User $user, PortalNotification $notification): string
    {
        $read = PortalNotificationRead::query()->updateOrCreate(
            [
                'portal_notification_id' => $notification->id,
                'user_id' => $user->id,
            ],
            [
                'read_at' => now(),
            ],
        );

        return $read->read_at?->toIso8601String() ?? now()->toIso8601String();
    }

    public static function markAllRead(User $user): void
    {
        if (! self::enabled()) {
            return;
        }

        $visibleIds = self::visibleQuery($user)->pluck('id');
        if ($visibleIds->isEmpty()) {
            return;
        }

        $readIds = PortalNotificationRead::query()
            ->where('user_id', '=', $user->id, 'and')
            ->whereIn('portal_notification_id', $visibleIds->all())
            ->pluck('portal_notification_id');

        $now = now();
        $rows = [];
        foreach ($visibleIds->diff($readIds) as $id) {
            $rows[] = [
                'portal_notification_id' => (int) $id,
                'user_id' => $user->id,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            PortalNotificationRead::query()->insert($rows);
        }
    }

    public static function unreadCount(User $user): int
    {
        return self::feedFor($user)['unread_count'];
    }

    public static function clearingEnabled(): bool
    {
        return self::enabled() && Schema::hasColumn('portal_notification_reads', 'cleared_at');
    }

    /**
     * Hides one read notification from this user's feed only. Returns false when the user has not read it.
     */
    public static function clear(User $user, PortalNotification $notification): bool
    {
        $read = PortalNotificationRead::query()
            ->where('portal_notification_id', '=', $notification->id, 'and')
            ->where('user_id', '=', $user->id, 'and')
            ->first();
        if (! $read || $read->read_at === null) {
            return false;
        }

        $read->cleared_at = now();
        $read->save();

        return true;
    }

    /**
     * Hides every read notification in this user's feed. Unread ones and other users' feeds are untouched.
     */
    public static function clearRead(User $user): int
    {
        if (! self::clearingEnabled()) {
            return 0;
        }

        $visibleIds = self::feedQuery($user)->pluck('id');
        if ($visibleIds->isEmpty()) {
            return 0;
        }

        $now = now();

        return PortalNotificationRead::query()
            ->where('user_id', '=', $user->id, 'and')
            ->whereIn('portal_notification_id', $visibleIds->all())
            ->whereNotNull('read_at')
            ->whereNull('cleared_at')
            ->update(['cleared_at' => $now, 'updated_at' => $now]);
    }

    private static function recordTaskAssigned(
        string $audience,
        string $kind,
        string $title,
        string $message,
        int $roomId,
        ?int $bookingId,
        ?string $serviceDate,
        int $recipientUserId,
        string $href,
    ): ?int {
        return self::record(
            $audience,
            $kind,
            $title,
            $message,
            self::payload($roomId, self::roomNumber($roomId), $bookingId, null, $serviceDate),
            $href,
            null,
            $recipientUserId,
        );
    }

    /**
     * @return Builder<PortalNotification>
     */
    private static function feedQuery(User $user): Builder
    {
        $query = self::visibleQuery($user);
        if (! self::clearingEnabled()) {
            return $query;
        }

        return $query->whereNotExists(function ($cleared) use ($user) {
            $cleared->selectRaw('1')
                ->from('portal_notification_reads')
                ->whereColumn('portal_notification_reads.portal_notification_id', 'portal_notifications.id')
                ->where('portal_notification_reads.user_id', '=', $user->id)
                ->whereNotNull('portal_notification_reads.cleared_at');
        });
    }

    /**
     * @return Builder<PortalNotification>
     */
    private static function visibleQuery(User $user): Builder
    {
        $query = PortalNotification::query();
        if (! Schema::hasColumn('portal_notifications', 'audience')) {
            return $query;
        }

        $open = array_values(array_diff(self::audiencesFor($user), PortalNotification::STAFF_SCOPED_AUDIENCES));
        $allocation = self::allocationAudiencesFor($user);
        $hasRecipient = Schema::hasColumn('portal_notifications', 'recipient_user_id');

        if ($open === [] && $allocation === [] && ! $hasRecipient) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function ($inner) use ($user, $open, $allocation, $hasRecipient) {
            $applied = false;

            if ($open !== []) {
                $inner->where(function ($openQuery) use ($open) {
                    $openQuery->whereIn('audience', $open);
                    if (in_array(PortalNotification::AUDIENCE_FRONT_DESK, $open, true)) {
                        $openQuery->orWhereNull('audience');
                    }
                });
                $applied = true;
            }

            if ($allocation !== []) {
                $allocationScope = function ($allocationQuery) use ($allocation) {
                    $allocationQuery
                        ->whereIn('audience', $allocation)
                        ->whereNotIn('kind', PortalNotification::ASSIGNMENT_KINDS);
                };
                if ($applied) {
                    $inner->orWhere($allocationScope);
                } else {
                    $inner->where($allocationScope);
                }
                $applied = true;
            }

            if ($hasRecipient) {
                $recipient = function ($recipientQuery) use ($user) {
                    $recipientQuery
                        ->where('recipient_user_id', '=', $user->id, 'and')
                        ->whereIn('audience', PortalNotification::STAFF_SCOPED_AUDIENCES);
                };
                if ($applied) {
                    $inner->orWhere($recipient);
                } else {
                    $inner->where($recipient);
                }
                $applied = true;
            }

            if (! $applied) {
                $inner->whereRaw('0 = 1');
            }
        });
    }

    /**
     * @param  array<int, int>  $notificationIds
     */
    private static function readsFor(User $user, array $notificationIds)
    {
        if ($notificationIds === []) {
            return collect();
        }

        return PortalNotificationRead::query()
            ->where('user_id', '=', $user->id, 'and')
            ->whereIn('portal_notification_id', $notificationIds)
            ->get()
            ->keyBy('portal_notification_id');
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(PortalNotification $row, ?PortalNotificationRead $read): array
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $bookingId = $payload['booking_id'] ?? null;
        $roomId = $payload['room_id'] ?? null;
        $href = isset($payload['href']) ? trim((string) $payload['href']) : '';

        return [
            'id' => (int) $row->id,
            'audience' => (string) ($row->audience ?: PortalNotification::AUDIENCE_FRONT_DESK),
            'kind' => (string) $row->kind,
            'title' => (string) $row->title,
            'message' => (string) $row->message,
            'href' => $href !== '' ? $href : null,
            'room_id' => $roomId !== null ? (int) $roomId : null,
            'room_number' => isset($payload['room_number']) && $payload['room_number'] !== null
                ? (string) $payload['room_number']
                : null,
            'booking_id' => $bookingId !== null ? (int) $bookingId : null,
            'guest_name' => isset($payload['guest_name']) && $payload['guest_name'] !== null
                ? (string) $payload['guest_name']
                : null,
            'service_date' => isset($payload['service_date']) && $payload['service_date'] !== null
                ? (string) $payload['service_date']
                : null,
            'read_at' => $read?->read_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(
        int $roomId,
        ?string $roomNumber,
        ?int $bookingId,
        ?string $guestName,
        ?string $serviceDate,
    ): array {
        $guest = $guestName !== null ? trim($guestName) : '';
        $number = $roomNumber !== null ? trim($roomNumber) : '';

        return [
            'room_id' => $roomId,
            'room_number' => $number !== '' ? $number : null,
            'booking_id' => $bookingId,
            'guest_name' => $guest !== '' ? $guest : null,
            'service_date' => $serviceDate,
        ];
    }

    private static function roomNumber(int $roomId): ?string
    {
        if (! Schema::hasTable('rooms')) {
            return null;
        }

        $number = Room::query()->where('id', '=', $roomId, 'and')->value('room_number');

        return $number !== null ? (string) $number : null;
    }

    private static function roomLabel(int $roomId): string
    {
        $number = self::roomNumber($roomId);

        return $number !== null && trim($number) !== '' ? 'Room '.$number : 'Room '.$roomId;
    }

    private static function prune(): void
    {
        $cutoffId = PortalNotification::query()->orderByDesc('id')->skip(200)->value('id');
        if ($cutoffId) {
            PortalNotification::query()->where('id', '<=', $cutoffId)->delete();
        }
    }
}
