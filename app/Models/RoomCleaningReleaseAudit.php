<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomCleaningReleaseAudit extends Model
{
    public $timestamps = false;

    public const ACTION_RELEASED = 'room_released_for_cleaning';

    public const ACTION_WINDOW_MODIFIED = 'window_modified';

    public const ACTION_WINDOW_EXTENDED = 'window_extended';

    public const ACTION_WINDOW_RESCHEDULED = 'window_rescheduled';

    public const ACTION_CANCELLED = 'cancelled';

    public const ACTION_EXPIRED = 'window_expired';

    public const ACTION_CLEANING_STARTED = 'cleaning_started';

    public const ACTION_CLEANING_COMPLETED = 'cleaning_completed';

    public const ACTION_INSPECTION_COMPLETED = 'inspection_completed';

    public const ACTION_ROOM_READY = 'room_ready';

    protected $fillable = [
        'room_cleaning_release_id',
        'action',
        'user_id',
        'remarks',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(RoomCleaningRelease::class, 'room_cleaning_release_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
