<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\HousekeepingJobLine;

class HousekeepingJob extends Model
{
    protected $fillable = [
        'room_status_block_id',
        'room_id',
        'status',
        'started_by',
        'finished_by',
        'finished_at',
        'approved_by',
        'approved_at',
        'remarks',
        'issues_summary',
    ];

    protected $casts = [
        'finished_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function block()
    {
        return $this->belongsTo(RoomStatusBlock::class, 'room_status_block_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id')->withTrashed();
    }

    public function lines()
    {
        return $this->hasMany(HousekeepingJobLine::class);
    }

    public function startedByUser()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function finishedByUser()
    {
        return $this->belongsTo(User::class, 'finished_by');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
