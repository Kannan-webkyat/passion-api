<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiosellBookingLink extends Model
{
    protected $fillable = [
        'channel',
        'booking_id',
        'cm_booking_id',
        'passion_booking_id',
        'booking_group_id',
        'room_index',
        'pah',
        'currency',
        'amount_after_tax',
        'amount_before_tax',
        'tax',
        'commission',
        'tcs',
        'tds',
    ];

    protected $casts = [
        'pah' => 'boolean',
        'amount_after_tax' => 'decimal:2',
        'amount_before_tax' => 'decimal:2',
        'tax' => 'decimal:2',
        'commission' => 'decimal:2',
        'tcs' => 'decimal:2',
        'tds' => 'decimal:2',
    ];

    public function passionBooking()
    {
        return $this->belongsTo(Booking::class, 'passion_booking_id');
    }
}
