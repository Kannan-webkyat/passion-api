<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['room_number', 'room_type_id', 'status', 'floor', 'notes'];

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
}
