<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSegment extends Model
{
    protected $fillable = [
        'booking_id',
        'room_id',
        'check_in',
        'check_out',
        'rate_plan_id',
        'adults_count',
        'children_count',
        'extra_beds_count',
        'total_price',
        'status',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }
}
