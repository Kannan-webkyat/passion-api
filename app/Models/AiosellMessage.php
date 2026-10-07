<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiosellMessage extends Model
{
    protected $fillable = [
        'message_id',
        'conversation_id',
        'hotel_id',
        'channel',
        'booking_id',
        'passion_booking_id',
        'sender_type',
        'content',
        'time_sent',
        'guest_name',
        'guest_phone',
        'guest_email',
    ];

    public function passionBooking()
    {
        return $this->belongsTo(Booking::class, 'passion_booking_id');
    }
}
