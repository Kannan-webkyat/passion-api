<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomCleaningRelease extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INSPECTION_PENDING = 'inspection_pending';

    public const STATUS_READY = 'ready';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const QUEUE_STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_IN_PROGRESS,
        self::STATUS_INSPECTION_PENDING,
    ];

    protected $fillable = [
        'room_id',
        'booking_id',
        'room_status_block_id',
        'daily_room_cleaning_id',
        'release_date',
        'window_start',
        'window_end',
        'status',
        'priority',
        'assigned_to',
        'remarks',
        'started_at',
        'started_by',
        'completed_at',
        'completed_by',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'release_date' => 'date',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function roomStatusBlock(): BelongsTo
    {
        return $this->belongsTo(RoomStatusBlock::class);
    }

    public function dailyRoomCleaning(): BelongsTo
    {
        return $this->belongsTo(DailyRoomCleaning::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(RoomCleaningReleaseAudit::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $array = parent::toArray();

        if ($this->release_date) {
            $array['release_date'] = $this->release_date->toDateString();
        }

        return $array;
    }
}
