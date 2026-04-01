<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = [
        'room_number', 
        'room_type_id', 
        'is_active', 
        'status', 
        'floor', 
        'bed_config',
        'amenities',
        'intercom_extension',
        'view_type',
        'is_smoking_allowed',
        'connected_room_id',
        'internal_notes',
        'notes'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_smoking_allowed' => 'boolean',
        'amenities' => 'array',
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

    public function connectedRoom()
    {
        return $this->belongsTo(Room::class, 'connected_room_id');
    }
}
