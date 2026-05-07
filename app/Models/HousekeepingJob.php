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
        'remarks',
        'issues_summary',
    ];

    public function block()
    {
        return $this->belongsTo(RoomStatusBlock::class, 'room_status_block_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function lines()
    {
        return $this->hasMany(HousekeepingJobLine::class);
    }
}
