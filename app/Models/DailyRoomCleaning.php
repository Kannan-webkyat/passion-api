<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyRoomCleaning extends Model
{
    protected $fillable = [
        'room_id',
        'booking_id',
        'service_date',
        'status',
        'started_at',
        'completed_at',
        'daily_cleaning_completed_at',
        'started_by',
        'completed_by',
        'assigned_to',
        'remarks',
        'maintenance_note',
        'checklist_done',
        'front_desk_notified_at',
    ];

    protected $casts = [
        'service_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'daily_cleaning_completed_at' => 'datetime',
        'front_desk_notified_at' => 'datetime',
        'checklist_done' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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

    public function consumptions(): HasMany
    {
        return $this->hasMany(DailyRoomCleaningConsumption::class);
    }
}
