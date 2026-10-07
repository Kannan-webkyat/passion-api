<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortalNotification extends Model
{
    public const AUDIENCE_FRONT_DESK = 'front_desk';

    public const AUDIENCE_DIRTY_ROOMS = 'dirty_rooms';

    public const AUDIENCE_CHECKOUT_INSPECTION = 'checkout_inspection';

    public const AUDIENCE_DAILY_CLEANING = 'daily_cleaning';

    public const AUDIENCE_LAUNDRY = 'laundry';

    public const KIND_DAILY_CLEANING = 'daily_cleaning.desk_notify';

    public const KIND_DIRTY_ROOM = 'dirty_room.created';

    public const KIND_INSPECTION_REQUESTED = 'checkout_inspection.requested';

    public const KIND_INSPECTION_COMPLETED = 'checkout_inspection.completed';

    public const KIND_ROOM_READY = 'turnover.room_ready';

    public const KIND_DAILY_RELEASED = 'daily_cleaning.released';

    public const KIND_RESERVICE_REQUESTED = 'daily_cleaning.reservice_requested';

    public const KIND_RESERVICE_APPROVED = 'daily_cleaning.reservice_approved';

    public const KIND_DIRTY_ASSIGNED = 'dirty_room.assigned';

    public const KIND_INSPECTION_ASSIGNED = 'checkout_inspection.assigned';

    public const KIND_DAILY_ASSIGNED = 'daily_cleaning.assigned';

    public const KIND_LAUNDRY_REQUESTED = 'laundry.requested';

    public const KIND_LAUNDRY_READY = 'laundry.ready';

    public const KIND_LAUNDRY_POSTED = 'laundry.posted';

    /**
     * @var array<string, array<int, string>>
     */
    public const AUDIENCE_PERMISSIONS = [
        self::AUDIENCE_FRONT_DESK => ['view-rooms', 'reservation', 'reservation-view'],
        self::AUDIENCE_DIRTY_ROOMS => ['housekeeping-dirty-rooms', 'housekeeping-cleaning-tasks'],
        self::AUDIENCE_CHECKOUT_INSPECTION => ['housekeeping-checkout-inspection'],
        self::AUDIENCE_DAILY_CLEANING => ['housekeeping-daily-room-cleaning'],
        self::AUDIENCE_LAUNDRY => ['housekeeping-laundry'],
    ];

    /**
     * Housekeeping boards where unassigned work is for allocators, and an assignment row is only for the assignee.
     *
     * @var array<int, string>
     */
    public const STAFF_SCOPED_AUDIENCES = [
        self::AUDIENCE_DIRTY_ROOMS,
        self::AUDIENCE_CHECKOUT_INSPECTION,
        self::AUDIENCE_DAILY_CLEANING,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    public const ALLOCATION_PERMISSIONS = [
        self::AUDIENCE_DIRTY_ROOMS => ['housekeeping-assignable'],
        self::AUDIENCE_CHECKOUT_INSPECTION => ['housekeeping-checkout-inspection-assign'],
        self::AUDIENCE_DAILY_CLEANING => ['housekeeping-assignable'],
    ];

    /**
     * Sent only to the assigned staff member.
     *
     * @var array<int, string>
     */
    public const ASSIGNMENT_KINDS = [
        self::KIND_DIRTY_ASSIGNED,
        self::KIND_INSPECTION_ASSIGNED,
        self::KIND_DAILY_ASSIGNED,
    ];

    protected $fillable = [
        'audience',
        'recipient_user_id',
        'kind',
        'title',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function reads(): HasMany
    {
        return $this->hasMany(PortalNotificationRead::class);
    }
}
