<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['room_number', 'room_type_id', 'is_active', 'status', 'floor', 'notes'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function segments()
    {
        return $this->hasMany(BookingSegment::class);
    }

    public function statusBlocks()
    {
        return $this->hasMany(RoomStatusBlock::class);
    }
}
