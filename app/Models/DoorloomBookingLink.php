<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoorloomBookingLink extends Model
{
    protected $fillable = [
        'booking_id',
        'room_type_id',
        'doorloom_booking_id',
        'revision',
        'external_booking_id',
        'idempotency_key',
        'payload_hash',
        'sync_status',
        'last_error',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
