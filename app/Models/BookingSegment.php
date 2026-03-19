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
        'check_in_at',
        'check_out_at',
        'rate_plan_id',
        'adults_count',
        'children_count',
        'extra_beds_count',
        'total_price',
        'status',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
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
