<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomStatusBlock extends Model
{
    protected $fillable = [
        'room_id',
        'status',
        'start_date',
        'end_date',
        'note',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}

